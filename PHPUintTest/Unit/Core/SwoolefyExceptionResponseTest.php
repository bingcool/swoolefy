<?php

declare(strict_types=1);

namespace PHPUintTest\Unit\Core;

use PHPUintTest\TestCase;
use ReflectionProperty;
use Swoole\Http\Request as SwooleRequest;
use Swoole\Http\Response as SwooleResponse;
use Swoole\Http\Status;
use Swoolefy\Core\App;
use Swoolefy\Core\SwoolefyException;
use Swoolefy\Http\ResponseOutput;
use Swoolefy\Support\Auth\AuthException;

/**
 * 异常响应：withStatus 必须收到 int，SQLSTATE 等字符串 code 不得再掩盖原始错误。
 */
final class SwoolefyExceptionResponseTest extends TestCase
{
    public function testNormalizeExceptionCodeCastsNumericStringAndSqlstate(): void
    {
        $this->assertSame(401, SwoolefyException::normalizeExceptionCode('401'));
        $this->assertSame(500, SwoolefyException::normalizeExceptionCode('500'));
        $this->assertSame(401, SwoolefyException::normalizeExceptionCode(401));
        $this->assertSame(-1, SwoolefyException::normalizeExceptionCode(0));
        $this->assertSame(-1, SwoolefyException::normalizeExceptionCode('42S22'));
        $this->assertSame(-1, SwoolefyException::normalizeExceptionCode('HY000'));
        $this->assertSame(-1, SwoolefyException::normalizeExceptionCode(''));
    }

    public function testNormalizeHttpStatusAlwaysReturnsInt(): void
    {
        $this->assertSame(401, ResponseOutput::normalizeHttpStatus(401));
        $this->assertSame(401, ResponseOutput::normalizeHttpStatus('401'));
        $this->assertSame(Status::INTERNAL_SERVER_ERROR, ResponseOutput::normalizeHttpStatus('42S22'));
        $this->assertTrue(is_int(ResponseOutput::normalizeHttpStatus('42S22')));
        $this->assertTrue(is_int(ResponseOutput::normalizeHttpStatus('401')));
    }

    public function testWithStatusAcceptsNumericStringAsInt(): void
    {
        $response = $this->capturingResponse();
        $output = new ResponseOutput(new SwooleRequest(), $response);

        $output->withStatus('401');

        $this->assertSame(401, $response->statusCode);
        $this->assertTrue(is_int($response->statusCode));
    }

    public function testWithStatusSqlstateFallsBackTo500Int(): void
    {
        $response = $this->capturingResponse();
        $output = new ResponseOutput(new SwooleRequest(), $response);

        $output->withStatus('42S22');

        $this->assertSame(Status::INTERNAL_SERVER_ERROR, $response->statusCode);
        $this->assertTrue(is_int($response->statusCode));
    }

    public function testResponseDoesNotCrashOnSqlstateCode(): void
    {
        $this->defineEnvConstants();
        $response = $this->capturingResponse();
        $app = (new \ReflectionClass(App::class))->newInstanceWithoutConstructor();
        $app->swooleRequest = $this->request('/api/v1/tasks', 'page=1&pageSize=20&status=1&nodeId=1786977160');
        $app->swooleResponse = $response;

        $throwable = new \RuntimeException("Unknown column 'name' in 'field list'");
        $codeProp = new ReflectionProperty(\Exception::class, 'code');
        $codeProp->setAccessible(true);
        $codeProp->setValue($throwable, '42S22');

        ob_start();
        try {
            QuietSwoolefyException::response($app, $throwable);
        } finally {
            ob_end_clean();
        }

        $this->assertSame(0, $response->statusCode);
        $this->assertTrue(is_int($response->statusCode));
        $decoded = json_decode($response->body, true);
        $this->assertIsArray($decoded);
        $this->assertSame(-1, $decoded['code'] ?? null);
        $this->assertIsInt($decoded['code']);
    }

    public function testResponseWritesIntHttpStatusForAuthException(): void
    {
        $this->defineEnvConstants();
        $response = $this->capturingResponse();
        $app = (new \ReflectionClass(App::class))->newInstanceWithoutConstructor();
        $app->swooleRequest = $this->request('/api/v1/tasks');
        $app->swooleResponse = $response;

        ob_start();
        try {
            QuietSwoolefyException::response($app, new AuthException('Unauthenticated', 401));
        } finally {
            ob_end_clean();
        }

        $this->assertSame(401, $response->statusCode);
        $this->assertTrue(is_int($response->statusCode));
        $decoded = json_decode($response->body, true);
        $this->assertSame(401, $decoded['code'] ?? null);
    }

    /**
     * response() 会读 SystemEnv::isPrdEnv()，需补齐命名空间内环境常量，避免拉全量 bootstrap。
     */
    private function defineEnvConstants(): void
    {
        foreach (['SWOOLEFY_DEV' => 'dev', 'SWOOLEFY_TEST' => 'test', 'SWOOLEFY_GRA' => 'gra', 'SWOOLEFY_PRD' => 'prd'] as $name => $value) {
            defined($name) or define($name, $value);
            defined('Swoolefy\\Core\\' . $name) or define('Swoolefy\\Core\\' . $name, $value);
        }
        defined('SWOOLEFY_ENV') or define('SWOOLEFY_ENV', 'dev');
        defined('Swoolefy\\Core\\SWOOLEFY_ENV') or define('Swoolefy\\Core\\SWOOLEFY_ENV', 'dev');
    }

    /**
     * @return SwooleResponse&object{statusCode:int,body:string}
     */
    private function capturingResponse(): SwooleResponse
    {
        return new class extends SwooleResponse {
            public int $statusCode = 0;
            public string $body = '';

            public function __construct()
            {
            }

            public function status($http_status_code, $reason = null, $preserve_keep_alive = null): bool
            {
                if (!is_int($http_status_code)) {
                    throw new \TypeError('HTTP status must be int, ' . get_debug_type($http_status_code) . ' given');
                }
                $this->statusCode = $http_status_code;

                return true;
            }

            public function header($key, $value, $format = true): bool
            {
                return true;
            }

            public function write($data): bool
            {
                $this->body .= (string) $data;

                return true;
            }

            public function end($content = null): bool
            {
                if ($content !== null) {
                    $this->body .= (string) $content;
                }

                return true;
            }
        };
    }

    private function request(string $uri, string $query = ''): SwooleRequest
    {
        $request = new SwooleRequest();
        $request->header = [];
        $request->get = [];
        $request->post = [];
        $request->server = [
            'REQUEST_URI' => $uri,
            'QUERY_STRING' => $query,
        ];

        return $request;
    }
}

/**
 * 单测用：跳过 shutHalt 打日志 / 控制台，只验证响应状态码类型。
 */
final class QuietSwoolefyException extends SwoolefyException
{
    public static function shutHalt(
        string $errorMsg,
        $errorType,
        \Throwable|null $throwable
    ): void {
    }
}
