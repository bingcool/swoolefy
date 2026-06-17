<?php

declare(strict_types=1);

namespace Swoolefy\Script\Sdk;

/**
 * 加载 Sdk/Stubs 目录下的 .stub.php 模板并替换占位符。
 */
final class SdkStubLoader
{
    private string $stubsDir;

    public function __construct(?string $stubsDir = null)
    {
        $this->stubsDir = $stubsDir ?? __DIR__ . '/Stubs';
    }

    /**
     * @param array<string, string> $replacements
     */
    public function load(string $name, array $replacements = []): string
    {
        $path = $this->stubsDir . '/' . $name . '.stub.php';
        if (!is_readable($path)) {
            throw new \RuntimeException('[gen:sdk] Stub not found: ' . $path);
        }

        $content = file_get_contents($path);
        if ($content === false) {
            throw new \RuntimeException('[gen:sdk] Cannot read stub: ' . $path);
        }

        if ($replacements !== []) {
            $content = str_replace(array_keys($replacements), array_values($replacements), $content);
        }

        return $content;
    }
}
