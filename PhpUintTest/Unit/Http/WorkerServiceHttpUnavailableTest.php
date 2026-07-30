<?php

declare(strict_types=1);

namespace PhpUintTest\Unit\Http;

use PhpUintTest\TestCase;
use Swoole\Http\Response;
use Swoolefy\Http\HttpServer;

/**
 * 阶段一 P0-3.3（审计项 41）：WorkerService 无 HTTP 控制面时必须结束响应。
 * 目标：固定 503 JSON、不暴露内部类型、end 只调用一次，避免请求悬挂。
 */
final class WorkerServiceHttpUnavailableTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        require_once dirname(__DIR__, 3) . '/src/Support/Tests/SwoolefyTestBootstrap.php';
    }

    /**
     * 测响应体仅为约定 code，不含 service/type 等内部字段。
     * 对应问题：503 体若泄露内部服务类型/配置会扩大攻击面。
     */
    public function testUnavailableBodyIsOpaqueFixedCode(): void
    {
        $body = HttpServer::buildWorkerServiceHttpUnavailableBody();
        $decoded = json_decode($body, true);

        $this->assertIsArray($decoded);
        $this->assertSame(['code' => HttpServer::WORKER_SERVICE_HTTP_UNAVAILABLE], $decoded);
        $this->assertSame('worker_service_http_unavailable', $decoded['code']);
        $this->assertArrayNotHasKey('service', $decoded);
        $this->assertArrayNotHasKey('type', $decoded);
    }

    /**
     * 测 CLI 单测引导下控制面判定为关闭（非 Cron/Daemon+HTTP）。
     * 对应问题：无控制面组合应走 503 分支，而不是误进 CtlApi。
     */
    public function testControlPlaneDisabledUnderCliBootstrapStubs(): void
    {
        // SwoolefyTestBootstrap 将 cron/daemon 视为 false；无真实 HTTP Server 时 isHttpApp 也为 false
        $this->assertFalse(HttpServer::shouldServeWorkerServiceHttpControlPlane());
    }

    /**
     * 测 endWorkerServiceHttpUnavailable：503、JSON Content-Type、且 end 恰好一次。
     * 对应问题：无处理器 WorkerService 空返回会导致 HTTP 请求悬挂。
     */
    public function testEndUnavailableCallsEndOnceWith503Json(): void
    {
        $response = new class extends Response {
            public int $statusCode = 0;
            /** @var array<string, string> */
            public array $headers = [];
            public int $endCalls = 0;
            public string $body = '';

            public function __construct()
            {
            }

            public function status($http_status_code, $reason = null, $preserve_keep_alive = null): bool
            {
                $this->statusCode = (int)$http_status_code;

                return true;
            }

            public function header($key, $value, $format = true): bool
            {
                $this->headers[(string)$key] = (string)$value;

                return true;
            }

            public function end($content = null): bool
            {
                ++$this->endCalls;
                $this->body = (string)$content;

                return true;
            }
        };

        HttpServer::endWorkerServiceHttpUnavailable($response);

        $this->assertSame(503, $response->statusCode);
        $this->assertSame('application/json; charset=utf-8', $response->headers['Content-Type']);
        $this->assertSame(1, $response->endCalls);
        $this->assertSame(HttpServer::buildWorkerServiceHttpUnavailableBody(), $response->body);
    }
}
