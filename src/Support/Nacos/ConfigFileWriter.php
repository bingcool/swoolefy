<?php
/**
 * +----------------------------------------------------------------------
 * | swoolefy framework bases on swoole extension development, we can use it easily!
 * +----------------------------------------------------------------------
 * | Licensed ( https://opensource.org/licenses/MIT )
 * +----------------------------------------------------------------------
 * | @see https://github.com/bingcool/swoolefy
 * +----------------------------------------------------------------------
 */

declare(strict_types=1);

namespace Swoolefy\Support\Nacos;

use Swoolefy\Exception\NacosMonitorException;

/**
 * 将 Nacos 配置内容原子写入本地配置文件（如 APP_PATH/.env）。
 */
final class ConfigFileWriter
{
    public function write(string $filePath, string $content): void
    {
        $dir = dirname($filePath);
        if (!is_dir($dir)) {
            mkdir($dir, 0777, true);
        }

        $tmpFile = $filePath . '.' . getmypid() . '.tmp';
        if (false === file_put_contents($tmpFile, $content)) {
            throw NacosMonitorException::throw('Failed to write temp config file: ' . $tmpFile);
        }

        if (!rename($tmpFile, $filePath)) {
            @unlink($tmpFile);
            throw NacosMonitorException::throw('Failed to replace config file: ' . $filePath);
        }
    }
}
