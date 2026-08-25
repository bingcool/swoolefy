<?php

declare(strict_types=1);

namespace Swoolefy\Cmd\Infrastructure;

use Swoolefy\Cmd\Support\ProcessTreeTerminator;
use Swoolefy\Core\Swfy;

/**
 * PID 文件管理器：读取、校验、目录创建、清理。
 *
 * 设计原则：
 * - 只管理自己传入的 PID 文件路径，不扫描目录
 * - 生产环境中多应用共享 runtime/pid/ 目录时，扫描目录可能误删其他应用的 PID 文件
 * - 所有方法均为静态方法，无状态，便于各处复用
 */
final class PidFileManager
{
    /**
     * 从 Protocol/conf.php 读取 pid_file 路径，自动创建其所在目录。
     *
     * 优先使用 APP_PATH 下的配置，找不到时回退到 ROOT_PATH/$appName 下。
     * 若配置项缺失则返回空字符串。
     *
     * @param string $appName 应用名称
     * @return string PID 文件完整路径，未配置时返回 ''
     */
    public static function resolve(string $appName): string
    {
        // 优先使用 APP_PATH（已在 parseConstant 中定义）
        $path = defined('APP_PATH') ? APP_PATH . '/Protocol/conf.php' : '';
        if (!$path || !is_file($path)) {
            // 回退到 ROOT_PATH 拼接
            $path = ROOT_PATH . '/' . $appName . '/Protocol/conf.php';
        }

        if (!is_file($path)) {
            return '';
        }

        $config = (array) include $path;
        $pidFile = (string) ($config['setting']['pid_file'] ?? '');

        if (!$pidFile) {
            return '';
        }

        // 自动创建 PID 文件所在目录
        $dir = pathinfo($pidFile, PATHINFO_DIRNAME);
        if (!is_dir($dir)) {
            mkdir($dir, 0777, true);
        }

        return $pidFile;
    }

    /**
     * 获取pidFile
     *
     * @return string
     *
     */
    public static function getPidFile(): string
    {
        $pidFile = Swfy::getConf()['setting']['pid_file'] ?? '';
        if (empty($pidFile)) {
            $pidFile = PidFileManager::resolve(APP_NAME);
        }
        return $pidFile;
    }

    /**
     * 从 PID 文件中读取进程 ID。
     *
     * @param string $pidFile PID 文件路径
     * @return int 进程 ID，文件不存在或内容无效时返回 0
     */
    public static function read(string $pidFile): int
    {
        if (!is_file($pidFile)) {
            return 0;
        }

        $content = file_get_contents($pidFile);
        return is_numeric($content) ? (int) $content : 0;
    }

    /**
     * 删除 PID 文件（静默失败）。
     *
     * @param string $pidFile PID 文件路径
     */
    public static function remove(string $pidFile): void
    {
        if (is_file($pidFile)) {
            @unlink($pidFile);
        }
    }

    /**
     * 仅当指定 PID 文件对应的进程已死亡时，才删除该文件。
     *
     * 只操作传入的单个文件，不扫描目录，避免误删其他应用的 PID 文件。
     * 典型使用场景：启动前清理残留的过期 PID 文件。
     *
     * @param string $pidFile PID 文件路径
     */
    public static function removeIfDead(string $pidFile): void
    {
        if (!is_file($pidFile)) {
            return;
        }

        $pid = self::read($pidFile);
        if ($pid <= 0 || !ProcessTreeTerminator::isAlive($pid)) {
            @unlink($pidFile);
        }
    }

    /**
     * 检查 PID 文件是否存在且对应进程正在运行。
     *
     * @param string $pidFile PID 文件路径
     * @return bool 服务是否正在运行
     */
    public static function isRunning(string $pidFile): bool
    {
        if (!is_file($pidFile)) {
            return false;
        }

        $pid = self::read($pidFile);
        return $pid > 0 && ProcessTreeTerminator::isAlive($pid);
    }

    /**
     * 保存主进程 PID 到 PID 文件。
     *
     * @param int $pid 主进程 PID
     * @return bool 保存成功时返回 true
     */
    public static function saveMasterPid(int $pid, string $pidFile): bool
    {
        if ($pid > 0 && ProcessTreeTerminator::isAlive($pid)) {
            return file_put_contents($pidFile, $pid) >= 0;
        }
        return false;
    }
}
