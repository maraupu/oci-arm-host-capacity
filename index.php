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
 * ========================================
 * OCI CONFIGURATION
 * ========================================
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
 * ========================================
 * BOOT VOLUME
 * ========================================
 */
$bootVolumeSizeInGBs = (string) getenv('OCI_BOOT_VOLUME_SIZE_IN_GBS');
$bootVolumeId = (string) getenv('OCI_BOOT_VOLUME_ID');

if ($bootVolumeSizeInGBs) {
    $config->setBootVolumeSizeInGBs($bootVolumeSizeInGBs);
} elseif ($bootVolumeId) {
    $config->setBootVolumeId($bootVolumeId);
}

/*
 * ========================================
 * OCI API
 * ========================================
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
 * ========================================
 * GENERAL CONFIGURATION
 * ========================================
 */
$shape = getenv('OCI_SHAPE') ?: 'VM.Standard.A1.Flex';

$maxRunningInstancesOfThatShape = 1;

if (getenv('OCI_MAX_INSTANCES') !== false) {
    $maxRunningInstancesOfThatShape =
        max(1, (int) getenv('OCI_MAX_INSTANCES'));
}

/*
 * ========================================
 * RETRY CONFIGURATION
 *
 * Maksimal 2 request setiap trigger.
 * Jeda request kedua = 45 detik.
 * ========================================
 */
$maxAttempts = 2;
$retryDelay = 45;

/*
 * ========================================
 * HEADER
 * ========================================
 */
echo "========================================\n";
echo " OCI ARM HOST CAPACITY BOT\n";
echo "========================================\n";
echo "Region : " . getenv('OCI_REGION') . "\n";
echo "Shape  : {$shape}\n";
echo "OCPU   : " . getenv('OCI_OCPUS') . "\n";
echo "RAM    : " . getenv('OCI_MEMORY_IN_GBS') . " GB\n";
echo "Retry  : {$maxAttempts} requests\n";
echo "Delay  : {$retryDelay} seconds\n";
echo "========================================\n";

/*
 * ========================================
 * CHECK EXISTING INSTANCE
 * ========================================
 */
try {

    echo "\n";
    echo "Memeriksa instance yang sudah ada...\n";

    $instances = $api->getInstances($config);

    $existingInstances = $api->checkExistingInstances(
        $config,
        $instances,
        $shape,
        $maxRunningInstancesOfThatShape
    );

    if ($existingInstances) {

        echo "\n";
        echo "========================================\n";
        echo "INSTANCE SUDAH ADA\n";
        echo "========================================\n";

        echo $existingInstances . "\n";

        echo "Bot dihentikan.\n";

        /*
         * Exit 0 supaya workflow dianggap sukses.
         */
        exit(0);
    }

    echo "Belum ada instance {$shape}.\n";

} catch (\Throwable $e) {

    echo "\n";
    echo "========================================\n";
    echo "ERROR SAAT CEK INSTANCE\n";
    echo "========================================\n";

    echo $e->getMessage() . "\n";

    exit(1);
}

/*
 * ========================================
 * GET AVAILABILITY DOMAIN
 * ========================================
 */
try {

    echo "\n";
    echo "Mendapatkan Availability Domain...\n";

    if (!empty($config->availabilityDomains)) {

        if (is_array($config->availabilityDomains)) {

            $availabilityDomains =
                $config->availabilityDomains;

        } else {

            $availabilityDomains = [
                $config->availabilityDomains
            ];
        }

    } else {

        $availabilityDomains =
            $api->getAvailabilityDomains($config);
    }

} catch (\Throwable $e) {

    echo "\n";
    echo "========================================\n";
    echo "ERROR AVAILABILITY DOMAIN\n";
    echo "========================================\n";

    echo $e->getMessage() . "\n";

    exit(1);
}

/*
 * ========================================
 * NORMALIZE AVAILABILITY DOMAIN
 * ========================================
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
 * ========================================
 * CREATE INSTANCE
 * ========================================
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
         * ========================================
         * SUCCESS
         * ========================================
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

        echo "\n";
        echo "Workflow akan dianggap SUKSES.\n";
        echo "Telegram akan dikirim oleh GitHub Actions.\n";

        exit(0);

    } catch (ApiCallException $e) {

        $message = $e->getMessage();
        $code = $e->getCode();

        echo "\n";
        echo "OCI ERROR {$code}:\n";
        echo $message . "\n";

        /*
         * ========================================
         * OUT OF HOST CAPACITY
         * ========================================
         */
        $outOfCapacity =
            stripos($message, 'Out of host capacity') !== false ||
            stripos($message, 'out of capacity') !== false;

        if ($outOfCapacity) {

            echo "\n";
            echo "⚠️ Out of host capacity.\n";

            /*
             * Masih boleh melakukan request kedua.
             */
            if ($attempt < $maxAttempts) {

                echo "\n";
                echo "Request berikutnya akan dilakukan dalam ";
                echo "{$retryDelay} detik...\n";

                /*
                 * Countdown sederhana tanpa cls.
                 */
                for ($remaining = $retryDelay; $remaining > 0; $remaining--) {

                    echo "\rMenunggu: {$remaining} detik   ";
                    sleep(1);
                }

                echo "\rMenunggu selesai.                \n";

                continue;
            }

            /*
             * Semua attempt sudah gagal.
             */
            echo "\n";
            echo "========================================\n";
            echo "2 REQUEST GAGAL\n";
            echo "========================================\n";

            echo "Menunggu cron-job.org berikutnya.\n";

            exit(1);
        }

        /*
         * ========================================
         * TOO MANY REQUESTS - 429
         * ========================================
         */
        if (
            $code === 429 ||
            stripos($message, 'TooManyRequests') !== false ||
            stripos($message, 'Too Many Requests') !== false
        ) {

            echo "\n";
            echo "========================================\n";
            echo "⚠️ OCI RATE LIMIT (429)\n";
            echo "========================================\n";

            echo "OCI menolak request karena terlalu banyak request.\n";
            echo "Tidak melakukan retry tambahan pada trigger ini.\n";
            echo "Cron-job.org akan mencoba kembali pada interval berikutnya.\n";

            exit(1);
        }

        /*
         * ========================================
         * OTHER ERROR
         * ========================================
         *
         * Jangan retry error konfigurasi,
         * authorization, image, subnet, dll.
         */
        echo "\n";
        echo "========================================\n";
        echo "❌ ERROR BUKAN CAPACITY\n";
        echo "========================================\n";

        echo "Bot dihentikan untuk mencegah retry yang tidak perlu.\n";

        exit(1);
    }
}

/*
 * ========================================
 * SAFETY EXIT
 * ========================================
 */
exit(1);
