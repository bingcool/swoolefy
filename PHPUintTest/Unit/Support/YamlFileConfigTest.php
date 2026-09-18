<?php

declare(strict_types=1);

namespace PHPUintTest\Unit\Support;

use PHPUintTest\TestCase;
use Swoolefy\Support\YamlFileConfig;

/**
 * YAML 文件加载与 ${ENV} 占位符替换。
 */
final class YamlFileConfigTest extends TestCase
{
    /**
     * ${POD_IP} 等占位符按 env() 取值；缺失则为空串，避免把字面量当成 IP。
     */
    public function testResolveEnvPlaceholdersReplacesPosixNames(): void
    {
        $env = static function (string $key): mixed {
            return match ($key) {
                'POD_IP' => '10.1.2.3',
                'NACOS_HOST' => 'nacos.internal',
                'PORT' => 8848,
                'FLAG' => true,
                default => null,
            };
        };

        $this->assertSame('10.1.2.3', YamlFileConfig::resolveEnvPlaceholders('${POD_IP}', $env));
        $this->assertSame(
            'http://nacos.internal:8848',
            YamlFileConfig::resolveEnvPlaceholders('http://${NACOS_HOST}:${PORT}', $env),
        );
        $this->assertSame('true', YamlFileConfig::resolveEnvPlaceholders('${FLAG}', $env));
        $this->assertSame('', YamlFileConfig::resolveEnvPlaceholders('${MISSING}', $env));
        $this->assertSame(30, YamlFileConfig::resolveEnvPlaceholders(30, $env));
        $this->assertSame(
            ['nacos' => ['ip' => '10.1.2.3', 'port' => 9501]],
            YamlFileConfig::resolveEnvPlaceholders(
                ['nacos' => ['ip' => '${POD_IP}', 'port' => 9501]],
                $env,
            ),
        );
        $this->assertSame('${123BAD}', YamlFileConfig::resolveEnvPlaceholders('${123BAD}', $env));
    }

    /**
     * load() 读盘后走占位符替换；缺文件返回 []；字面量保持不变。
     */
    public function testLoadReturnsEmptyWhenMissingAndKeepsLiterals(): void
    {
        $this->assertSame([], YamlFileConfig::load('/tmp/swoolefy-missing-' . bin2hex(random_bytes(4)) . '.yaml'));

        $tmp = sys_get_temp_dir() . '/swoolefy_yaml_' . bin2hex(random_bytes(4)) . '.yaml';
        file_put_contents($tmp, "nacos:\n  ip: 127.0.0.1\n  port: 8848\n");
        $parsed = YamlFileConfig::load($tmp);
        $this->assertSame('127.0.0.1', $parsed['nacos']['ip'] ?? null);
        $this->assertSame(8848, $parsed['nacos']['port'] ?? null);
        @unlink($tmp);
    }
}
