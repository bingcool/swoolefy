<?php

declare(strict_types=1);

namespace PhpUintTest\Unit\Http;

use PhpUintTest\Support\HttpRequestHarness;
use PhpUintTest\TestCase;
use Swoole\Coroutine;
use Swoole\Http\Status;
use Swoolefy\Core\App;
use Swoolefy\Core\Application;
use Swoolefy\Core\BaseServer;
use Swoolefy\Http\Middleware\OptionalAuthenticateMiddleware;
use Swoolefy\Support\Auth\AuthException;
use Swoolefy\Support\Auth\AuthGuardInterface;
use Swoolefy\Support\Auth\AuthUser;
use Swoolefy\Support\FrameworkContext;
use Swoolefy\Support\HeaderPropagation\HeaderContext;

/**
 * 阶段三 5.7（审计项 48）：Optional Auth 只放行缺失 Token。
 * 目标：无 Token 匿名；合法 Token 设用户；过期/伪造/null/Guard 异常不得降级匿名。
 */
final class OptionalAuthenticateMiddlewareTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        BaseServer::setAppConf(['components' => []]);
    }

    /**
     * 测无 Authorization 时 Optional 匿名放行，且不写入 FrameworkContext 用户。
     * 对应问题：缺失 Token 才是 Optional 唯一放行路径。
     */
    public function testMissingTokenAllowsAnonymous(): void
    {
        $this->runWithGuard($this->guardReturning(null), function (): void {
            $mw = new OptionalAuthenticateMiddleware();
            $ok = $mw->handle(
                HttpRequestHarness::requestInput('GET', '/api/optional'),
                HttpRequestHarness::responseOutput('GET', '/api/optional'),
            );
            $this->assertTrue($ok);
            $this->assertNull(FrameworkContext::user());
        });
    }

    /**
     * 测合法 Bearer 验票成功后 setUser，userId 可读。
     */
    public function testValidTokenSetsUser(): void
    {
        $user = new AuthUser(userId: 'u-ok', roles: ['user']);
        $this->runWithGuard($this->guardReturning($user), function () use ($user): void {
            $mw = new OptionalAuthenticateMiddleware();
            $mw->handle(
                HttpRequestHarness::requestInput('GET', '/api/optional', [], [], [
                    'authorization' => 'Bearer good.token',
                ]),
                HttpRequestHarness::responseOutput('GET', '/api/optional'),
            );
            $this->assertSame('u-ok', FrameworkContext::user()?->userId);
            $this->assertSame($user->userId, FrameworkContext::userOrFail()->userId);
        });
    }

    /**
     * 测过期 Token：Guard 抛 AuthException(401)，Optional 不得匿名放行。
     */
    public function testExpiredTokenReturns401(): void
    {
        $guard = $this->guardThrowing(new AuthException('Token expired', Status::UNAUTHORIZED));
        $this->runWithGuard($guard, function (): void {
            $mw = new OptionalAuthenticateMiddleware();
            try {
                $mw->handle(
                    HttpRequestHarness::requestInput('GET', '/api/optional', [], [], [
                        'authorization' => 'Bearer expired.token',
                    ]),
                    HttpRequestHarness::responseOutput('GET', '/api/optional'),
                );
                $this->fail('expected AuthException');
            } catch (AuthException $e) {
                $this->assertSame(Status::UNAUTHORIZED, $e->getCode());
                $this->assertSame('Token expired', $e->getMessage());
                $this->assertNull(FrameworkContext::user());
            }
        });
    }

    /**
     * 测伪造 Token：同样 401，禁止当匿名继续。
     */
    public function testForgedTokenReturns401(): void
    {
        $guard = $this->guardThrowing(new AuthException('Invalid Token', Status::UNAUTHORIZED));
        $this->runWithGuard($guard, function (): void {
            $mw = new OptionalAuthenticateMiddleware();
            try {
                $mw->handle(
                    HttpRequestHarness::requestInput('GET', '/api/optional', [], [], [
                        'authorization' => 'Bearer forged.token',
                    ]),
                    HttpRequestHarness::responseOutput('GET', '/api/optional'),
                );
                $this->fail('expected AuthException');
            } catch (AuthException $e) {
                $this->assertSame(Status::UNAUTHORIZED, $e->getCode());
                $this->assertNull(FrameworkContext::user());
            }
        });
    }

    /**
     * 测已携带 Token 但 Guard 返回 null：仍 401（旧实现 Optional 会匿名放行）。
     */
    public function testPresentTokenNullUserStillUnauthorized(): void
    {
        $this->runWithGuard($this->guardReturning(null), function (): void {
            $mw = new OptionalAuthenticateMiddleware();
            try {
                $mw->handle(
                    HttpRequestHarness::requestInput('GET', '/api/optional', [], [], [
                        'authorization' => 'Bearer present-but-null',
                    ]),
                    HttpRequestHarness::responseOutput('GET', '/api/optional'),
                );
                $this->fail('expected AuthException');
            } catch (AuthException $e) {
                $this->assertSame(Status::UNAUTHORIZED, $e->getCode());
                $this->assertSame('Unauthenticated', $e->getMessage());
            }
        });
    }

    /**
     * 测 Guard 内部非 AuthException：映射 500，禁止 Optional 降级匿名。
     */
    public function testGuardInternalErrorMapsTo500(): void
    {
        $guard = $this->guardThrowing(new \RuntimeException('redis down'));
        $this->runWithGuard($guard, function (): void {
            $mw = new OptionalAuthenticateMiddleware();
            try {
                $mw->handle(
                    HttpRequestHarness::requestInput('GET', '/api/optional', [], [], [
                        'authorization' => 'Bearer any.token',
                    ]),
                    HttpRequestHarness::responseOutput('GET', '/api/optional'),
                );
                $this->fail('expected AuthException');
            } catch (AuthException $e) {
                $this->assertSame(Status::INTERNAL_SERVER_ERROR, $e->getCode());
                $this->assertSame('Auth guard error', $e->getMessage());
                $this->assertInstanceOf(\RuntimeException::class, $e->getPrevious());
                $this->assertNull(FrameworkContext::user());
            }
        });
    }

    /**
     * 测请求结束 clearUser 可清掉本地验票快照（与 HttpServer defer 行为对齐）。
     */
    public function testClearUserRemovesSnapshotAfterRequest(): void
    {
        $this->runWithGuard($this->guardReturning(new AuthUser(userId: 'u-clear')), function (): void {
            $mw = new OptionalAuthenticateMiddleware();
            $mw->handle(
                HttpRequestHarness::requestInput('GET', '/api/optional', [], [], [
                    'authorization' => 'Bearer good.token',
                ]),
                HttpRequestHarness::responseOutput('GET', '/api/optional'),
            );
            $this->assertNotNull(FrameworkContext::user());
            FrameworkContext::clearUser();
            $this->assertNull(FrameworkContext::user());
        });
    }

    /**
     * @param callable(): void $assert
     */
    private function runWithGuard(AuthGuardInterface $guard, callable $assert): void
    {
        $error = null;
        Coroutine\run(function () use ($guard, $assert, &$error): void {
            try {
                $app = new App();
                Application::setApp($app);
                $app->creatObject('auth.guard', static fn () => $guard);
                $assert();
            } catch (\Throwable $e) {
                $error = $e;
            } finally {
                FrameworkContext::clearUser();
                HeaderContext::clear();
                Application::removeApp();
            }
        });
        if ($error instanceof \Throwable) {
            throw $error;
        }
    }

    private function guardReturning(?AuthUser $user): AuthGuardInterface
    {
        return new class($user) implements AuthGuardInterface {
            public function __construct(private readonly ?AuthUser $user)
            {
            }

            public function authenticate(array $credentials): ?AuthUser
            {
                return $this->user;
            }

            public function generateToken(AuthUser $user, ?int $ttlSeconds = null): string
            {
                return 'unused';
            }
        };
    }

    private function guardThrowing(\Throwable $throwable): AuthGuardInterface
    {
        return new class($throwable) implements AuthGuardInterface {
            public function __construct(private readonly \Throwable $throwable)
            {
            }

            public function authenticate(array $credentials): ?AuthUser
            {
                throw $this->throwable;
            }

            public function generateToken(AuthUser $user, ?int $ttlSeconds = null): string
            {
                return 'unused';
            }
        };
    }
}
