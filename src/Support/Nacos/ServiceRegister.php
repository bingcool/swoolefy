<?php

declare(strict_types=1);

namespace Swoolefy\Support\Nacos;

use Swoolefy\Library\Nacos\Client;
use Swoolefy\Library\Nacos\Provider\Instance\Model\ListResponse;
use Swoolefy\Library\Nacos\Provider\Instance\Model\RsInfo;
use Swoolefy\Exception\NacosMonitorException;
use Swoolefy\Util\Log;

/**
 * Nacos 服务注册：实例注册、心跳保活与查询。
 */
final class ServiceRegister
{
    private const HEARTBEAT_INTERVAL_SEC = 10;

    private ?Client $client = null;

    private ?int $heartbeatTimer = null;

    private string $registeredIp = '';

    private int $registeredPort = 0;

    private string $registeredServiceName = '';

    private string $registeredNamespaceId = '';

    private string $registeredGroupName = '';

    private bool $registeredEphemeral = true;

    private readonly Log $logger;

    public function __construct(
        private readonly NacosConfig                $nacosConfig,
        private readonly NacosServiceRegisterConfig $serviceRegisterConfig,
    ) {
        $this->logger = NacosLogger::get();
    }

    public static function create(): self
    {
        return new self(
            NacosConfig::load(),
            NacosServiceRegisterConfig::load(),
        );
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
        $ip = $ip ?? $this->serviceRegisterConfig->ip;
        $port = $port ?? $this->serviceRegisterConfig->port;
        $serviceName = $serviceName ?? $this->serviceRegisterConfig->serviceName;
        $namespaceId = $this->normalizeNamespaceId($namespaceId ?? $this->serviceRegisterConfig->namespaceId);
        $groupName = $groupName ?? $this->serviceRegisterConfig->groupName;
        $ephemeral = $ephemeral ?? $this->serviceRegisterConfig->ephemeral;

        if ('' === $ip || $port <= 0 || '' === $serviceName) {
            throw NacosMonitorException::throw('service ip, port and service_name are required for register');
        }

        var_dump($this->nacosConfig->toClientConfigArray());

        $this->client = $this->nacosConfig->createClient();
        $ok = $this->client->instance->register(
            $ip,
            $port,
            $serviceName,
            $namespaceId,
            $weight ?? $this->serviceRegisterConfig->weight,
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

        $this->logger->info(sprintf(
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
     * 使用进程级 Swoole Timer 定时上报 Nacos 实例心跳（自定义进程 run() 返回后仍可持续）。
     *
     * @param int $intervalSeconds 心跳间隔（秒），默认 10
     */
    public function startHeartbeat(int $intervalSeconds = self::HEARTBEAT_INTERVAL_SEC): void
    {
        if ('' === $this->registeredIp || $this->registeredPort <= 0 || '' === $this->registeredServiceName) {
            throw NacosMonitorException::throw('register instance before starting heartbeat');
        }

        if (null === $this->client) {
            $this->client = $this->nacosConfig->createClient();
        }

        $this->stopHeartbeat();
        $this->sendHeartbeat();

        if ($intervalSeconds <= 3) {
            $intervalSeconds = 5;
        }

        // Nacos 临时实例默认约 15s 无心跳即下线，间隔不宜过大
        if ($intervalSeconds > 8) {
            $intervalSeconds = 5;
        }

        $intervalMs = $intervalSeconds * 1000;
        $this->heartbeatTimer = \Swoole\Timer::tick($intervalMs, function () {
            $this->sendHeartbeat();
        });

        $this->logger->info(sprintf(
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

        if (\Swoole\Timer::exists($this->heartbeatTimer)) {
            \Swoole\Timer::clear($this->heartbeatTimer);
        }
        $this->heartbeatTimer = null;
        $this->logger->info('nacos heartbeat timer stopped');
    }

    /**
     * 注销服务实例
     * @return void
     */
    public function deregister(): void
    {
        if ('' === $this->registeredIp || $this->registeredPort <= 0 || '' === $this->registeredServiceName) {
            return;
        }

        $this->stopHeartbeat();

        if (null === $this->client) {
            $this->client = $this->nacosConfig->createClient();
        }

        try {
            $ok = $this->client->instance->deregister(
                $this->registeredIp,
                $this->registeredPort,
                $this->registeredServiceName,
                $this->registeredNamespaceId,
                '',
                $this->registeredGroupName,
                $this->registeredEphemeral,
            );

            if ($ok) {
                fmtPrintNote('Server Stop!!!, Nacos service deregistered');
                $this->logger->info(sprintf(
                    'nacos instance deregistered: %s:%d -> %s',
                    $this->registeredIp,
                    $this->registeredPort,
                    $this->registeredServiceName,
                ));
            } else {
                $this->logger->error(sprintf(
                    'nacos instance deregister failed: %s:%d -> %s',
                    $this->registeredIp,
                    $this->registeredPort,
                    $this->registeredServiceName,
                ));
            }
        } catch (\Throwable $e) {
            $this->logger->error('nacos deregister failed: ' . $e->getMessage());
        }
    }

    /**
     * 发送心跳
     */
    private function normalizeNamespaceId(string $namespaceId): string
    {
        return 'public' === strtolower(trim($namespaceId)) ? '' : $namespaceId;
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
            $beat->setWeight($this->serviceRegisterConfig->weight);
            $beat->setEphemeral($this->registeredEphemeral);
            var_dump('sendHeartbeat'.date('Y-m-d H:i:s'));
            $this->client->instance->beat(
                $this->registeredServiceName,
                $beat,
                $this->registeredGroupName,
                $this->registeredNamespaceId,
                $this->registeredEphemeral,
            );

        } catch (\Throwable $e) {
            $this->logger->error('nacos heartbeat failed: ' . $e->getMessage());
        }
    }

    public function list(
        ?string $serviceName = null,
        ?string $groupName = null,
        ?string $namespaceId = null,
        string $clusters = '',
        bool $healthyOnly = true,
    ): ListResponse {
        $serviceName = $serviceName ?? $this->serviceRegisterConfig->serviceName;
        if ('' === $serviceName) {
            throw NacosMonitorException::throw('service_name is required for list');
        }

        $client = $this->client ?? $this->nacosConfig->createClient();

        return $client->instance->list(
            $serviceName,
            $groupName ?? $this->serviceRegisterConfig->groupName,
            $namespaceId ?? $this->serviceRegisterConfig->namespaceId,
            $clusters,
            $healthyOnly,
        );
    }
}
