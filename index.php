<?php
declare(strict_types=1);

// useful when script is being executed by cron user
$pathPrefix = ''; // e.g. /usr/share/nginx/oci-arm-host-capacity/

require "{$pathPrefix}vendor/autoload.php";

use Dotenv\Dotenv;
use Hitrov\Exception\ApiCallException;
use Hitrov\FileCache;
use Hitrov\OciApi;
use Hitrov\OciConfig;
use Hitrov\TooManyRequestsWaiter;

$envFilename = empty($argv[1]) ? '.env' : $argv[1];
$dotenv = Dotenv::createUnsafeImmutable(__DIR__, $envFilename);
$dotenv->safeLoad();

/*
 * Configuration setup from .env / Environment Variables
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

$bootVolumeSizeInGBs = (string) getenv('OCI_BOOT_VOLUME_SIZE_IN_GBS');
$bootVolumeId = (string) getenv('OCI_BOOT_VOLUME_ID');
if ($bootVolumeSizeInGBs) {
    $config->setBootVolumeSizeInGBs($bootVolumeSizeInGBs);
} elseif ($bootVolumeId) {
    $config->setBootVolumeId($bootVolumeId);
}

$api = new OciApi();
if (getenv('CACHE_AVAILABILITY_DOMAINS')) {
    $api->setCache(new FileCache($config));
}
if (getenv('TOO_MANY_REQUESTS_TIME_WAIT')) {
    $api->setWaiter(new TooManyRequestsWaiter((int) getenv('TOO_MANY_REQUESTS_TIME_WAIT')));
}

$notifier = (function (): \Hitrov\Interfaces\NotifierInterface {
    return new \Hitrov\Notification\Telegram();
})();

$shape = getenv('OCI_SHAPE');

$maxRunningInstancesOfThatShape = 1;
if (getenv('OCI_MAX_INSTANCES') !== false) {
    $maxRunningInstancesOfThatShape = (int) getenv('OCI_MAX_INSTANCES');
}

// Cek apakah instance sudah pernah dibuat sebelumnya
$instances = $api->getInstances($config);
$existingInstances = $api->checkExistingInstances($config, $instances, $shape, $maxRunningInstancesOfThatShape);
if ($existingInstances) {
    echo "$existingInstances\n";
    // Instance sudah ada, keluar dengan exit 0 (sukses) agar tidak mencoba buat lagi
    exit(0); 
}

if (!empty($config->availabilityDomains)) {
    if (is_array($config->availabilityDomains)) {
        $availabilityDomains = $config->availabilityDomains;
    } else {
        $availabilityDomains = [ $config->availabilityDomains ];
    }
} else {
    $availabilityDomains = $api->getAvailabilityDomains($config);
}

// Mulai perulangan pencobaan pembuatan instance di Availability Domain
foreach ($availabilityDomains as $availabilityDomainEntity) {
    $availabilityDomain = is_array($availabilityDomainEntity) ? $availabilityDomainEntity['name'] : $availabilityDomainEntity;
    
    try {
        $instanceDetails = $api->createInstance($config, $shape, getenv('OCI_SSH_PUBLIC_KEY'), $availabilityDomain);
    } catch(ApiCallException $e) {
        $message = $e->getMessage();
        echo "$message\n";

        // Jika error karena Out of host capacity, tunggu sejenak lalu coba AD berikutnya
        if (
            $e->getCode() === 500 &&
            strpos($message, 'InternalError') !== false &&
            strpos($message, 'Out of host capacity') !== false
        ) {
            sleep(16);
            continue;
        }

        // Jika error lain atau konfigurasi bermasalah, keluar dengan exit status 1 (Failed)
        exit(1);
    }

    // --- BERHASIL MEMBUAT INSTANCE ---
    $message = json_encode($instanceDetails, JSON_PRETTY_PRINT);
    echo "$message\n";
    
    if ($notifier->isSupported()) {
        $notifier->notify($message);
    }

    // Keluar dengan status 0 (Sukses) untuk memicu notifikasi Telegram di GitHub Actions
    exit(0);
}

// Jika semua Availability Domain sudah dicoba dan tetap gagal (out of capacity)
exit(1);
