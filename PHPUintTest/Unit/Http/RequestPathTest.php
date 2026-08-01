<?php

declare(strict_types=1);

namespace PHPUintTest\Unit\Http;

use PHPUintTest\Support\HttpRequestHarness;
use PHPUintTest\TestCase;
use ReflectionMethod;
use Swoole\Http\Request as SwooleRequest;
use Swoole\Http\Response as SwooleResponse;
use Swoolefy\Core\App;
use Swoolefy\Http\Middleware\ApiRateLimiterMiddleware;
use Swoolefy\Http\RequestInput;

/**
 * 阶段三 5.8（审计项 49）：修正 path() 键名。
 * 目标：读规范化 PATH_INFO；缺失回退 REQUEST_URI（去 query）；空则 `/`；无 warning；限流 key 稳定。
 */
final class RequestPathTest extends TestCase
{
    /**
     * 测普通路径在 harness（双写键）与仅大写 PATH_INFO 下均正确。
     */
    public function testNormalPath(): void
    {
        $input = HttpRequestHarness::requestInput('GET', '/api/users');
        $this->assertSame('api/users', $input->path());

        $onlyUpper = $this->requestInputWithServer([
            'REQUEST_METHOD' => 'GET',
            'PATH_INFO' => '/api/users',
            'REQUEST_URI' => '/api/users',
        ]);
        $this->assertSame('api/users', $onlyUpper->path());
    }

    /**
     * 测 PATH_INFO 缺失时从带 query 的 REQUEST_URI 解析 path，不带查询串。
     */
    public function testFallsBackToRequestUriWithoutQuery(): void
    {
        $input = $this->requestInputWithServer([
            'REQUEST_METHOD' => 'GET',
            'REQUEST_URI' => '/api/search?q=1&x=2',
        ]);
        $this->assertSame('api/search', $input->path());
    }

    /**
     * 测空 path / 仅 `/` / 键全缺时返回 `/`，且不触发 warning。
     */
    public function testEmptyPathReturnsRootWithoutWarning(): void
    {
        $root = $this->requestInputWithServer([
            'REQUEST_METHOD' => 'GET',
            'PATH_INFO' => '/',
            'REQUEST_URI' => '/',
        ]);
        $this->assertSame('/', $root->path());

        $empty = $this->requestInputWithServer([
            'REQUEST_METHOD' => 'GET',
        ]);
        $this->assertSame('/', $empty->path());

        $queryOnly = $this->requestInputWithServer([
            'REQUEST_METHOD' => 'GET',
            'REQUEST_URI' => '/?foo=1',
        ]);
        $this->assertSame('/', $queryOnly->path());
    }

    /**
     * 测编码路径原样经 PATH_INFO 返回（不二次解码/破坏）。
     */
    public function testEncodedPathPreserved(): void
    {
        $encoded = '/api/%E4%B8%AD%E6%96%87';
        $input = $this->requestInputWithServer([
            'REQUEST_METHOD' => 'GET',
            'PATH_INFO' => $encoded,
            'REQUEST_URI' => $encoded . '?x=1',
        ]);
        $this->assertSame('api/%E4%B8%AD%E6%96%87', $input->path());
    }

    /**
     * 测 App::parseHeaders 将小写 path_info 提升为 PATH_INFO 后 path() 仍可用。
     * 对应问题：旧 path() 硬读 path_info，规范化后键被 unset 会 warning/空串。
     */
    public function testPathWorksAfterParseHeadersUppercasesKeys(): void
    {
        $request = new SwooleRequest();
        $request->header = [];
        $request->server = [
            'request_method' => 'GET',
            'path_info' => '/api/orders',
            'request_uri' => '/api/orders?page=2',
        ];

        $app = new App();
        $method = new ReflectionMethod(App::class, 'parseHeaders');
        $method->invoke($app, $request);

        $this->assertArrayHasKey('PATH_INFO', $request->server);
        $this->assertArrayNotHasKey('path_info', $request->server);

        $input = new RequestInput($request, new SwooleResponse());
        $this->assertSame('api/orders', $input->path());
    }

    /**
     * 测限流 key 生成依赖规范化 path，与 METHOD+path 维度稳定一致。
     */
    public function testRateLimitKeyUsesNormalizedPath(): void
    {
        $mw = new ApiRateLimiterMiddleware();
        $input = $this->requestInputWithServer([
            'REQUEST_METHOD' => 'GET',
            'PATH_INFO' => '/api/rate-test1',
            'REQUEST_URI' => '/api/rate-test1?n=1',
        ]);
        $this->assertSame('api:GET:/api/rate-test1', $mw->buildRateKey($input));
    }

    /**
     * @param array<string, mixed> $server
     */
    private function requestInputWithServer(array $server): RequestInput
    {
        $request = new SwooleRequest();
        $request->header = [];
        $request->server = $server;
        $request->get = [];
        $request->post = [];

        return new RequestInput($request, new SwooleResponse());
    }
}
