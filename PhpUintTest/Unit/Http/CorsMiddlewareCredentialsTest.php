<?php

declare(strict_types=1);

namespace PhpUintTest\Unit\Http;

use PhpUintTest\TestCase;
use ReflectionMethod;
use ReflectionProperty;
use Swoole\Http\Request as SwooleRequest;
use Swoole\Http\Response as SwooleResponse;
use Swoolefy\Http\Middleware\CorsMiddleware;
use Swoolefy\Http\RequestInput;
use Swoolefy\Http\ResponseOutput;

/**
 * 阶段三 5.4（审计项 10）：CORS pattern 命中也必须写凭证头。
 * 目标：精确/pattern 共用写头；supportsCredentials 时写 Credentials；禁止 * + credentials；保留 Vary。
 */
final class CorsMiddlewareCredentialsTest extends TestCase
{
    /**
     * 测 pattern Origin 命中且 supportsCredentials=true 时写入 Allow-Credentials，并回显 Origin。
     * 对应问题：旧逻辑仅精确 Origin 写凭证头，pattern 命中遗漏。
     */
    public function testPatternOriginWritesCredentialsHeader(): void
    {
        [$headers] = $this->invokeSetCorsHeaders(
            origin: 'https://api.example.com',
            allowedOrigins: ['https://other.example.com'],
            originPattern: '/^https:\/\/(.*\.)?example\.com$/i',
            supportsCredentials: true,
        );

        $this->assertSame('https://api.example.com', $headers['Access-Control-Allow-Origin'] ?? null);
        $this->assertSame('true', $headers['Access-Control-Allow-Credentials'] ?? null);
        $this->assertSame('Origin', $headers['Vary'] ?? null);
    }

    /**
     * 测精确 Origin + credentials 与 pattern 路径一致（同一写头方法语义）。
     */
    public function testExactOriginWritesCredentialsHeader(): void
    {
        [$headers] = $this->invokeSetCorsHeaders(
            origin: 'https://app.example.com',
            allowedOrigins: ['https://app.example.com'],
            originPattern: null,
            supportsCredentials: true,
        );

        $this->assertSame('https://app.example.com', $headers['Access-Control-Allow-Origin'] ?? null);
        $this->assertSame('true', $headers['Access-Control-Allow-Credentials'] ?? null);
        $this->assertSame('Origin', $headers['Vary'] ?? null);
    }

    /**
     * 测 credentials 开启时即使配置了 * 也不得返回 Allow-Origin: *。
     */
    public function testCredentialsForbidsWildcardAllowOrigin(): void
    {
        [$headers] = $this->invokeSetCorsHeaders(
            origin: 'https://client.example.com',
            allowedOrigins: ['*'],
            originPattern: null,
            supportsCredentials: true,
        );

        $this->assertSame('https://client.example.com', $headers['Access-Control-Allow-Origin'] ?? null);
        $this->assertNotSame('*', $headers['Access-Control-Allow-Origin'] ?? null);
        $this->assertSame('true', $headers['Access-Control-Allow-Credentials'] ?? null);
    }

    /**
     * 测无凭证时 * 仍可用；拒绝未命中 Origin 不写 Allow-Origin。
     */
    public function testWildcardWithoutCredentialsAndRejectedOrigin(): void
    {
        [$okHeaders] = $this->invokeSetCorsHeaders(
            origin: 'https://any.example.com',
            allowedOrigins: ['*'],
            originPattern: null,
            supportsCredentials: false,
        );
        $this->assertSame('*', $okHeaders['Access-Control-Allow-Origin'] ?? null);
        $this->assertArrayNotHasKey('Access-Control-Allow-Credentials', $okHeaders);

        [$deniedHeaders] = $this->invokeSetCorsHeaders(
            origin: 'https://evil.test',
            allowedOrigins: ['https://good.example.com'],
            originPattern: null,
            supportsCredentials: false,
        );
        $this->assertArrayNotHasKey('Access-Control-Allow-Origin', $deniedHeaders);
        $this->assertSame('Origin', $deniedHeaders['Vary'] ?? null);
    }

    /**
     * @return array{0: array<string, string>}
     */
    private function invokeSetCorsHeaders(
        string $origin,
        array $allowedOrigins,
        ?string $originPattern,
        bool $supportsCredentials,
    ): array {
        $mw = new CorsMiddleware();
        $optionsProp = new ReflectionProperty(CorsMiddleware::class, 'options');
        $optionsProp->setAccessible(true);
        $options = $optionsProp->getValue($mw);
        $options['allowedOrigins'] = $allowedOrigins;
        $options['supportsCredentials'] = $supportsCredentials;
        $optionsProp->setValue($mw, $options);

        $patternProp = new ReflectionProperty(CorsMiddleware::class, 'originPattern');
        $patternProp->setAccessible(true);
        $patternProp->setValue($mw, $originPattern);

        $response = new class extends SwooleResponse {
            /** @var array<string, string> */
            public array $capturedHeaders = [];

            public function __construct()
            {
            }

            public function header($key, $value, $format = true): bool
            {
                $this->capturedHeaders[(string) $key] = is_array($value)
                    ? implode(', ', $value)
                    : (string) $value;

                return true;
            }
        };

        $request = new SwooleRequest();
        $request->header = ['origin' => $origin];
        $request->server = [
            'REQUEST_METHOD' => 'GET',
            'request_method' => 'GET',
            'PATH_INFO' => '/',
            'path_info' => '/',
            'REQUEST_URI' => '/',
            'request_uri' => '/',
            'SERVER_PROTOCOL' => 'HTTP/1.1',
        ];

        $input = new RequestInput($request, $response);
        $output = new ResponseOutput($request, $response);

        $method = new ReflectionMethod(CorsMiddleware::class, 'setCorsHeaders');
        $method->setAccessible(true);
        $method->invoke($mw, $input, $output);

        return [$response->capturedHeaders];
    }
}
