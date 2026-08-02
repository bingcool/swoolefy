<?php

declare(strict_types=1);

namespace PHPUintTest\Unit\Http;

use PHPUintTest\TestCase;
use ReflectionProperty;
use Swoolefy\Exception\InvalidMiddlewareException;
use Swoolefy\Http\HttpRoute;
use Swoolefy\Http\RequestInput;
use Swoolefy\Http\ResponseOutput;
use Swoolefy\Http\Route;
use Swoolefy\Http\RouteOption;
use Test\Middleware\Route\ValidLoginMiddleware;

/**
 * 阶段三 5.1：中间件配置 fail closed。
 */
final class RouteMiddlewareFailClosedTest extends TestCase
{
    protected function tearDown(): void
    {
        $this->clearRouteMap();
        Route::resetRouteMiddlewareValidatedFlag();
        parent::tearDown();
    }

    /**
     * 验证：鉴权中间件拼写错误时启动校验失败。
     */
    public function testTypoMiddlewareFailsAtStartupValidation(): void
    {
        $routeMap = [
            '/api/secure' => [
                'GET' => [
                    'group_meta' => [
                        'middleware' => ['Test\\Middleware\\Route\\TypoAuthMiddleware'],
                    ],
                    'route_meta' => [
                        'dispatch_route' => ['App\\Controller\\IndexController', 'index'],
                    ],
                    'route_option' => null,
                ],
            ],
        ];

        $this->expectException(InvalidMiddlewareException::class);
        $this->expectExceptionMessage('middleware class not found');
        $this->expectExceptionMessage('/api/secure [GET]');
        $this->expectExceptionMessage('TypoAuthMiddleware');

        HttpRoute::assertRouteMapMiddlewareValid($routeMap);
    }

    /**
     * 验证：实现 RouteMiddlewareInterface 的合法中间件可通过校验。
     */
    public function testInterfaceMiddlewarePassesValidation(): void
    {
        $routeMap = [
            '/api/login' => [
                'POST' => [
                    'group_meta' => [
                        'middleware' => [ValidLoginMiddleware::class],
                    ],
                    'route_meta' => [
                        'dispatch_route' => ['App\\Controller\\IndexController', 'index'],
                    ],
                    'route_option' => null,
                ],
            ],
        ];

        HttpRoute::assertRouteMapMiddlewareValid($routeMap);
        $this->addToAssertionCount(1);
    }

    /**
     * 验证：仅 __invoke 的 invokable 中间件可通过校验。
     */
    public function testInvokableMiddlewarePassesValidation(): void
    {
        $routeMap = [
            '/api/ping' => [
                'GET' => [
                    'group_meta' => [],
                    'route_meta' => [
                        InvokableRouteMiddleware::class,
                        'dispatch_route' => ['App\\Controller\\IndexController', 'index'],
                    ],
                    'route_option' => null,
                ],
            ],
        ];

        HttpRoute::assertRouteMapMiddlewareValid($routeMap);
        $this->addToAssertionCount(1);
    }

    /**
     * 验证：Route::loadRouteFile 在路由表含非法中间件时失败。
     */
    public function testLoadRouteFileFailsOnInvalidMiddleware(): void
    {
        $this->clearRouteMap();
        Route::get('/bad-route', [
            'dispatch_route' => ['App\\Controller\\IndexController', 'index'],
            'auth' => ['Not\\A\\Real\\Middleware'],
        ]);

        $this->expectException(InvalidMiddlewareException::class);
        $this->expectExceptionMessage('Not\\A\\Real\\Middleware');

        Route::loadRouteFile();
    }

    /**
     * 验证：Route::loadRouteFile 对合法中间件路由表校验通过。
     */
    public function testLoadRouteFilePassesForValidMiddleware(): void
    {
        $this->clearRouteMap();
        Route::get('/ok-route', [
            ValidLoginMiddleware::class,
            'dispatch_route' => ['App\\Controller\\IndexController', 'index'],
        ]);

        $map = Route::loadRouteFile();
        $this->assertArrayHasKey('/ok-route', $map);
        $this->assertArrayHasKey('GET', $map['/ok-route']);
    }

    /**
     * 验证：runMiddlewares 不再静默跳过不存在的中间件类。
     */
    public function testRunMiddlewaresThrowsForMissingClass(): void
    {
        $route = (new \ReflectionClass(HttpRoute::class))->newInstanceWithoutConstructor();
        $requestInput = $this->createMock(RequestInput::class);
        $responseOutput = $this->createMock(ResponseOutput::class);

        $requestInputProp = new ReflectionProperty(HttpRoute::class, 'requestInput');
        $requestInputProp->setAccessible(true);
        $requestInputProp->setValue($route, $requestInput);

        $responseOutputProp = new ReflectionProperty(HttpRoute::class, 'responseOutput');
        $responseOutputProp->setAccessible(true);
        $responseOutputProp->setValue($route, $responseOutput);

        $method = new \ReflectionMethod(HttpRoute::class, 'runMiddlewares');
        $method->setAccessible(true);

        $this->expectException(InvalidMiddlewareException::class);
        $this->expectExceptionMessage('middleware class not found');

        $method->invoke($route, ['Missing\\Middleware\\Class'], 'GET');
    }

    private function clearRouteMap(): void
    {
        $prop = new ReflectionProperty(Route::class, 'routeMap');
        $prop->setAccessible(true);
        $prop->setValue(null, null);
    }
}

/**
 * 仅用于 invokable 中间件校验用例。
 */
final class InvokableRouteMiddleware
{
    public function __invoke(RequestInput $requestInput, ResponseOutput $responseOutput): bool
    {
        return true;
    }
}
