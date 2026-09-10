<?php
declare(strict_types=1);

namespace Hitrov;

use Hitrov\Exception\ApiCallException;
use Hitrov\Exception\CurlException;
use Hitrov\Interfaces\CacheInterface;
use Hitrov\Exception\TooManyRequestsWaiterException;
use Hitrov\Interfaces\TooManyRequestsWaiterInterface;
use Hitrov\OCI\Signer;
use JsonException;

class OciApi
{
    /**
     * @var array
     */
    private $existingInstances;

    private CacheInterface $cache;
    private TooManyRequestsWaiterInterface $waiter;

    /**
     * Create OCI instance.
     */
    public function createInstance(
        OciConfig $config,
        string $shape,
        string $sshKey,
        string $availabilityDomain
    ): array
    {
        if (
            isset($this->waiter)
            &&
            $this->waiter->isConfigured()
        ) {

            if (
                $this->waiter->isTooEarly()
            ) {

                throw new TooManyRequestsWaiterException(
                    "Will retry after "
                    .
                    $this->waiter->secondsRemaining()
                    .
                    " seconds"
                );
            }

            $this->waiter->remove();
        }

        $displayName =
            'instance-'
            .
            date('Ymd-Hi');

        $body = <<<EOD
{
    "metadata": {
        "ssh_authorized_keys": "$sshKey"
    },
    "shape": "$shape",
    "compartmentId": "{$config->tenancyId}",
    "displayName": "$displayName",
    "availabilityDomain": "$availabilityDomain",
    "sourceDetails": {$config->getSourceDetails()},
    "createVnicDetails": {
        "assignPublicIp": false,
        "subnetId": "{$config->subnetId}",
        "assignPrivateDnsRecord": true
    },
    "agentConfig": {
        "pluginsConfig": [
            {
                "name": "Compute Instance Monitoring",
                "desiredState": "ENABLED"
            }
        ],
        "isMonitoringDisabled": false,
        "isManagementDisabled": false
    },
    "definedTags": {},
    "freeformTags": {},
    "instanceOptions": {
        "areLegacyImdsEndpointsDisabled": false
    },
    "availabilityConfig": {
        "recoveryAction": "RESTORE_INSTANCE"
    },
    "shapeConfig": {
        "ocpus": {$config->ocpus},
        "memoryInGBs": {$config->memoryInGBs}
    }
}
EOD;

        $baseUrl =
            "{$this->getBaseApiUrl($config)}"
            .
            "/instances/";

        try {

            return $this->call(
                $config,
                $baseUrl,
                'POST',
                $body
            );

        } catch (
            ApiCallException $e
        ) {

            $message =
                $e->getMessage();

            if (
                $e->getCode() != 429
                &&
                strpos(
                    $message,
                    'TooManyRequests'
                ) === false
            ) {

                throw $e;
            }

            if (
                !isset($this->waiter)
                ||
                !$this->waiter->isConfigured()
            ) {

                throw $e;
            }

            $this->waiter->enable();

            throw new TooManyRequestsWaiterException(
                $message
            );
        }
    }

    /**
     * Resize VM.Standard.A1.Flex.
     *
     * Example:
     *
     * 1 OCPU / 6 GB
     *       ↓
     * 2 OCPU / 12 GB
     */
    public function updateInstanceShape(
        OciConfig $config,
        string $instanceId,
        string $shape,
        int $ocpus,
        int $memoryInGBs
    ): array
    {
        $bodyArray = [
            'shape' => $shape,
            'shapeConfig' => [
                'ocpus' => $ocpus,
                'memoryInGBs' => $memoryInGBs,
            ],
        ];

        $body =
            json_encode(
                $bodyArray,
                JSON_UNESCAPED_SLASHES
            );

        if ($body === false) {

            throw new JsonException(
                'Unable to encode resize request body.'
            );
        }

        $baseUrl =
            "{$this->getBaseApiUrl($config)}"
            .
            "/instances/{$instanceId}";

        echo "\n";
        echo "OCI UpdateInstance request\n";
        echo "Instance ID: {$instanceId}\n";
        echo "Shape      : {$shape}\n";
        echo "OCPU       : {$ocpus}\n";
        echo "Memory     : {$memoryInGBs} GB\n";

        try {

            return $this->call(
                $config,
                $baseUrl,
                'PUT',
                $body
            );

        } catch (
            ApiCallException $e
        ) {

            throw $e;
        }
    }

    /**
     * Get all instances.
     */
    public function getInstances(
        OciConfig $config
    ): array
    {
        $baseUrl =
            "{$this->getBaseApiUrl($config)}"
            .
            "/instances/";

        $params = [
            'compartmentId' =>
                $config->tenancyId
        ];

        return $this->call(
            $config,
            $baseUrl,
            'GET',
            null,
            $params
        );
    }

    /**
     * Original Hitrov compatibility method.
     */
    public function checkExistingInstances(
        OciConfig $config,
        array $listResponse,
        string $shape,
        int $maxRunningInstancesOfThatShape
    ): string
    {
        $this->existingInstances =
            array_filter(
                $listResponse,
                function ($instance) use (
                    $shape
                ) {

                    $acceptableStates = [
                        'TERMINATED'
                    ];

                    return
                        !in_array(
                            $instance[
                                'lifecycleState'
                            ],
                            $acceptableStates
                        )
                        &&
                        $instance[
                            'shape'
                        ] === $shape;
                }
            );

        if (
            count(
                $this->existingInstances
            )
            <
            $maxRunningInstancesOfThatShape
        ) {

            return '';
        }

        $displayNames =
            array_map(
                function ($instance) {
                    return
                        $instance[
                            'displayName'
                        ];
                },
                $this->existingInstances
            );

        $displayNamesString =
            implode(
                ', ',
                $displayNames
            );

        $lifecycleStates =
            array_map(
                function ($instance) {
                    return
                        $instance[
                            'lifecycleState'
                        ];
                },
                $this->existingInstances
            );

        $lifecycleStatesString =
            implode(
                ', ',
                $lifecycleStates
            );

        return
            "Already have an instance(s) "
            .
            "[$displayNamesString] "
            .
            "in state(s) "
            .
            "(respectively) "
            .
            "[$lifecycleStatesString]. "
            .
            "User: "
            .
            $config->ociUserId
            .
            "\n";
    }

    /**
     * Get Availability Domains.
     */
    public function getAvailabilityDomains(
        OciConfig $config
    ): array
    {
        $data = null;

        if (
            getenv(
                'CACHE_AVAILABILITY_DOMAINS'
            )
            &&
            isset($this->cache)
        ) {

            $data =
                $this->cache->get(
                    'getAvailabilityDomains'
                );
        }

        if (!$data) {

            $baseUrl =
                "{$this->getBaseApiUrl("
                .
                $config
                .
                ", 'identity')}"
                .
                "/availabilityDomains/";

            $params = [
                'compartmentId' =>
                    $config->tenancyId
            ];

            $data =
                $this->call(
                    $config,
                    $baseUrl,
                    'GET',
                    null,
                    $params
                );

            if (
                getenv(
                    'CACHE_AVAILABILITY_DOMAINS'
                )
                &&
                isset($this->cache)
            ) {

                $this->cache->add(
                    $data,
                    'getAvailabilityDomains'
                );
            }
        }

        return $data;
    }

    /**
     * Existing instances.
     */
    public function getExistingInstances(): array
    {
        return $this->existingInstances;
    }

    /**
     * Set cache.
     */
    public function setCache(
        CacheInterface $cache
    ): void
    {
        $this->cache = $cache;
    }

    /**
     * OCI HTTP call.
     */
    public function call(
        OciConfig $config,
        string $baseUrl = '',
        string $method = 'GET',
        string $body = null,
        array $params = []
    )
    {
        $paramsString = '';

        if ($params) {

            $paramsString =
                '?'
                .
                http_build_query(
                    $params
                );
        }

        $url =
            "$baseUrl$paramsString";

        $signer =
            new Signer(
                $config->tenancyId,
                $config->ociUserId,
                $config->keyFingerPrint,
                $config->privateKeyFilename
            );

        $headers =
            $signer->getHeaders(
                $url,
                $method,
                $body,
                'application/json'
            );

        $curlOptions = [
            CURLOPT_URL =>
                $url,

            CURLOPT_RETURNTRANSFER =>
                true,

            CURLOPT_MAXREDIRS =>
                1,

            CURLOPT_TIMEOUT =>
                10,

            CURLOPT_FOLLOWLOCATION =>
                true,

            CURLOPT_HTTP_VERSION =>
                CURL_HTTP_VERSION_1_1,

            CURLOPT_CUSTOMREQUEST =>
                $method,

            CURLOPT_HTTPHEADER =>
                $headers,
        ];

        if ($body) {

            $curlOptions[
                CURLOPT_POSTFIELDS
            ] = $body;
        }

        return
            HttpClient::getResponse(
                $curlOptions
            );
    }

    /**
     * Set 429 waiter.
     */
    public function setWaiter(
        TooManyRequestsWaiterInterface $waiter
    ): void
    {
        $this->waiter = $waiter;
    }

    /**
     * OCI API URL.
     */
    private function getBaseApiUrl(
        OciConfig $config,
        string $api = 'iaas'
    ): string
    {
        return
            "https://$api."
            .
            "{$config->region}"
            .
            ".oraclecloud.com/20160918";
    }
}
