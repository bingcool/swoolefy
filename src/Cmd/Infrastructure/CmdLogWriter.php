<?php

declare(strict_types=1);

namespace Swoolefy\Cmd\Infrastructure;

/**
 * CLI 命令层日志写入器，支持文件大小轮转。
 *
 * 职责：将命令执行过程中的关键事件写入日志文件。
 * 日志格式：【日期时间】消息内容
 * 当文件超过 max_size 时自动删除旧文件重建（简单轮转策略）。
 */
final class CmdLogWriter
{
    /** 默认最大日志文件大小：5MB */
    private const DEFAULT_MAX_SIZE = 5 * 1024 * 1024;

    /**
     * 写入一条日志消息。
     *
     * 日志文件路径从全局常量 WORKER_CTL_LOG_FILE 获取。
     * 若未定义该常量则静默跳过（非 WorkerService 场景无日志文件）。
     *
     * @param string $msg 日志消息内容
     */
    public static function write(string $msg): void
    {
        if (!defined('WORKER_CTL_LOG_FILE')) {
            return;
        }

        $logFile = WORKER_CTL_LOG_FILE;
        $maxSize = defined('MAX_LOG_FILE_SIZE') ? MAX_LOG_FILE_SIZE : self::DEFAULT_MAX_SIZE;

        // 文件大小轮转：超过阈值时删除旧文件
        if (is_file($logFile) && filesize($logFile) > $maxSize) {
            unlink($logFile);
        }

        // 自动创建日志目录
        $dir = dirname($logFile);
        if (!is_dir($dir)) {
            mkdir($dir, 0777, true);
        }

        $logFd = fopen($logFile, 'a+');
        $date = date('Y-m-d H:i:s');
        fwrite($logFd, "【{$date}】{$msg}" . PHP_EOL);
        fclose($logFd);
    }
}
