<?php

declare(strict_types=1);

namespace PHPUintTest\Unit\Worker;

use PHPUintTest\TestCase;
use Swoole\Http\Request as SwooleRequest;
use Swoole\Http\Response as SwooleResponse;
use Swoolefy\Worker\CtlApi;
use Swoolefy\Worker\Config\WorkerConfLoader;

/**
 * CtlApi 控制面：带 query string 的路由、框架统一响应结构、分组校验。
 */
final class CtlApiTest extends TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        WorkerConfLoader::resetConfPath();
        putenv('group');

        $this->tmpDir = sys_get_temp_dir() . '/swoolefy_ctlapi_' . getmypid() . '_' . bin2hex(random_bytes(3));
        mkdir($this->tmpDir, 0777, true);

        $confFile = $this->tmpDir . '/worker_conf.php';
        file_put_contents(
            $confFile,
            "<?php\nreturn [\n    ['process_name' => 'demo-proc', 'handler' => 'DemoHandler'],\n];\n"
        );
        WorkerConfLoader::useConfFile($confFile);

        if (!defined('WORKER_STATUS_FILE')) {
            define('WORKER_STATUS_FILE', sys_get_temp_dir() . '/swoolefy_ctlapi_status_' . getmypid() . '.log');
        }

        file_put_contents(WORKER_STATUS_FILE, json_encode([
            'master' => [
                'report_time' => '2026-10-24 10:00:00',
                'children_process' => [
                    'demo-proc' => [
                        0 => [
                            'pid' => getmypid(),
                            'start_time' => '2026-10-24 09:59:00',
                        ],
                    ],
                ],
            ],
        ], JSON_UNESCAPED_UNICODE));
    }

    protected function tearDown(): void
    {
        WorkerConfLoader::resetConfPath();
        putenv('group');
        if (defined('WORKER_STATUS_FILE') && is_file(WORKER_STATUS_FILE)) {
            unlink(WORKER_STATUS_FILE);
        }
        foreach (glob($this->tmpDir . '/*') ?: [] as $file) {
            unlink($file);
        }
        @rmdir($this->tmpDir);
    }

    /**
     * Swoole 的 request_uri 带 query string，旧实现与路由常量全等比较会落到 404 分支。
     */
    public function testProcessListRouteIgnoresQueryString(): void
    {
        $response = $this->createResponse();
        (new CtlApi($this->createRequest('/process-list?page=1&page_size=20'), $response))->handle();

        $body = json_decode($response->body, true);
        $this->assertSame(1, $response->endCalls);
        $this->assertSame('application/json; charset=utf-8', $response->headers['Content-Type']);
        $this->assertSame(0, $body['code']);
        $this->assertSame('success', $body['msg']);
        $this->assertSame(1, $body['data']['total']);
        $this->assertSame('demo-proc', $body['data']['list'][0]['process_name']);
        $this->assertSame(1, $body['data']['list'][0]['running']);
    }

    /**
     * 响应复用框架 ResponseFormatter：code/msg/data 统一结构。
     */
    public function testResponseUsesFrameworkFormatter(): void
    {
        $response = $this->createResponse();
        (new CtlApi($this->createRequest('/process-status?process_name=demo-proc'), $response))->handle();

        $body = json_decode($response->body, true);
        $this->assertSame(1, $response->endCalls);
        $this->assertArrayHasKey('code', $body);
        $this->assertArrayHasKey('msg', $body);
        $this->assertArrayHasKey('data', $body);
        $this->assertSame(1, $body['data']['status']);
    }

    /**
     * 未知 uri 走失败分支，且返回实际请求 path。
     */
    public function testUnknownUriReturnsFail(): void
    {
        $response = $this->createResponse();
        (new CtlApi($this->createRequest('/unknown?x=1'), $response))->handle();

        $body = json_decode($response->body, true);
        $this->assertSame(-1, $body['code']);
        $this->assertSame('找不到对应的uri', $body['msg']);
        $this->assertSame('/unknown', $body['data']['uri']);
    }

    /**
     * 不属于当前实例分组的进程直接拒绝，不写 FIFO。
     */
    public function testStartRejectsProcessOutsideCurrentGroup(): void
    {
        $response = $this->createResponse();
        (new CtlApi($this->createRequest('/process-start?process_name=other-proc'), $response))->handle();

        $body = json_decode($response->body, true);
        $this->assertSame(-1, $body['code']);
        $this->assertSame('进程不属于当前分组实例', $body['msg']);
        $this->assertSame('other-proc', $body['data']['process_name']);
    }

    private function createRequest(string $uri): SwooleRequest
    {
        $get = [];
        parse_str((string)parse_url($uri, PHP_URL_QUERY), $get);

        $request = new SwooleRequest();
        $request->header = [];
        $request->server = [
            'REQUEST_METHOD' => 'GET',
            'REQUEST_URI' => $uri,
            'REQUEST_TIME_FLOAT' => microtime(true),
        ];
        $request->get = $get;
        $request->post = [];

        return $request;
    }

    private function createResponse(): SwooleResponse
    {
        return new class extends SwooleResponse {
            /** @var array<string, string> */
            public array $headers = [];
            public string $body = '';
            public int $endCalls = 0;
            public bool $writable = true;

            public function __construct()
            {
            }

            public function header($key, $value, $format = true): bool
            {
                $this->headers[(string)$key] = (string)$value;

                return true;
            }

            public function write($data): bool
            {
                $this->body .= (string)$data;

                return true;
            }

            public function end($content = null): bool
            {
                ++$this->endCalls;
                if ($content !== null) {
                    $this->body .= (string)$content;
                }
                $this->writable = false;

                return true;
            }

            public function isWritable(): bool
            {
                return $this->writable;
            }
        };
    }
}
