<?php

declare(strict_types=1);

namespace Swoolefy\Support\Nacos\Monitor;

use Swoolefy\Core\SystemEnv;
use Swoolefy\Exception\NacosMonitorException;
use Swoolefy\Util\Log;

/**
 * 调用 cli.php restart（等同 RestartCmd），参数从运行时常量读取。
 */
final class ServiceRestarter
{
    public function __construct(
        private readonly Log $logger,
    ) {
    }

    public function restart(): void
    {
        if (!defined('APP_NAME')) {
            throw NacosMonitorException::throw('APP_NAME is not defined, cannot restart');
        }

        $appName = (string) APP_NAME;
        $cliScript = $this->resolveCliScript();
        $phpBinary = SystemEnv::PhpBinFile();

        if (!is_file($cliScript)) {
            throw NacosMonitorException::throw('cli script not found: ' . $cliScript);
        }

        $command = sprintf(
            '%s %s restart %s --force=1 > /dev/null 2>&1 &',
            escapeshellarg($phpBinary),
            escapeshellarg($cliScript),
            escapeshellarg($appName),
        );

        $this->logger->info('exec restart: ' . $command);
        exec($command, $output, $exitCode);

        if (0 !== $exitCode) {
            $this->logger->error(sprintf('restart command=%s exit_code=%d',$command, $exitCode));
        }
    }

    private function resolveCliScript(): string
    {
        if (defined('WORKER_START_SCRIPT_FILE')) {
            return (string) WORKER_START_SCRIPT_FILE;
        }

        if (!empty($_SERVER['SCRIPT_FILENAME']) && !empty($_SERVER['PWD'])
            && str_contains((string) $_SERVER['SCRIPT_FILENAME'], (string) $_SERVER['PWD'])) {
            return (string) $_SERVER['SCRIPT_FILENAME'];
        }

        if (defined('START_DIR_ROOT')) {
            return START_DIR_ROOT . '/cli.php';
        }

        throw NacosMonitorException::throw('cannot resolve cli script from system');
    }
}
