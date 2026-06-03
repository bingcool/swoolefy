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
    private const DEFAULT_WAIT_SECONDS = 120;

    private const ENV_REGISTER_HOST = 'NACOS_SERVICE_REGISTER_HOST';

    private const ENV_REGISTER_PORT = 'NACOS_SERVICE_REGISTER_PORT';

    private ?ServiceRegister $registrar = null;

    public function run(): void
    {
        $serviceConfig = NacosServiceConfig::load();
        $logger = NacosLogger::get();

        $ip = $this->resolveRegisterIp($serviceConfig, $logger);
        $port = $this->resolveRegisterPort($serviceConfig, $logger);

        if ($port <= 0) {
            throw NacosMonitorException::throw(
                'service port is required, set nacos.service_register.port in application.yaml or NACOS_SERVICE_REGISTER_PORT',
            );
        }

        $this->waitUntilSwooleServerReady($port, $logger);

        $this->registrar = ServiceRegister::create();
        $this->registrar->register(
            ip: $ip,
            port: $port,
            heartbeatInterval: $serviceConfig->heartbeatInterval,
        );

        $logger->info(sprintf(
            'nacos service register process running, %s:%d, heartbeat=%ds',
            $ip,
            $port,
            $serviceConfig->heartbeatInterval,
        ));
    }

    public function onReceive($msg, ...$args): void
    {
    }

    public function onShutDown(): void
    {
        $this->registrar?->stopHeartbeat();
    }

    private function resolveRegisterIp(NacosServiceConfig $serviceConfig, \Swoolefy\Util\Log $logger): string
    {
        $envIp = getenv(self::ENV_REGISTER_HOST);
        if (false !== $envIp && '' !== $envIp) {
            $logger->info(sprintf('%s is set, value=%s', self::ENV_REGISTER_HOST, $envIp));
            return (string) $envIp;
        }

        $logger->info(sprintf('%s is not set', self::ENV_REGISTER_HOST));

        if ('' !== $serviceConfig->ip) {
            return $serviceConfig->ip;
        }

        return $this->resolveLocalIp();
    }

    private function resolveRegisterPort(NacosServiceConfig $serviceConfig, \Swoolefy\Util\Log $logger): int
    {
        $envPort = getenv(self::ENV_REGISTER_PORT);
        if (false !== $envPort && is_numeric($envPort)) {
            $logger->info(sprintf('%s is set, value=%s', self::ENV_REGISTER_PORT, $envPort));
            return (int) $envPort;
        }

        if (false !== $envPort && '' !== $envPort) {
            $logger->info(sprintf('%s is set but invalid, value=%s', self::ENV_REGISTER_PORT, $envPort));
        } else {
            $logger->info(sprintf('%s is not set', self::ENV_REGISTER_PORT));
        }

        if ($serviceConfig->port > 0) {
            return $serviceConfig->port;
        }

        if (defined('WORKER_PORT')) {
            return (int) WORKER_PORT;
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
        $logger->info(sprintf('waiting for swoole server ready on port %d, timeout=%ds', $port, $maxWaitSeconds));

        while (time() < $deadline) {
            if ($this->isSwooleServerFullyStarted($port)) {
                $logger->info(sprintf('swoole server is ready on port %d', $port));
                return;
            }

            if (\Swoole\Coroutine::getCid() > 0) {
                \Swoole\Coroutine::sleep(0.2);
            } else {
                usleep(200_000);
            }
        }

        throw NacosMonitorException::throw(sprintf(
            'swoole server is not ready within %ds, port=%d',
            $maxWaitSeconds,
            $port,
        ));
    }

    private function isSwooleServerFullyStarted(int $port): bool
    {
        $masterPid = Swfy::getMasterPid();
        if ($masterPid <= 0 || !\Swoole\Process::kill($masterPid, 0)) {
            return false;
        }

        $server = Swfy::getServer();
        if (is_object($server)) {
            $stats = $server->stats();
            if (!isset($stats['start_time']) || (int) $stats['start_time'] <= 0) {
                return false;
            }

            $expectedWorkerNum = (int) ($server->setting['worker_num'] ?? 1);
            $runningWorkerNum = (int) ($stats['worker_num'] ?? 0);
            if ($runningWorkerNum < $expectedWorkerNum) {
                return false;
            }
        }

        return $this->isPortListening('127.0.0.1', $port);
    }

    private function isPortListening(string $host, int $port): bool
    {
        $connection = @stream_socket_client(
            "tcp://{$host}:{$port}",
            $errno,
            $errstr,
            0.5,
            STREAM_CLIENT_CONNECT,
        );

        if (false === $connection) {
            return false;
        }

        fclose($connection);

        return true;
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
