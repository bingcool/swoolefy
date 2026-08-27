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

namespace Swoolefy\Worker;

use Swoolefy\Exception\WorkerException;

/**
 * confctl.json 单一读写入口：共享锁读、独占锁读改写、临时文件 + fflush + rename 原子落盘。
 * JSON 损坏时保留原文件并抛错，禁止静默覆盖为空配置。
 */
final class ConfCtlStore
{
    private string $filePath;

    private string $lockPath;

    public function __construct(string $filePath)
    {
        $this->filePath = $filePath;
        $this->lockPath = $filePath . '.lock';
    }

    /**
     * 解析默认 confctl 路径（优先 WORKER_CTL_CONF_FILE）。
     */
    public static function defaultPath(): string
    {
        if (defined('WORKER_CTL_CONF_FILE') && is_string(WORKER_CTL_CONF_FILE) && WORKER_CTL_CONF_FILE !== '') {
            return WORKER_CTL_CONF_FILE;
        }
        if (!defined('WORKER_PID_FILE_ROOT')) {
            throw new WorkerException('WORKER_PID_FILE_ROOT is not defined');
        }

        return WORKER_PID_FILE_ROOT . '/confctl.json';
    }

    public function getFilePath(): string
    {
        return $this->filePath;
    }

    /**
     * 共享锁读取；文件不存在返回空数组。
     *
     * @return array<string, mixed>
     */
    public function read(): array
    {
        return $this->withLock(LOCK_SH, function (): array {
            return $this->decodeFileOrEmpty();
        });
    }

    /**
     * 独占锁下读改写：$mutator 接收当前数组并返回新数组，再原子写入。
     *
     * @param callable(array): array $mutator
     * @return array<string, mixed> 写入后的完整配置
     */
    public function update(callable $mutator): array
    {
        return $this->withLock(LOCK_EX, function () use ($mutator): array {
            $current = $this->decodeFileOrEmpty();
            $next = $mutator($current);
            if (!is_array($next)) {
                throw new WorkerException('confctl mutator must return array');
            }
            $this->atomicWrite(json_encode($next, JSON_UNESCAPED_UNICODE));

            return $next;
        });
    }

    /**
     * @template T
     * @param callable(): T $callback
     * @return T
     */
    private function withLock(int $lockMode, callable $callback)
    {
        $dir = dirname($this->filePath);
        if (!is_dir($dir) && !@mkdir($dir, 0777, true) && !is_dir($dir)) {
            throw new WorkerException('Failed to create confctl directory: ' . $dir);
        }

        $lockFp = @fopen($this->lockPath, 'c+');
        if ($lockFp === false) {
            throw new WorkerException('Failed to open confctl lock: ' . $this->lockPath);
        }

        try {
            if (!flock($lockFp, $lockMode)) {
                throw new WorkerException('Failed to flock confctl lock: ' . $this->lockPath);
            }
            try {
                return $callback();
            } finally {
                flock($lockFp, LOCK_UN);
            }
        } finally {
            fclose($lockFp);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeFileOrEmpty(): array
    {
        if (!is_file($this->filePath)) {
            return [];
        }

        $content = file_get_contents($this->filePath);
        if ($content === false) {
            throw new WorkerException('Failed to read confctl file: ' . $this->filePath);
        }

        $content = trim($content);
        if ($content === '') {
            return [];
        }

        $decoded = json_decode($content, true);
        if (!is_array($decoded)) {
            // 损坏 JSON 保留原文件，禁止覆盖为空配置
            throw new WorkerException(
                'confctl.json is corrupted, refuse to overwrite: ' . $this->filePath
                . ', json_error=' . json_last_error_msg()
            );
        }

        return $decoded;
    }

    private function atomicWrite(string $content): void
    {
        $tmpFile = $this->filePath . '.' . getmypid() . '.' . bin2hex(random_bytes(4)) . '.tmp';
        $tmpFp = @fopen($tmpFile, 'wb');
        if ($tmpFp === false) {
            throw new WorkerException('Failed to open confctl temp file: ' . $tmpFile);
        }

        try {
            if (fwrite($tmpFp, $content) === false) {
                throw new WorkerException('Failed to write confctl temp file: ' . $tmpFile);
            }
            // 关键落盘后再 rename，降低断电/杀进程留下半截内容的概率
            if (!fflush($tmpFp)) {
                throw new WorkerException('Failed to fflush confctl temp file: ' . $tmpFile);
            }
        } finally {
            fclose($tmpFp);
        }

        // Windows rename 不能覆盖已存在目标；已持独占锁，可先删再替换
        if (DIRECTORY_SEPARATOR === '\\' && is_file($this->filePath)) {
            if (!@unlink($this->filePath)) {
                @unlink($tmpFile);
                throw new WorkerException('Failed to replace confctl on Windows: ' . $this->filePath);
            }
        }

        if (!@rename($tmpFile, $this->filePath)) {
            @unlink($tmpFile);
            throw new WorkerException('Failed to rename confctl temp file to: ' . $this->filePath);
        }
    }
}
