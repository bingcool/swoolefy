<?php

declare(strict_types=1);

namespace Swoolefy\Support\Nacos;

use Swoolefy\Library\Nacos\Client;
use Swoolefy\Library\Nacos\Provider\Instance\Model\ListResponse;
use Swoolefy\Library\Nacos\Provider\Instance\Model\RsInfo;
use Swoolefy\Core\Coroutine\Timer as GoTimer;
use Swoolefy\Exception\NacosMonitorException;
use Swoolefy\Util\Log;

/**
 * Nacos 服务注册：实例注册、心跳保活与查询。
 */
final class ServiceRegister
{
    private const HEARTBEAT_INTERVAL_SEC = 10;

    private ?Client $client = null;

    /** @var \Swoole\Coroutine\Channel|int|null */
    private $heartbeatTimer = null;

    private string $registeredIp = '';

    private int $registeredPort = 0;

    private string $registeredServiceName = '';

    private string $registeredNamespaceId = '';

    private string $registeredGroupName = '';

    private bool $registeredEphemeral = true;

    public function __construct(
        private readonly NacosConfig $config,
        private readonly ?Log $logger = null,
    ) {
    }

    public function register(
        ?string $ip = null,
        ?int $port = null,
        ?string $serviceName = null,
        ?string $namespaceId = null,
        ?float $weight = null,
        ?string $groupName = null,
        ?bool $ephemeral = null,
        bool $startHeartbeat = true,
        int $heartbeatInterval = 10,
    ): void {
        $ip = $ip ?? $this->config->serviceIp;
        $port = $port ?? $this->config->servicePort;
        $serviceName = $serviceName ?? $this->config->serviceName;
        $namespaceId = $namespaceId ?? $this->config->serviceNamespaceId;
        $groupName = $groupName ?? $this->config->serviceGroupName;
        $ephemeral = $ephemeral ?? $this->config->serviceEphemeral;

        if ('' === $ip || $port <= 0 || '' === $serviceName) {
            throw NacosMonitorException::throw('service ip, port and service_name are required for register');
        }

        $this->client = $this->config->createClient($this->logger);
        $ok = $this->client->instance->register(
            $ip,
            $port,
            $serviceName,
            $namespaceId,
            $weight ?? $this->config->serviceWeight,
            true,
            true,
            '',
            '',
            $groupName,
            $ephemeral,
        );

        if (!$ok) {
            throw NacosMonitorException::throw(sprintf(
                'Nacos instance register failed: %s:%d -> %s',
                $ip,
                $port,
                $serviceName,
            ));
        }

        $this->registeredIp = $ip;
        $this->registeredPort = $port;
        $this->registeredServiceName = $serviceName;
        $this->registeredNamespaceId = $namespaceId;
        $this->registeredGroupName = $groupName;
        $this->registeredEphemeral = $ephemeral;

        $this->logger?->info(sprintf(
            'nacos instance registered: %s:%d -> %s',
            $ip,
            $port,
            $serviceName,
        ));

        if ($startHeartbeat && $ephemeral) {
            $this->startHeartbeat($heartbeatInterval);
        }
    }

    /**
     * 使用 goTick 定时上报 Nacos 实例心跳。
     *
     * @param int $intervalSeconds 心跳间隔（秒），默认 10
     */
    public function startHeartbeat(int $intervalSeconds = self::HEARTBEAT_INTERVAL_SEC): void
    {
        if ('' === $this->registeredIp || $this->registeredPort <= 0 || '' === $this->registeredServiceName) {
            throw NacosMonitorException::throw('register instance before starting heartbeat');
        }

        if (null === $this->client) {
            $this->client = $this->config->createClient($this->logger);
        }

        $this->stopHeartbeat();
        $this->sendHeartbeat();

        if ($intervalSeconds <= 3) {
            $intervalSeconds = 5;
        }

        $intervalMs = $intervalSeconds * 1000;
        $this->heartbeatTimer = goTick($intervalMs, function () {
            $this->sendHeartbeat();
        }, true);

        $this->logger?->info(sprintf(
            'nacos heartbeat timer started, interval=%ds, service=%s',
            $intervalSeconds,
            $this->registeredServiceName,
        ));
    }

    public function stopHeartbeat(): void
    {
        if (null === $this->heartbeatTimer) {
            return;
        }

        GoTimer::cancel($this->heartbeatTimer);
        $this->heartbeatTimer = null;
        $this->logger?->info('nacos heartbeat timer stopped');
    }

    private function sendHeartbeat(): void
    {
        if (null === $this->client) {
            return;
        }

        try {
            $beat = new RsInfo();
            $beat->setIp($this->registeredIp);
            $beat->setPort($this->registeredPort);
            $beat->setServiceName($this->registeredServiceName);
            $beat->setWeight($this->config->serviceWeight);
            $beat->setEphemeral($this->registeredEphemeral);

            $this->client->instance->beat(
                $this->registeredServiceName,
                $beat,
                $this->registeredGroupName,
                $this->registeredNamespaceId,
                $this->registeredEphemeral,
            );
        } catch (\Throwable $e) {
            $this->logger?->error('nacos heartbeat failed: ' . $e->getMessage());
        }
    }

    public function list(
        ?string $serviceName = null,
        ?string $groupName = null,
        ?string $namespaceId = null,
        string $clusters = '',
        bool $healthyOnly = true,
    ): ListResponse {
        $serviceName = $serviceName ?? $this->config->serviceName;
        if ('' === $serviceName) {
            throw NacosMonitorException::throw('service_name is required for list');
        }

        $client = $this->client ?? $this->config->createClient($this->logger);

        return $client->instance->list(
            $serviceName,
            $groupName ?? $this->config->serviceGroupName,
            $namespaceId ?? $this->config->serviceNamespaceId,
            $clusters,
            $healthyOnly,
        );
    }
}
