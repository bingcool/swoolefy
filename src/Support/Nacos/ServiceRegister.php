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
 * 失败恢复：tick 内退避重试 → 轻量连续失败回退重型 beat → 仍失败则重新 register。
 */
final class ServiceRegister
{
    private const HEARTBEAT_INTERVAL_SEC = 5;

    /** Nacos 默认 15s 无心跳标记不健康，间隔上限留足余量 */
    private const MAX_HEARTBEAT_INTERVAL_MS = 8000;

    /** 单次定时器触发内的立即重试次数（含退避） */
    private const HEARTBEAT_ATTEMPTS_PER_TICK = 2;

    /** 同一 tick 内重试退避（毫秒） */
    private const HEARTBEAT_RETRY_BACKOFF_MS = [200, 500];

    /** 轻量心跳连续失败达到此次数后，回退重型 beat */
    private const LIGHT_BEAT_FAIL_FALLBACK = 2;

    /** 连续失败达到此次数后，重新 register 实例 */
    private const HEARTBEAT_FAIL_REREGISTER = 3;

    private ?Client $client = null;

    private ?int $heartbeatTimer = null;

    /** 服务端首次心跳返回 lightBeatEnabled=true 后，后续只发轻量心跳（不带 beat 参数） */
    private bool $lightBeatEnabled = false;

    /** 由心跳响应 clientBeatInterval 驱动，默认 5000ms */
    private int $clientBeatIntervalMs = 5000;

    /** 跨 tick 累计的连续心跳失败次数，成功一次即清零 */
    private int $consecutiveHeartbeatFailures = 0;

    /** 当前已注册实例的快照，供心跳/注销/恢复使用 */
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
        // public 命名空间在 Open API 中须传空字符串
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
        // 新注册从重型心跳开始，由服务端响应再决定是否启用轻量模式
        $this->lightBeatEnabled = false;
        $this->consecutiveHeartbeatFailures = 0;

        $this->logger->info(sprintf(
            'nacos instance registered: %s:%d -> %s',
            $ip,
            $port,
            $serviceName,
        ));

        if ($startHeartbeat && $ephemeral) {
            // 持久化实例不走心跳保活，由 Nacos 主动探测
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
        $this->consecutiveHeartbeatFailures = 0;
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
        $this->consecutiveHeartbeatFailures = 0;

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
     * 定时器单次触发入口：先 tick 内退避重试，仍失败则逐级升级恢复。
     */
    private function sendHeartbeat(): void
    {
        if (null === $this->client) {
            return;
        }

        // 阶段 1：同一 tick 内快速重试，应对瞬时网络抖动
        for ($attempt = 1; $attempt <= self::HEARTBEAT_ATTEMPTS_PER_TICK; $attempt++) {
            try {
                $response = $this->dispatchHeartbeat();
                $this->applyBeatResponse($response);
                $this->consecutiveHeartbeatFailures = 0;

                return;
            } catch (\Throwable $e) {
                $this->consecutiveHeartbeatFailures++;
                $this->logger->error(sprintf(
                    'nacos heartbeat failed (tick_attempt=%d/%d, consecutive=%d): %s',
                    $attempt,
                    self::HEARTBEAT_ATTEMPTS_PER_TICK,
                    $this->consecutiveHeartbeatFailures,
                    $e->getMessage(),
                ));

                if ($attempt < self::HEARTBEAT_ATTEMPTS_PER_TICK) {
                    $this->waitHeartbeatBackoff($attempt);
                }
            }
        }

        // 阶段 2：tick 内重试耗尽后，按失败次数升级恢复策略
        $this->escalateHeartbeatRecovery();
    }

    /** 按当前模式路由：轻量（无 beat 体）或重型（带完整 beat JSON） */
    private function dispatchHeartbeat(): BeatResponse
    {
        if ($this->lightBeatEnabled) {
            // 轻量续约：仅 serviceName/ip/port 等标识
            return $this->client->instance->lightBeat(
                $this->registeredServiceName,
                $this->registeredIp,
                $this->registeredPort,
                $this->registeredGroupName,
                $this->registeredNamespaceId,
                $this->registeredEphemeral,
            );
        }

        return $this->dispatchHeavyBeat();
    }

    /** 重型心跳：携带 beat 元数据，用于首次注册、降级恢复或服务端未启用轻量模式 */
    private function dispatchHeavyBeat(): BeatResponse
    {
        $beat = $this->buildBeatInfo();

        return $this->client->instance->beat(
            $this->registeredServiceName,
            $beat,
            $this->registeredGroupName,
            $this->registeredNamespaceId,
            $this->registeredEphemeral,
        );
    }

    /** 组装 Nacos beat 请求体（RsInfo JSON） */
    private function buildBeatInfo(): RsInfo
    {
        $beat = new RsInfo();
        $beat->setIp($this->registeredIp);
        $beat->setPort($this->registeredPort);
        $beat->setServiceName($this->registeredServiceName);
        $beat->setWeight($this->serviceRegisterConfig->weight);
        $beat->setEphemeral($this->registeredEphemeral);

        return $beat;
    }

    /**
     * 单次 tick 内重试仍失败后的升级恢复：
     * 连续轻量失败 → 回退重型 beat；仍失败 → 重新 register（实例可能已被 Nacos 剔除）。
     */
    private function escalateHeartbeatRecovery(): void
    {
        // 阶段 2a：轻量模式连续失败，回退重型 beat 尝试重建续约
        if ($this->lightBeatEnabled && $this->consecutiveHeartbeatFailures >= self::LIGHT_BEAT_FAIL_FALLBACK) {
            $this->logger->warning(sprintf(
                'nacos light beat failed %d times, fallback to heavy beat, service=%s',
                $this->consecutiveHeartbeatFailures,
                $this->registeredServiceName,
            ));
            $this->lightBeatEnabled = false;

            try {
                $response = $this->dispatchHeavyBeat();
                $this->applyBeatResponse($response);
                $this->consecutiveHeartbeatFailures = 0;

                return;
            } catch (\Throwable $e) {
                $this->consecutiveHeartbeatFailures++;
                $this->logger->error('nacos heavy beat fallback failed: ' . $e->getMessage());
            }
        }

        // 阶段 2b：重型 beat 仍无效，说明实例可能已从注册表移除，需重新 register
        if ($this->consecutiveHeartbeatFailures >= self::HEARTBEAT_FAIL_REREGISTER) {
            $this->recoverByReregister();
        }
    }

    /**
     * 重新向 Nacos 注册实例并发送重型 beat，用于心跳长期失败后的兜底恢复。
     */
    private function recoverByReregister(): void
    {
        if ('' === $this->registeredIp || $this->registeredPort <= 0 || '' === $this->registeredServiceName) {
            return;
        }

        if (null === $this->client) {
            $this->client = $this->nacosConfig->createClient();
        }

        try {
            $this->logger->warning(sprintf(
                'nacos heartbeat: consecutive failures=%d, re-registering %s:%d -> %s',
                $this->consecutiveHeartbeatFailures,
                $this->registeredIp,
                $this->registeredPort,
                $this->registeredServiceName,
            ));

            $ok = $this->client->instance->register(
                $this->registeredIp,
                $this->registeredPort,
                $this->registeredServiceName,
                $this->registeredNamespaceId,
                $this->serviceRegisterConfig->weight,
                true,
                true,
                '',
                '',
                $this->registeredGroupName,
                $this->registeredEphemeral,
            );

            if (!$ok) {
                $this->logger->error(sprintf(
                    'nacos heartbeat re-register failed: %s:%d -> %s',
                    $this->registeredIp,
                    $this->registeredPort,
                    $this->registeredServiceName,
                ));

                return;
            }

            // 重新注册后从重型心跳开始，等待服务端再次下发 lightBeatEnabled
            $this->lightBeatEnabled = false;
            $response = $this->dispatchHeavyBeat();
            $this->applyBeatResponse($response);
            $this->consecutiveHeartbeatFailures = 0;

            $this->logger->info(sprintf(
                'nacos heartbeat re-register succeeded: %s:%d -> %s',
                $this->registeredIp,
                $this->registeredPort,
                $this->registeredServiceName,
            ));
        } catch (\Throwable $e) {
            $this->logger->error('nacos heartbeat re-register failed: ' . $e->getMessage());
        }
    }

    /** tick 内重试退避，协程环境用 Coroutine::sleep，否则 usleep */
    private function waitHeartbeatBackoff(int $attempt): void
    {
        $index = max(0, min(\count(self::HEARTBEAT_RETRY_BACKOFF_MS) - 1, $attempt - 1));
        $ms = self::HEARTBEAT_RETRY_BACKOFF_MS[$index];

        if (\Swoole\Coroutine::getCid() > 0) {
            \Swoole\Coroutine::sleep($ms / 1000);
        } else {
            usleep($ms * 1000);
        }
    }

    /**
     * 解析心跳响应：同步服务端期望的间隔，并在 lightBeatEnabled=true 时切换轻量续约。
     */
    private function applyBeatResponse(BeatResponse $response): void
    {
        $interval = $response->getClientBeatInterval();
        // 以服务端下发的间隔为准，上限 8s（Nacos 约 15s 无心跳即不健康）
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

    /** 服务端 clientBeatInterval 变化时，重建 Timer 以匹配期望心跳频率 */
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
