<?php

declare(strict_types=1);

namespace Swoolefy\Support\Nacos;

use Swoolefy\Core\Process\AbstractProcess;
use Swoolefy\Core\Swfy;
use Swoolefy\Exception\NacosMonitorException;
use Swoolefy\Util\Log;

/**
 * 内置进程：读取 application.yaml 的 nacos 配置，在 Swoole 服务完全启动后注册到 Nacos 并心跳保活。
 */
class NacosRegisterServiceProcess extends AbstractProcess
{
    // 最多等待 5s，避免端口探测异常时阻塞服务启动
    private const DEFAULT_WAIT_SECONDS = 5;

    // 单次 TCP 连接探测超时，控制在较短时间内避免卡住注册进程
    private const PORT_PROBE_TIMEOUT_SECONDS = 0.2;

    private ?ServiceRegister $registrar = null;

    public function run(): void
    {
        $logger = NacosLogger::get();
        $servicRegistereConfig = NacosServiceRegisterConfig::load();

        $ip = $this->resolveRegisterIp($servicRegistereConfig, $logger);
        $port = $this->resolveRegisterPort($servicRegistereConfig, $logger);

        if ($port <= 0) {
            throw NacosMonitorException::throw(
                'service port is required, set nacos.service_register.port in application.yaml or ' . NacosConst::ENV_SERVICE_REGISTER_PORT,
            );
        }

        $this->waitUntilSwooleServerReady($port, $logger);

        $this->registrar = ServiceRegister::create();
        $this->registrar->register(
            ip: $ip,
            port: $port,
            serviceName: $servicRegistereConfig->serviceName,
            namespaceId: $servicRegistereConfig->namespaceId,
            groupName: $servicRegistereConfig->groupName,
            heartbeatInterval: $servicRegistereConfig->heartbeatInterval,
        );

        $logger->info(sprintf(
            'nacos service register process running, %s:%d, serviceName=%s,groupName=%s, heartbeat=%ds',
            $ip,
            $port,
            $servicRegistereConfig->serviceName,
            $servicRegistereConfig->groupName,
            $servicRegistereConfig->heartbeatInterval,
        ));
    }

    public function onReceive($msg, ...$args): void
    {
    }

    public function onShutDown(): void
    {
        if (null === $this->registrar) {
            return;
        }

        $logger = NacosLogger::get();
        $masterPid = Swfy::getMasterPid();
        $masterAlive = $masterPid > 0 && \Swoole\Process::kill($masterPid, 0);

        // reload 时 Master 仍存活，但旧注册进程即将退出；必须主动注销旧实例，
        // 避免 Nacos 等待心跳超时期间仍把已停止实例分发给其他服务。
        $logger->info(sprintf(
            'nacos register process shutdown, masterAlive=%s, masterPid=%d, deregistering from nacos',
            $masterAlive ? 'true' : 'false',
            $masterPid,
        ));
        $this->registrar->deregister();
    }

    private function resolveRegisterIp(NacosServiceRegisterConfig $serviceConfig, Log $logger): string
    {
        $envIp = getenv(NacosConst::ENV_SERVICE_REGISTER_HOST);

        if (false !== $envIp && '' !== $envIp) {
            $logger->info(sprintf('%s is set, value=%s', NacosConst::ENV_SERVICE_REGISTER_HOST, $envIp));
            return (string) $envIp;
        }

        $logger->info(sprintf('%s is not set， now discovery k8s|ACK POD IP', NacosConst::ENV_SERVICE_REGISTER_HOST));

        // K8s/ACK 部署时建议通过 Downward API 注入 POD_IP，作为服务注册 IP。
        // 优先级低于 NACOS_SERVICE_REGISTER_HOST，避免覆盖本地开发显式指定的 127.0.0.1。
        $podIp = getenv(NacosConst::ENV_POD_IP);
        if (false !== $podIp && '' !== $podIp) {
            if (filter_var($podIp, FILTER_VALIDATE_IP)) {
                $logger->info(sprintf('%s is set, value=%s', NacosConst::ENV_POD_IP, $podIp));
                return (string) $podIp;
            }

            $logger->info(sprintf('%s is set but invalid, value=%s', NacosConst::ENV_POD_IP, $podIp));
        } else {
            $logger->info(sprintf('%s is not set', NacosConst::ENV_POD_IP));
        }

        if ('' !== $serviceConfig->ip) {
            return $serviceConfig->ip;
        }

        return $this->resolveLocalIp();
    }

    private function resolveRegisterPort(NacosServiceRegisterConfig $serviceConfig, Log $logger): int
    {
        if (defined('WORKER_PORT')) {
            return (int) WORKER_PORT;
        }

        $envPort = getenv(NacosConst::ENV_SERVICE_REGISTER_PORT);
        if (false !== $envPort && is_numeric($envPort)) {
            $logger->info(sprintf('%s is set, value=%s', NacosConst::ENV_SERVICE_REGISTER_PORT, $envPort));
            return (int) $envPort;
        }

        if (false !== $envPort && '' !== $envPort) {
            $logger->info(sprintf('%s is set but invalid, value=%s', NacosConst::ENV_SERVICE_REGISTER_PORT, $envPort));
        } else {
            $logger->info(sprintf('%s is not set', NacosConst::ENV_SERVICE_REGISTER_PORT));
        }

        if ($serviceConfig->port > 0) {
            return $serviceConfig->port;
        }

        $conf = Swfy::getConf();
        if (isset($conf['port']) && is_numeric($conf['port'])) {
            return (int) $conf['port'];
        }

        return 0;
    }

    /**
     * 注册到 Nacos 前先确认服务端口已经真正 listen。
     *
     * 只判断 Master 进程存活还不够：reload / cold start 时 Master 可能已存在，
     * 但 Worker 监听端口尚未就绪，过早注册会让其他服务发现到一个短暂不可用的实例。
     */
    private function waitUntilSwooleServerReady(int $port, Log $logger): void
    {
        $maxWaitSeconds = self::DEFAULT_WAIT_SECONDS;
        $deadline = microtime(true) + $maxWaitSeconds;
        $probeHost = $this->resolveServerProbeHost();
        $logger->info(sprintf(
            'nacos register: waiting for swoole server ready on %s:%d, timeout=%ds',
            $probeHost,
            $port,
            $maxWaitSeconds,
        ));

        while (microtime(true) < $deadline) {
            // Master 存活 + TCP 端口可连接，才认为实例可对外提供服务。
            if ($this->isSwooleServerFullyStarted() && $this->isPortListening($probeHost, $port)) {
                $logger->info(sprintf('swoole server is ready on %s:%d', $probeHost, $port));
                return;
            }
            $this->sleepForNextProbe();
        }

        // 探测只做启动前的保护，不能因为探测异常永久阻塞服务注册进程。
        $logger->warning(sprintf(
            'nacos register: swoole server not confirmed ready on %s:%d within %ds, continue register to avoid startup blocking',
            $probeHost,
            $port,
            $maxWaitSeconds,
        ));
    }

    /** 确认 Swoole Master 进程仍存活，避免向 Nacos 注册一个正在退出的服务。 */
    private function isSwooleServerFullyStarted(): bool
    {
        $masterPid = Swfy::getMasterPid();
        if ($masterPid <= 0 || !\Swoole\Process::kill($masterPid, 0)) {
            return false;
        } else {
            return true;
        }
    }

    /**
     * 端口探测使用服务实际监听地址，而不是注册到 Nacos 的地址。
     *
     * 本地开发常把注册 IP 写成 127.0.0.1；线上也可能注册容器/宿主机 IP。
     * 这些地址不一定等同于 Swoole bind 的 host，所以探测应基于 Swfy::getConf()['host']。
     */
    private function resolveServerProbeHost(): string
    {
        $conf = Swfy::getConf();
        $host = trim((string) ($conf['host'] ?? '127.0.0.1'));

        // 监听所有网卡时，用本机回环地址探测即可，不依赖注册到 Nacos 的外部 IP。
        if ('' === $host || '0.0.0.0' === $host || '::' === $host || '[::]' === $host) {
            return '127.0.0.1';
        }

        return trim($host, '[]');
    }

    /** 用短超时 TCP connect 判断端口是否已 listen，避免阻塞注册进程。 */
    private function isPortListening(string $host, int $port): bool
    {
        $address = filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)
            ? sprintf('tcp://[%s]:%d', $host, $port)
            : sprintf('tcp://%s:%d', $host, $port);

        $errno = 0;
        $errstr = '';
        // stream_socket_client 会真实走 TCP connect；失败通常代表端口尚未监听或地址不可达。
        $conn = @stream_socket_client(
            $address,
            $errno,
            $errstr,
            self::PORT_PROBE_TIMEOUT_SECONDS,
            STREAM_CLIENT_CONNECT,
        );

        if (false === $conn) {
            return false;
        }

        fclose($conn);

        return true;
    }

    /** 每轮探测间隔 200ms；协程环境让出执行权，非协程环境用 usleep。 */
    private function sleepForNextProbe(): void
    {
        if (\Swoole\Coroutine::getCid() > 0) {
            \Swoole\Coroutine::sleep(0.2);
        } else {
            usleep(200_000);
        }
    }

    private function resolveLocalIp(): string
    {
        if (!empty($_SERVER['SERVER_ADDR']) && filter_var($_SERVER['SERVER_ADDR'], FILTER_VALIDATE_IP)) {
            return (string) $_SERVER['SERVER_ADDR'];
        }

        $hostname = gethostname();
        if (false !== $hostname) {
            $ip = gethostbyname($hostname);
            if (false !== $ip && $ip !== $hostname) {
                return $ip;
            }
        }

        return '127.0.0.1';
    }

}
