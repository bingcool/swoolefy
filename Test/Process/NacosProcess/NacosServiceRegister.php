<?php

declare(strict_types=1);

namespace Test\Process\NacosProcess;

use Swoolefy\Core\Log\LogManager;
use Swoolefy\Core\Process\AbstractProcess;
use Swoolefy\Exception\NacosMonitorException;
use Swoolefy\Support\Nacos\NacosConfig;
use Swoolefy\Support\Nacos\ServiceRegister;

/**
 * 自定义进程：向 Nacos 注册当前服务实例并定时心跳保活。
 */
class NacosServiceRegister extends AbstractProcess
{
    private ?ServiceRegister $registrar = null;

    public function run(): void
    {
        $config = NacosConfig::load(defined('APP_PATH') ? APP_PATH : null);
        $logger = LogManager::getInstance()->getLogger('nacos_log');

        $ip = '' !== $config->serviceIp ? $config->serviceIp : $this->resolveRegisterIp();
        $port = $config->servicePort > 0
            ? $config->servicePort
            : (defined('WORKER_PORT') ? (int) WORKER_PORT : 0);

        if ($port <= 0) {
            throw NacosMonitorException::throw('service port is required, set nacos.service_register.port in application.yaml or WORKER_PORT');
        }

        $heartbeatInterval = $config->serviceHeartbeatInterval;

        $this->registrar = new ServiceRegister($config, $logger);
        $this->registrar->register(
            ip: $ip,
            port: $port,
            heartbeatInterval: $heartbeatInterval,
        );

        $logger?->info(sprintf(
            'nacos service register process running, %s:%d, heartbeat=%ds',
            $ip,
            $port,
            $heartbeatInterval,
        ));

        while (true) {
            if (\extension_loaded('swoole') && \Swoole\Coroutine::getCid() > 0) {
                \Swoole\Coroutine::sleep(3600);
            } else {
                sleep(3600);
            }
        }
    }

    public function onReceive($msg, ...$args): void
    {
    }

    public function onShutDown(): void
    {
        $this->registrar?->stopHeartbeat();
    }

    private function resolveRegisterIp(): string
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
