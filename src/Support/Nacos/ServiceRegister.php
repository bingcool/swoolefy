<?php

declare(strict_types=1);

namespace Swoolefy\Support\Nacos;

use Swoolefy\Library\Nacos\Client;
use Swoolefy\Library\Nacos\Provider\Instance\Model\BeatResponse;
use Swoolefy\Library\Nacos\Provider\Instance\Model\ListResponse;
use Swoolefy\Library\Nacos\Provider\Instance\Model\RsInfo;
use Swoolefy\Exception\NacosMonitorException;
use Swoolefy\Util\Log;

/**
 * Nacos 服务注册：实例注册、心跳保活与查询。
 *
 * 心跳策略：首次发送重型 beat → 解析响应中的 lightBeatEnabled/clientBeatInterval
 * → 启用轻量续约后不再携带 beat 参数，避免实例被周期性标记不健康。
 */
final class ServiceRegister
{
    private const HEARTBEAT_INTERVAL_SEC = 5;

    /** Nacos 默认 15s 无心跳标记不健康，间隔上限留足余量 */
    private const MAX_HEARTBEAT_INTERVAL_MS = 8000;

    private ?Client $client = null;

    private ?int $heartbeatTimer = null;

    /** 服务端首次心跳返回 lightBeatEnabled=true 后，后续只发轻量心跳（不带 beat 参数） */
    private bool $lightBeatEnabled = false;

    /** 由心跳响应 clientBeatInterval 驱动，默认 5000ms */
    private int $clientBeatIntervalMs = 5000;

    private string $registeredIp = '';

    private int $registeredPort = 0;

    private string $registeredServiceName = '';

    private string $registeredNamespaceId = '';

    private string $registeredGroupName = '';

    private bool $registeredEphemeral = true;

    private readonly Log $logger;

    public function __construct(
        private readonly NacosConfig $nacosConfig,
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
        $this->lightBeatEnabled = false;

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
     * 使用进程级 Swoole Timer 定时上报 Nacos 实例心跳。
     * 启动时立即发送一次心跳，以便在创建定时器前拿到 lightBeatEnabled 与 clientBeatInterval。
     */
    public function startHeartbeat(int $intervalSeconds = self::HEARTBEAT_INTERVAL_SEC): void
    {
        if ('' === $this->registeredIp || $this->registeredPort <= 0 || '' === $this->registeredServiceName) {
            throw NacosMonitorException::throw('register instance before starting heartbeat');
        }

        if (null === $this->client) {
            $this->client = $this->nacosConfig->createClient();
        }

        // 每次启动先走一次重型心跳，由服务端响应决定是否切换轻量模式
        $this->lightBeatEnabled = false;
        $this->stopHeartbeat();
        $this->sendHeartbeat();

        $intervalMs = $this->resolveHeartbeatIntervalMs($intervalSeconds);
        $this->heartbeatTimer = \Swoole\Timer::tick($intervalMs, function () {
            $this->sendHeartbeat();
        });

        $this->logger->info(sprintf(
            'nacos heartbeat timer started, interval=%dms, lightBeat=%s, service=%s',
            $intervalMs,
            $this->lightBeatEnabled ? 'true' : 'false',
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

    public function deregister(): void
    {
        if ('' === $this->registeredIp || $this->registeredPort <= 0 || '' === $this->registeredServiceName) {
            return;
        }

        $this->stopHeartbeat();
        $this->lightBeatEnabled = false;

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

    private function sendHeartbeat(): void
    {
        if (null === $this->client) {
            return;
        }

        try {
            // 轻量模式：仅传 serviceName/ip/port 等标识，省略 beat 体
            if ($this->lightBeatEnabled) {
                $response = $this->client->instance->lightBeat(
                    $this->registeredServiceName,
                    $this->registeredIp,
                    $this->registeredPort,
                    $this->registeredGroupName,
                    $this->registeredNamespaceId,
                    $this->registeredEphemeral,
                );
            } else {
                // 重型心跳：首次或轻量未启用时，携带完整 beat 元数据完成注册/续约
                $beat = new RsInfo();
                $beat->setIp($this->registeredIp);
                $beat->setPort($this->registeredPort);
                $beat->setServiceName($this->registeredServiceName);
                $beat->setWeight($this->serviceRegisterConfig->weight);
                $beat->setEphemeral($this->registeredEphemeral);

                $response = $this->client->instance->beat(
                    $this->registeredServiceName,
                    $beat,
                    $this->registeredGroupName,
                    $this->registeredNamespaceId,
                    $this->registeredEphemeral,
                );
            }

            $this->applyBeatResponse($response);
        } catch (\Throwable $e) {
            $this->logger->error('nacos heartbeat failed: ' . $e->getMessage());
        }
    }

    /**
     * 解析心跳响应：同步服务端期望的间隔，并在 lightBeatEnabled=true 时切换轻量续约。
     */
    private function applyBeatResponse(BeatResponse $response): void
    {
        $interval = $response->getClientBeatInterval();
        if ($interval > 0 && $interval !== $this->clientBeatIntervalMs) {
            $this->clientBeatIntervalMs = min($interval, self::MAX_HEARTBEAT_INTERVAL_MS);
            $this->rescheduleHeartbeatTimer();
        }

        // lightBeatEnabled 后若继续发送 beat 参数，服务端会判定心跳无效
        if ($response->getLightBeatEnabled()) {
            if (!$this->lightBeatEnabled) {
                $this->lightBeatEnabled = true;
                $this->logger->info(sprintf(
                    'nacos light beat enabled, service=%s, interval=%dms',
                    $this->registeredServiceName,
                    $this->clientBeatIntervalMs,
                ));
            }
        }
    }

    private function rescheduleHeartbeatTimer(): void
    {
        if (null === $this->heartbeatTimer || !\Swoole\Timer::exists($this->heartbeatTimer)) {
            return;
        }

        \Swoole\Timer::clear($this->heartbeatTimer);
        $this->heartbeatTimer = \Swoole\Timer::tick($this->clientBeatIntervalMs, function () {
            $this->sendHeartbeat();
        });

        $this->logger->info(sprintf(
            'nacos heartbeat interval updated to %dms, service=%s',
            $this->clientBeatIntervalMs,
            $this->registeredServiceName,
        ));
    }

    /** 优先采用服务端 clientBeatInterval，其次回退到配置值 */
    private function resolveHeartbeatIntervalMs(int $intervalSeconds): int
    {
        if ($this->clientBeatIntervalMs > 0) {
            return min($this->clientBeatIntervalMs, self::MAX_HEARTBEAT_INTERVAL_MS);
        }

        if ($intervalSeconds <= 3) {
            $intervalSeconds = 5;
        }

        if ($intervalSeconds > 8) {
            $intervalSeconds = 5;
        }

        return $intervalSeconds * 1000;
    }

    /** Nacos Open API 中 public 命名空间应传空字符串 */
    private function normalizeNamespaceId(string $namespaceId): string
    {
        return 'public' === strtolower(trim($namespaceId)) ? '' : $namespaceId;
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
