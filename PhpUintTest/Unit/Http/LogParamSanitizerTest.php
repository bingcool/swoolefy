<?php

declare(strict_types=1);

namespace PhpUintTest\Unit\Http;

use PhpUintTest\TestCase;
use Swoolefy\Http\LogParamSanitizer;

/**
 * 阶段三 5.3（审计项 9）：统一参数脱敏器。
 * 目标：password/token 等敏感键遮蔽，并限制深度/字段数/长度。
 */
final class LogParamSanitizerTest extends TestCase
{
    /**
     * 测默认敏感键（含嵌套、大小写变体）被替换为 [REDACTED]。
     * 对应问题：缺参异常曾把完整 actionParams 暴露到响应/日志。
     */
    public function testRedactsDefaultSensitiveKeys(): void
    {
        $sanitized = LogParamSanitizer::sanitize([
            'username' => 'alice',
            'password' => 'plain-secret',
            'Token' => 'tok-1',
            'Authorization' => 'Bearer xyz',
            'cookie' => 'sid=1',
            'api_secret' => 's',
            'user_credential' => 'c',
            'nested' => [
                'access_token' => 'nested-tok',
                'ok' => 1,
            ],
        ]);

        $this->assertSame('alice', $sanitized['username']);
        $this->assertSame('[REDACTED]', $sanitized['password']);
        $this->assertSame('[REDACTED]', $sanitized['Token']);
        $this->assertSame('[REDACTED]', $sanitized['Authorization']);
        $this->assertSame('[REDACTED]', $sanitized['cookie']);
        $this->assertSame('[REDACTED]', $sanitized['api_secret']);
        $this->assertSame('[REDACTED]', $sanitized['user_credential']);
        $this->assertSame('[REDACTED]', $sanitized['nested']['access_token']);
        $this->assertSame(1, $sanitized['nested']['ok']);
        $this->assertStringNotContainsString('plain-secret', json_encode($sanitized));
        $this->assertStringNotContainsString('tok-1', json_encode($sanitized));
    }
}
