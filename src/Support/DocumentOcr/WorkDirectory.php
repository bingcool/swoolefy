<?php

declare(strict_types=1);

namespace Swoolefy\Support\DocumentOcr;

use Swoolefy\Support\DocumentOcr\Exceptions\DocumentException;

/**
 * 解析临时工作目录助手。
 *
 * 每次解析创建独立子目录，避免并发互相覆盖；调用方负责 finally 清理。
 */
final class WorkDirectory
{
    /**
     * @param string $baseDir 配置中的 work_dir 根路径
     */
    public function __construct(
        private readonly string $baseDir,
    ) {
    }

    /** 确保根目录存在且可写。 */
    public function ensureBase(): string
    {
        $base = rtrim($this->baseDir, DIRECTORY_SEPARATOR);
        if ($base === '') {
            throw new DocumentException('DocumentOcr work_dir must not be empty');
        }

        if (!is_dir($base) && !@mkdir($base, 0775, true) && !is_dir($base)) {
            throw new DocumentException('Failed to create DocumentOcr work_dir: ' . $base);
        }

        return $base;
    }

    /**
     * 创建带前缀的唯一子目录，返回绝对路径。
     */
    public function createJobDir(string $prefix = 'job'): string
    {
        $base = $this->ensureBase();
        $name = $prefix . '_' . date('YmdHis') . '_' . bin2hex(random_bytes(4));
        $dir = $base . DIRECTORY_SEPARATOR . $name;

        if (!@mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new DocumentException('Failed to create DocumentOcr job dir: ' . $dir);
        }

        return $dir;
    }

    /**
     * 递归删除目录（仅允许删除位于 baseDir 下的路径，防止误删）。
     */
    public function removeDir(string $dir): void
    {
        $base = realpath($this->ensureBase());
        $target = realpath($dir);
        if ($base === false || $target === false) {
            return;
        }

        // 路径安全：目标必须在 work_dir 内
        if ($target !== $base && !str_starts_with($target, $base . DIRECTORY_SEPARATOR)) {
            return;
        }

        $this->rmTree($target);
    }

    private function rmTree(string $path): void
    {
        if (is_file($path) || is_link($path)) {
            @unlink($path);

            return;
        }

        if (!is_dir($path)) {
            return;
        }

        $items = scandir($path) ?: [];
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $this->rmTree($path . DIRECTORY_SEPARATOR . $item);
        }
        @rmdir($path);
    }
}
