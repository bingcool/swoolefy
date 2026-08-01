<?php

declare(strict_types=1);

namespace PHPUintTest\Unit\Cmd;

/**
 * CreateCmd 单测文件系统安全清理：仅允许删除 sys_get_temp_dir 下或本用例 tmpRoot 内的路径。
 */
trait CreateCmdTestFilesystemTrait
{
    private function assertPathIsSafeToRemove(string $path): void
    {
        $normalized = str_replace('\\', '/', $path);
        $tmpDir = str_replace('\\', '/', sys_get_temp_dir());
        $tmpDir = rtrim($tmpDir, '/');

        $underSysTmp = $normalized === $tmpDir
            || str_starts_with($normalized, $tmpDir . '/');

        $underOwnTmp = isset($this->tmpRoot)
            && (
                $normalized === $this->tmpRoot
                || str_starts_with($normalized, rtrim($this->tmpRoot, '/') . '/')
            );

        $projectTest = str_replace('\\', '/', dirname(__DIR__, 3) . '/Test');
        $isProjectTest = $normalized === $projectTest
            || str_starts_with($normalized, $projectTest . '/');

        $this->assertTrue(
            $underSysTmp || $underOwnTmp,
            "Refusing to remove path outside temp directory: {$path}"
        );
        $this->assertFalse(
            $isProjectTest,
            'Refusing to remove repository Test/ directory: ' . $path
        );
    }

    private function safeRemoveTree(string $dir): void
    {
        if (!is_dir($dir)) {
            if (is_file($dir) || is_link($dir)) {
                $this->assertPathIsSafeToRemove($dir);
                @unlink($dir);
            }
            return;
        }

        $this->assertPathIsSafeToRemove($dir);

        $items = scandir($dir);
        if ($items === false) {
            return;
        }
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir . '/' . $item;
            if (is_dir($path) && !is_link($path)) {
                $this->safeRemoveTree($path);
            } else {
                @unlink($path);
            }
        }
        @rmdir($dir);
    }
}
