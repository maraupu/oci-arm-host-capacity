<?php
declare(strict_types=1);

$pathPrefix = '';

require "{$pathPrefix}vendor/autoload.php";

use Dotenv\Dotenv;
use Hitrov\Exception\ApiCallException;
use Hitrov\OciApi;

$envFilename = empty($argv[1]) ? '.env' : $argv[1];

$dotenv = Dotenv::createUnsafeImmutable(__DIR__, $envFilename);
$dotenv->safeLoad();

/*
|--------------------------------------------------------------------------
| CONFIGURATION
|--------------------------------------------------------------------------
*/

$shape = getenv('OCI_SHAPE') ?: 'VM.Standard.A1.Flex';

/*
 * Hunting target
 */
$huntOcpus = (int) (getenv('OCI_OCPUS') ?: 1);
$huntMemory = (int) (getenv('OCI_MEMORY_IN_GBS') ?: 6);

/*
 * Resize target
 */
$resizeOcpus = (int) (getenv('OCI_RESIZE_OCPUS') ?: 2);
$resizeMemory = (int) (getenv('OCI_RESIZE_MEMORY_IN_GBS') ?: 12);

/*
 * Retry
 */
$maxAttempts = max(
    1,
    (int) (getenv('OCI_MAX_ATTEMPTS') ?: 2)
);

$retryDelay = max(
    0,
    (int) (getenv('OCI_RETRY_DELAY') ?: 45)
);

$maxInstances = max(
    1,
    (int) (getenv('OCI_MAX_INSTANCES') ?: 1)
);

echo "========================================\n";
echo " OCI ARM HOST CAPACITY BOT\n";
echo "========================================\n";
echo "Shape          : {$shape}\n";
echo "Hunting        : {$huntOcpus} OCPU / {$huntMemory} GB\n";
echo "Resize target  : {$resizeOcpus} OCPU / {$resizeMemory} GB\n";
echo "Max attempts   : {$maxAttempts}\n";
echo "Retry delay    : {$retryDelay} seconds\n";
echo "Max instances  : {$maxInstances}\n";
echo "========================================\n\n";

/*
|--------------------------------------------------------------------------
| TELEGRAM
|--------------------------------------------------------------------------
*/

function sendTelegram(string $message): void
{
    $token = getenv('TELEGRAM_TOKEN');
    $chatId = getenv('TELEGRAM_CHAT_ID');

    if (!$token || !$chatId) {
        echo "Telegram credentials tidak tersedia.\n";
        return;
    }

    $url =
        "https://api.telegram.org/bot"
        . $token
        . "/sendMessage";

    $postData = [
        'chat_id' => $chatId,
        'text' => $message,
        'parse_mode' => 'Markdown',
    ];

    $curl = curl_init($url);

    curl_setopt_array($curl, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $postData,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 15,
        CURLOPT_CONNECTTIMEOUT => 10,
    ]);

    $response = curl_exec($curl);

    if ($response === false) {
        echo "Telegram error: ";
        echo curl_error($curl);
        echo "\n";
    } else {
        echo "Telegram notification terkirim.\n";
    }

    curl_close($curl);
}

/*
|--------------------------------------------------------------------------
| ERROR CHECKERS
|--------------------------------------------------------------------------
*/

function isOutOfHostCapacity(ApiCallException $e): bool
{
    $message = $e->getMessage();

    return
        $e->getCode() === 500 &&
        (
            strpos(
                $message,
                'Out of host capacity'
            ) !== false
            ||
            strpos(
                $message,
                'Out of host'
            ) !== false
        );
}

function isTooManyRequests(ApiCallException $e): bool
{
    $message = $e->getMessage();

    return
        $e->getCode() === 429 ||
        strpos(
            $message,
            'TooManyRequests'
        ) !== false ||
        strpos(
            $message,
            'Too Many Requests'
        ) !== false;
}

/*
|--------------------------------------------------------------------------
| INSTANCE CONFIG
|--------------------------------------------------------------------------
*/

function getInstanceOcpus(array $instance): float
{
    return (float) (
        $instance['shapeConfig']['ocpus']
        ?? 0
    );
}

function getInstanceMemory(array $instance): float
{
    return (float) (
        $instance['shapeConfig']['memoryInGBs']
        ?? 0
    );
}

/*
|--------------------------------------------------------------------------
| FIND ACTIVE A1 INSTANCES
|--------------------------------------------------------------------------
*/

function findActiveA1Instances(
    array $instances,
    string $shape
): array {

    $terminatedStates = [
        'TERMINATED'
    ];

    return array_values(
        array_filter(
            $instances,
            function (array $instance) use (
                $shape,
                $terminatedStates
            ): bool {

                if (
                    ($instance['shape'] ?? '')
                    !==
                    $shape
                ) {
                    return false;
                }

                $state =
                    $instance['lifecycleState']
                    ?? '';

                return !in_array(
                    $state,
                    $terminatedStates,
                    true
                );
            }
        )
    );
}

/*
|--------------------------------------------------------------------------
| OCI CONFIG
|--------------------------------------------------------------------------
*/

$config = new \Hitrov\OciConfig(
    getenv('OCI_REGION'),
    getenv('OCI_USER_ID'),
    getenv('OCI_TENANCY_ID'),
    getenv('OCI_KEY_FINGERPRINT'),
    getenv('OCI_PRIVATE_KEY_FILENAME'),
    getenv('OCI_AVAILABILITY_DOMAIN') ?: null,
    getenv('OCI_SUBNET_ID'),
    getenv('OCI_IMAGE_ID'),
    $huntOcpus,
    $huntMemory
);

$bootVolumeSize =
    (string) getenv(
        'OCI_BOOT_VOLUME_SIZE_IN_GBS'
    );

$bootVolumeId =
    (string) getenv(
        'OCI_BOOT_VOLUME_ID'
    );

if ($bootVolumeSize !== '') {

    $config->setBootVolumeSizeInGBs(
        $bootVolumeSize
    );

} elseif ($bootVolumeId !== '') {

    $config->setBootVolumeId(
        $bootVolumeId
    );
}

$api = new OciApi();

/*
|--------------------------------------------------------------------------
| GET EXISTING INSTANCES
|--------------------------------------------------------------------------
*/

echo "[1] Mengecek instance A1...\n";

try {

    $instances =
        $api->getInstances($config);

} catch (Throwable $e) {

    echo "Gagal mengambil daftar instance:\n";
    echo $e->getMessage() . "\n";

    exit(1);
}

$existingA1 =
    findActiveA1Instances(
        $instances,
        $shape
    );

echo "Instance A1 aktif: ";
echo count($existingA1);
echo "\n\n";

/*
|--------------------------------------------------------------------------
| EXISTING INSTANCE
|--------------------------------------------------------------------------
*/

if (count($existingA1) > 0) {

    if (
        count($existingA1)
        >
        $maxInstances
    ) {

        echo "========================================\n";
        echo "PERINGATAN: TERLALU BANYAK INSTANCE A1\n";
        echo "========================================\n";
        echo "Ditemukan: ";
        echo count($existingA1);
        echo "\n";

        exit(1);
    }

    $instance =
        $existingA1[0];

    $instanceId =
        $instance['id'] ?? '';

    $displayName =
        $instance['displayName']
        ?? '(tanpa nama)';

    $lifecycleState =
        $instance['lifecycleState']
        ?? 'UNKNOWN';

    $currentOcpus =
        getInstanceOcpus($instance);

    $currentMemory =
        getInstanceMemory($instance);

    echo "Instance ditemukan:\n";
    echo "  Name   : {$displayName}\n";
    echo "  OCID   : {$instanceId}\n";
    echo "  State  : {$lifecycleState}\n";
    echo "  OCPU   : {$currentOcpus}\n";
    echo "  Memory : {$currentMemory} GB\n\n";

    /*
    |--------------------------------------------------------------------------
    | SUDAH 2/12
    |--------------------------------------------------------------------------
    */

    if (
        $currentOcpus >= $resizeOcpus
        &&
        $currentMemory >= $resizeMemory
    ) {

        echo "========================================\n";
        echo "TARGET 2/12 SUDAH TERCAPAI\n";
        echo "========================================\n";

        echo "OCPU   : {$currentOcpus}\n";
        echo "Memory : {$currentMemory} GB\n";

        exit(0);
    }

    /*
    |--------------------------------------------------------------------------
    | HARUS 1/6
    |--------------------------------------------------------------------------
    */

    if (
        $currentOcpus != $huntOcpus
        ||
        $currentMemory != $huntMemory
    ) {

        echo "Instance A1 ditemukan tetapi\n";
        echo "konfigurasinya tidak sesuai target bot.\n";

        echo "Ditemukan:\n";
        echo "{$currentOcpus} OCPU / ";
        echo "{$currentMemory} GB\n";

        echo "\nBot berhenti demi keamanan.\n";

        exit(1);
    }

    /*
    |--------------------------------------------------------------------------
    | WAIT UNTIL INSTANCE READY
    |--------------------------------------------------------------------------
    */

    if (
        !in_array(
            $lifecycleState,
            [
                'RUNNING',
                'STOPPED'
            ],
            true
        )
    ) {

        echo "========================================\n";
        echo "INSTANCE BELUM SIAP RESIZE\n";
        echo "========================================\n";
        echo "Lifecycle: {$lifecycleState}\n";
        echo "\n";
        echo "Tidak melakukan resize sekarang.\n";
        echo "Cron berikutnya akan mencoba lagi.\n";

        exit(1);
    }

    /*
    |--------------------------------------------------------------------------
    | RESIZE 1/6 -> 2/12
    |--------------------------------------------------------------------------
    */

    echo "========================================\n";
    echo "RESIZE 1/6 -> 2/12\n";
    echo "========================================\n";

    for (
        $attempt = 1;
        $attempt <= $maxAttempts;
        $attempt++
    ) {

        echo "\n";
        echo "Resize attempt ";
        echo "{$attempt}/{$maxAttempts}\n";

        try {

            $result =
                $api->updateInstanceShape(
                    $config,
                    $instanceId,
                    $shape,
                    $resizeOcpus,
                    $resizeMemory
                );

            echo "\nResize request diterima OCI.\n";

            echo json_encode(
                $result,
                JSON_PRETTY_PRINT
            ) . "\n";

            /*
            |--------------------------------------------------------------------------
            | VERIFY
            |--------------------------------------------------------------------------
            |
            | PUT berhasil belum berarti instance sudah
            | selesai resize. Ambil ulang instance.
            |
            */

            echo "\nMemverifikasi konfigurasi instance...\n";

            sleep(5);

            $verifyInstances =
                $api->getInstances($config);

            $verifiedInstance = null;

            foreach (
                $verifyInstances
                as $verify
            ) {

                if (
                    ($verify['id'] ?? '')
                    ===
                    $instanceId
                ) {

                    $verifiedInstance =
                        $verify;

                    break;
                }
            }

            if ($verifiedInstance === null) {

                echo "Instance tidak ditemukan saat verifikasi.\n";
                exit(1);
            }

            $verifiedOcpus =
                getInstanceOcpus(
                    $verifiedInstance
                );

            $verifiedMemory =
                getInstanceMemory(
                    $verifiedInstance
                );

            $verifiedState =
                $verifiedInstance[
                    'lifecycleState'
                ]
                ?? 'UNKNOWN';

            echo "\nHasil verifikasi:\n";
            echo "  State  : {$verifiedState}\n";
            echo "  OCPU   : {$verifiedOcpus}\n";
            echo "  Memory : {$verifiedMemory} GB\n";

            /*
            |--------------------------------------------------------------------------
            | RESIZE CONFIRMED
            |--------------------------------------------------------------------------
            */

            if (
                $verifiedOcpus >= $resizeOcpus
                &&
                $verifiedMemory >= $resizeMemory
            ) {

                echo "\n";
                echo "========================================\n";
                echo "RESIZE 2/12 TERVERIFIKASI\n";
                echo "========================================\n";

                echo "Telegram 2/12 akan dikirim oleh workflow.\n";

                exit(0);
            }

            /*
            |--------------------------------------------------------------------------
            | RESIZE REQUESTED BUT NOT FINISHED
            |--------------------------------------------------------------------------
            */

            echo "\nResize belum selesai.\n";
            echo "Konfigurasi masih:\n";
            echo "{$verifiedOcpus} OCPU / ";
            echo "{$verifiedMemory} GB\n";

            echo "\nCron berikutnya akan mengecek lagi.\n";

            exit(1);

        } catch (ApiCallException $e) {

            $message =
                $e->getMessage();

            echo "\nResize gagal:\n";
            echo $message . "\n";

            if (
                isOutOfHostCapacity($e)
                ||
                isTooManyRequests($e)
            ) {

                if (
                    $attempt
                    <
                    $maxAttempts
                ) {

                    echo "\nMenunggu ";
                    echo "{$retryDelay} detik...\n";

                    sleep($retryDelay);

                    continue;
                }

                echo "\n";
                echo "========================================\n";
                echo "RESIZE BELUM BERHASIL\n";
                echo "========================================\n";

                echo "Instance 1/6 tetap dipertahankan.\n";
                echo "Cron berikutnya akan mencoba lagi.\n";

                exit(1);
            }

            echo "\nError bukan capacity/429.\n";
            echo "Bot dihentikan demi keamanan.\n";

            exit(1);
        }
    }

    exit(1);
}

/*
|--------------------------------------------------------------------------
| BELUM ADA INSTANCE
| HUNT 1/6
|--------------------------------------------------------------------------
*/

echo "========================================\n";
echo "BELUM ADA INSTANCE A1\n";
echo "HUNTING 1/6\n";
echo "========================================\n";

/*
|--------------------------------------------------------------------------
| GET AVAILABILITY DOMAINS
|--------------------------------------------------------------------------
*/

if (!empty($config->availabilityDomains)) {

    if (
        is_array(
            $config->availabilityDomains
        )
    ) {

        $availabilityDomains =
            $config->availabilityDomains;

    } else {

        $availabilityDomains = [
            $config->availabilityDomains
        ];
    }

} else {

    try {

        $availabilityDomains =
            $api->getAvailabilityDomains(
                $config
            );

    } catch (Throwable $e) {

        echo "Gagal mendapatkan AD:\n";
        echo $e->getMessage() . "\n";

        exit(1);
    }
}

/*
|--------------------------------------------------------------------------
| HUNTING
|--------------------------------------------------------------------------
*/

foreach (
    $availabilityDomains
    as $availabilityDomainEntity
) {

    $availabilityDomain =
        is_array(
            $availabilityDomainEntity
        )
        ?
        (
            $availabilityDomainEntity['name']
            ?? ''
        )
        :
        $availabilityDomainEntity;

    echo "\n";
    echo "Availability Domain:\n";
    echo "{$availabilityDomain}\n";

    for (
        $attempt = 1;
        $attempt <= $maxAttempts;
        $attempt++
    ) {

        echo "\n";
        echo "Create attempt ";
        echo "{$attempt}/{$maxAttempts}\n";

        echo "Target: ";
        echo "{$huntOcpus} OCPU / ";
        echo "{$huntMemory} GB\n";

        try {

            $instanceDetails =
                $api->createInstance(
                    $config,
                    $shape,
                    getenv(
                        'OCI_SSH_PUBLIC_KEY'
                    ),
                    $availabilityDomain
                );

            $newInstanceId =
                $instanceDetails['id']
                ?? '';

            $newState =
                $instanceDetails[
                    'lifecycleState'
                ]
                ?? 'UNKNOWN';

            $newDisplayName =
                $instanceDetails[
                    'displayName'
                ]
                ?? 'instance';

            echo "\n";
            echo "========================================\n";
            echo "🟢 INSTANCE 1/6 BERHASIL DIBUAT\n";
            echo "========================================\n";

            echo "Name : {$newDisplayName}\n";
            echo "OCID : {$newInstanceId}\n";
            echo "State: {$newState}\n";
            echo "OCPU : {$huntOcpus}\n";
            echo "RAM  : {$huntMemory} GB\n";

            /*
            |--------------------------------------------------------------------------
            | TELEGRAM 1/6
            |--------------------------------------------------------------------------
            */

            $telegramMessage =
                "🟢 *OCI ARM 1/6 BERHASIL!*\n\n"
                .
                "Instance berhasil dibuat.\n\n"
                .
                "• Shape: `VM.Standard.A1.Flex`\n"
                .
                "• OCPU: `1`\n"
                .
                "• RAM: `6 GB`\n"
                .
                "• Boot Volume: `150 GB`\n\n"
                .
                "⏳ Instance akan dipertahankan.\n"
                .
                "Bot akan mencoba resize otomatis "
                .
                "ke *2 OCPU / 12 GB* pada run berikutnya.";

            sendTelegram(
                $telegramMessage
            );

            echo "\n";
            echo "Instance 1/6 sudah aman.\n";
            echo "Run berikutnya akan melakukan resize.\n";

            /*
             * PENTING:
             *
             * exit(1) supaya workflow TIDAK mengirim
             * notifikasi 2/12 dan TIDAK disable workflow.
             *
             * Cron berikutnya akan menjalankan proses resize.
             */

            exit(1);

        } catch (ApiCallException $e) {

            $message =
                $e->getMessage();

            echo "\nCreate gagal:\n";
            echo $message . "\n";

            /*
            |--------------------------------------------------------------------------
            | OUT OF HOST CAPACITY
            |--------------------------------------------------------------------------
            */

            if (
                isOutOfHostCapacity($e)
            ) {

                if (
                    $attempt
                    <
                    $maxAttempts
                ) {

                    echo "\n";
                    echo "Out of host capacity.\n";
                    echo "Menunggu ";
                    echo "{$retryDelay} detik...\n";

                    sleep($retryDelay);

                    continue;
                }

                echo "\n";
                echo "========================================\n";
                echo "HUNTING BELUM BERHASIL\n";
                echo "========================================\n";

                echo "2 attempt sudah digunakan.\n";
                echo "Cron berikutnya akan mencoba lagi.\n";

                exit(1);
            }

            /*
            |--------------------------------------------------------------------------
            | 429
            |--------------------------------------------------------------------------
            */

            if (
                isTooManyRequests($e)
            ) {

                if (
                    $attempt
                    <
                    $maxAttempts
                ) {

                    echo "\n";
                    echo "OCI API rate limit 429.\n";
                    echo "Menunggu ";
                    echo "{$retryDelay} detik...\n";

                    sleep($retryDelay);

                    continue;
                }

                echo "\n";
                echo "2 attempt sudah digunakan.\n";
                echo "Cron berikutnya akan mencoba lagi.\n";

                exit(1);
            }

            /*
            |--------------------------------------------------------------------------
            | OTHER ERROR
            |--------------------------------------------------------------------------
            */

            echo "\n";
            echo "ERROR OCI bukan capacity/429.\n";
            echo "Bot dihentikan.\n";

            exit(1);
        }
    }
}

/*
|--------------------------------------------------------------------------
| ALL AD FAILED
|--------------------------------------------------------------------------
*/

echo "\n";
echo "========================================\n";
echo "HUNTING GAGAL\n";
echo "========================================\n";

exit(1);
