<?php
declare(strict_types=1);

$pathPrefix = '';

require "{$pathPrefix}vendor/autoload.php";

use Hitrov\Exception\ApiCallException;
use Hitrov\FileCache;
use Hitrov\OciApi;
use Hitrov\OciConfig;
use Hitrov\TooManyRequestsWaiter;

$envFilename = empty($argv[1]) ? '.env' : $argv[1];

$dotenv = \Dotenv\Dotenv::createUnsafeImmutable(__DIR__, $envFilename);
$dotenv->safeLoad();

/*
 * OCI CONFIG
 */
$config = new OciConfig(
    getenv('OCI_REGION'),
    getenv('OCI_USER_ID'),
    getenv('OCI_TENANCY_ID'),
    getenv('OCI_KEY_FINGERPRINT'),
    getenv('OCI_PRIVATE_KEY_FILENAME'),
    getenv('OCI_AVAILABILITY_DOMAIN') ?: null,
    getenv('OCI_SUBNET_ID'),
    getenv('OCI_IMAGE_ID'),
    (int) getenv('OCI_OCPUS'),
    (int) getenv('OCI_MEMORY_IN_GBS')
);

/*
 * BOOT VOLUME
 */
$bootVolumeSizeInGBs = (string) getenv('OCI_BOOT_VOLUME_SIZE_IN_GBS');
$bootVolumeId = (string) getenv('OCI_BOOT_VOLUME_ID');

if ($bootVolumeSizeInGBs) {
    $config->setBootVolumeSizeInGBs($bootVolumeSizeInGBs);
} elseif ($bootVolumeId) {
    $config->setBootVolumeId($bootVolumeId);
}

/*
 * OCI API
 */
$api = new OciApi();

if (getenv('CACHE_AVAILABILITY_DOMAINS')) {
    $api->setCache(new FileCache($config));
}

if (getenv('TOO_MANY_REQUESTS_TIME_WAIT')) {
    $api->setWaiter(
        new TooManyRequestsWaiter(
            (int) getenv('TOO_MANY_REQUESTS_TIME_WAIT')
        )
    );
}

/*
 * CONFIGURATION
 */
$shape = getenv('OCI_SHAPE') ?: 'VM.Standard.A1.Flex';

$maxRunningInstancesOfThatShape = 1;

if (getenv('OCI_MAX_INSTANCES') !== false) {
    $maxRunningInstancesOfThatShape =
        max(1, (int) getenv('OCI_MAX_INSTANCES'));
}

/*
 * RETRY CONFIGURATION
 *
 * 3 request setiap trigger.
 */
$maxAttempts = 3;
$minDelay = 20;
$maxDelay = 25;

/*
 * HEADER
 */
echo "========================================\n";
echo " OCI ARM HOST CAPACITY BOT\n";
echo "========================================\n";
echo "Region : " . getenv('OCI_REGION') . "\n";
echo "Shape  : {$shape}\n";
echo "OCPU   : " . getenv('OCI_OCPUS') . "\n";
echo "RAM    : " . getenv('OCI_MEMORY_IN_GBS') . " GB\n";
echo "Retry  : {$maxAttempts} requests\n";
echo "========================================\n";

/*
 * CHECK EXISTING INSTANCE
 */
try {

    $instances = $api->getInstances($config);

    $existingInstances = $api->checkExistingInstances(
        $config,
        $instances,
        $shape,
        $maxRunningInstancesOfThatShape
    );

    if ($existingInstances) {

        echo "\n";
        echo "Instance sudah tersedia.\n";
        echo $existingInstances . "\n";
        echo "Bot dihentikan.\n";

        exit(0);
    }

} catch (\Throwable $e) {

    echo "\n";
    echo "Gagal mengecek instance:\n";
    echo $e->getMessage() . "\n";

    exit(1);
}

/*
 * GET AVAILABILITY DOMAIN
 */
try {

    if (!empty($config->availabilityDomains)) {

        if (is_array($config->availabilityDomains)) {
            $availabilityDomains = $config->availabilityDomains;
        } else {
            $availabilityDomains = [
                $config->availabilityDomains
            ];
        }

    } else {

        $availabilityDomains = $api->getAvailabilityDomains($config);
    }

} catch (\Throwable $e) {

    echo "\n";
    echo "Gagal mendapatkan Availability Domain:\n";
    echo $e->getMessage() . "\n";

    exit(1);
}

/*
 * Singapore ap-singapore-1
 * gunakan Availability Domain pertama.
 */
$availabilityDomain = null;

foreach ($availabilityDomains as $domain) {

    if (is_array($domain)) {
        $domain = $domain['name'] ?? null;
    }

    if ($domain) {
        $availabilityDomain = $domain;
        break;
    }
}

if (!$availabilityDomain) {

    echo "\n";
    echo "Availability Domain tidak ditemukan.\n";

    exit(1);
}

echo "\n";
echo "Availability Domain:\n";
echo "{$availabilityDomain}\n";

/*
 * 3 CREATE ATTEMPTS
 */
for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {

    echo "\n";
    echo "========================================\n";
    echo "ATTEMPT {$attempt}/{$maxAttempts}\n";
    echo "========================================\n";

    echo "Mencoba membuat {$shape}...\n";

    try {

        $instanceDetails = $api->createInstance(
            $config,
            $shape,
            getenv('OCI_SSH_PUBLIC_KEY'),
            $availabilityDomain
        );

        /*
         * SUCCESS
         */
        echo "\n";
        echo "========================================\n";
        echo "🎉 INSTANCE BERHASIL DIBUAT!\n";
        echo "========================================\n";

        $message = json_encode(
            $instanceDetails,
            JSON_PRETTY_PRINT
        );

        echo $message . "\n";

        /*
         * Telegram dikirim oleh GitHub Actions.
         */
        exit(0);

    } catch (ApiCallException $e) {

        $message = $e->getMessage();
        $code = $e->getCode();

        echo "\n";
        echo "OCI ERROR {$code}:\n";
        echo $message . "\n";

        /*
         * OUT OF HOST CAPACITY
         */
        $outOfCapacity =
            stripos($message, 'Out of host capacity') !== false ||
            stripos($message, 'out of capacity') !== false;

        if ($outOfCapacity) {

            echo "\n";
            echo "⚠️ Out of host capacity.\n";

            if ($attempt < $maxAttempts) {

                $wait = random_int($minDelay, $maxDelay);

                echo "Menunggu {$wait} detik sebelum request berikutnya...\n";

                sleep($wait);

                continue;
            }

            echo "3 attempt sudah gagal.\n";
            echo "Menunggu cron-job.org berikutnya.\n";

            exit(1);
        }

        /*
         * TOO MANY REQUESTS
         */
        if (
            $code === 429 ||
            stripos($message, 'TooManyRequests') !== false ||
            stripos($message, 'Too Many Requests') !== false
        ) {

            echo "\n";
            echo "⚠️ TooManyRequests (429).\n";
            echo "Menghentikan attempt untuk trigger ini.\n";

            exit(1);
        }

        /*
         * ERROR LAIN
         */
        echo "\n";
        echo "❌ Error bukan Out of Capacity.\n";
        echo "Bot dihentikan.\n";

        exit(1);
    }
}

exit(1);
