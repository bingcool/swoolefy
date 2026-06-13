<?php

declare(strict_types=1);

namespace Swoolefy\Support\Nacos;

use Swoolefy\Core\Process\AbstractProcess;
use Swoolefy\Core\Swfy;
use Swoolefy\Exception\NacosMonitorException;

/**
 * 内置进程：读取 application.yaml 的 nacos 配置，在 Swoole 服务完全启动后注册到 Nacos 并心跳保活。
 */
class NacosRegisterServiceProcess extends AbstractProcess
{
    // 5s后开始nacos注册
    private const DEFAULT_WAIT_SECONDS = 5;

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

        if ($masterAlive) {
            $logger->info(sprintf(
                'swoole master process is alive (pid=%d), skip nacos deregister',
                $masterPid,
            ));
            $this->registrar->stopHeartbeat();

            return;
        }

        $logger->info(sprintf(
            'swoole master process is not alive (pid=%d), deregistering from nacos',
            $masterPid,
        ));
        $this->registrar->deregister();
    }

    private function resolveRegisterIp(NacosServiceRegisterConfig $serviceConfig, \Swoolefy\Util\Log $logger): string
    {
        $envIp = getenv(NacosConst::ENV_SERVICE_REGISTER_HOST);

        if (false !== $envIp && '' !== $envIp) {
            $logger->info(sprintf('%s is set, value=%s', NacosConst::ENV_SERVICE_REGISTER_HOST, $envIp));
            return (string) $envIp;
        }

        $logger->info(sprintf('%s is not set', NacosConst::ENV_SERVICE_REGISTER_HOST));

        if ('' !== $serviceConfig->ip) {
            return $serviceConfig->ip;
        }

        return $this->resolveLocalIp();
    }

    private function resolveRegisterPort(NacosServiceRegisterConfig $serviceConfig, \Swoolefy\Util\Log $logger): int
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

    private function waitUntilSwooleServerReady(int $port, \Swoolefy\Util\Log $logger): void
    {
        $maxWaitSeconds = self::DEFAULT_WAIT_SECONDS;
        $deadline = time() + $maxWaitSeconds;
        $logger->info(sprintf('nacos register: waiting for swoole server ready on port %d, timeout=%ds', $port, $maxWaitSeconds));

        while (time() < $deadline) {
            if ($this->isSwooleServerFullyStarted()) {
                $logger->info(sprintf('swoole server is ready on port %d', $port));
                return;
            }
            if (\Swoole\Coroutine::getCid() > 0) {
                \Swoole\Coroutine::sleep(0.2);
            } else {
                usleep(200_000);
            }
        }
    }

    private function isSwooleServerFullyStarted(): bool
    {
        $masterPid = Swfy::getMasterPid();
        if ($masterPid <= 0 || !\Swoole\Process::kill($masterPid, 0)) {
            return false;
        } else {
            return true;
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
