<?php

declare(strict_types=1);

namespace PHPUintTest\Unit\Http;

use PHPUintTest\Support\HttpRequestHarness;
use PHPUintTest\TestCase;
use Swoolefy\Core\App;
use Swoolefy\Core\Application;
use Swoolefy\Core\BaseServer;
use Swoolefy\Exception\CorsRespException;
use Swoolefy\Http\HttpRoute;
use Swoolefy\Http\RequestInput;
use Swoolefy\Http\RequestValidate;
use Swoolefy\Http\ResponseOutput;
use Swoolefy\Http\RouteOption;
use Swoolefy\Library\Exception\ValidateException;
use Swoolefy\Support\Auth\AuthException;

/**
 * 参数校验必须发生在 before 中间件放行之后。
 */
final class HttpRouteMiddlewareValidationOrderTest extends TestCase
{
    private bool $ownedApp = false;

    protected function setUp(): void
    {
        if (!Application::issetApp()) {
            if (!defined('APP_PATH')) {
                BaseServer::setAppConf(['components' => []]);
            }
            Application::setApp(new App());
            $this->ownedApp = true;
        }
        OrderProbeController::$constructed = 0;
    }

    protected function tearDown(): void
    {
        if ($this->ownedApp) {
            Application::removeApp();
        }
        parent::tearDown();
    }

    public function testAuthExceptionSkipsValidation(): void
    {
        $route = $this->route(static function (RequestInput $request, ResponseOutput $response): bool {
            unset($request, $response);
            throw new AuthException('Missing bearer token', 401);
        });

        try {
            $route->run(OrderProbeController::class, 'ping');
            $this->fail('expected AuthException');
        } catch (AuthException $e) {
            $this->assertSame(401, $e->getCode());
        }

        $this->assertSame(['middleware'], $route->events);
        $this->assertSame(0, OrderProbeController::$constructed);
    }

    public function testCorsExceptionSkipsValidationAndReturnsFalse(): void
    {
        $route = $this->route(static function (RequestInput $request, ResponseOutput $response): bool {
            unset($request, $response);
            throw new CorsRespException();
        });

        $this->assertFalse($route->run(OrderProbeController::class, 'ping'));
        $this->assertSame(['middleware'], $route->events);
        $this->assertSame(0, OrderProbeController::$constructed);
    }

    public function testValidationRunsAfterMiddlewareAndKeepsValidateException(): void
    {
        $seenLimit = null;
        $route = $this->route(function (RequestInput $request) use (&$seenLimit): bool {
            $seenLimit = $request->getValue(RouteOption::API_LIMIT_NUM_KEY);

            return true;
        });

        try {
            $route->run(OrderProbeController::class, 'ping');
            $this->fail('expected ValidateException');
        } catch (ValidateException) {
            $this->assertSame(['middleware', 'class-validate'], $route->events);
        }

        $this->assertSame(17, $seenLimit);
        $this->assertSame(0, OrderProbeController::$constructed);
    }

    public function testPassingValidationHappensBeforeControllerConstruct(): void
    {
        $route = $this->route(static function (RequestInput $request, ResponseOutput $response): bool {
            unset($request, $response);

            return true;
        });

        try {
            $route->run(OrderProbeController::class, 'open');
            $this->fail('expected construct marker');
        } catch (\RuntimeException $e) {
            $this->assertSame('constructed-after-validation', $e->getMessage());
        }

        $this->assertSame(['middleware', 'class-validate', 'annotation-validate'], $route->events);
        $this->assertSame(1, OrderProbeController::$constructed);
    }

    private function route(\Closure $middleware): OrderTrackingHttpRoute
    {
        $input = HttpRequestHarness::requestInput('POST', '/order', [], []);
        $output = new ResponseOutput($input->getSwooleRequest(), $input->getSwooleResponse());
        $route = new OrderTrackingHttpRoute($input, $output);
        $option = new RouteOption();
        $option->withRateLimiterMiddleware('RateLimit', 17, 60);
        $route->prepare([$middleware], $option);

        return $route;
    }
}

final class OrderTrackingHttpRoute extends HttpRoute
{
    /** @var list<string> */
    public array $events = [];

    public function __construct(RequestInput $input, ResponseOutput $output)
    {
        $app = Application::getApp();
        $this->app = $app;
        $this->appConf = is_array($app?->appConf) ? $app->appConf : [];
        $this->requestInput = $input;
        $this->requestValidate = new RequestValidate($input);
        $this->responseOutput = $output;
        $this->httpMethod = $input->getMethod();
    }

    public function run(string $class, string $action): bool
    {
        return $this->invoke($class, $action);
    }

    /**
     * @param list<\Closure> $middlewares
     */
    public function prepare(array $middlewares, RouteOption $option): void
    {
        $wrapped = [];
        foreach ($middlewares as $middleware) {
            $wrapped[] = function (RequestInput $request, ResponseOutput $response) use ($middleware): mixed {
                $this->events[] = 'middleware';

                return $middleware($request, $response);
            };
        }
        $this->beforeMiddlewares = $wrapped;
        $this->routeOption = $option;
    }

    protected function validateByControllerValidationClass(string $class, string $action): void
    {
        $this->events[] = 'class-validate';
        parent::validateByControllerValidationClass($class, $action);
    }

    protected function validateActionParamRules(string $class, string $action): void
    {
        $this->events[] = 'annotation-validate';
        parent::validateActionParamRules($class, $action);
    }
}

class OrderProbeController
{
    public static int $constructed = 0;

    public function __construct()
    {
        self::$constructed++;
        throw new \RuntimeException('constructed-after-validation');
    }

    public function ping(): void
    {
    }

    public function open(): void
    {
    }
}

class OrderProbeValidation
{
    /**
     * @return array{rules: array<string, string>, messages: array<string, string>}
     */
    public function ping(): array
    {
        return ['rules' => ['name' => 'require'], 'messages' => []];
    }

    /**
     * @return array{rules: array<string, string>, messages: array<string, string>}
     */
    public function open(): array
    {
        return ['rules' => [], 'messages' => []];
    }
}
