<?php

declare(strict_types=1);

namespace PHPUintTest\Coroutine\Support\Auth;

use Swoole\Coroutine\Channel;
use Swoolefy\Core\Coroutine\Context;
use Swoolefy\Support\Auth\AuthUser;
use Swoolefy\Support\FrameworkContext;
use Swoolefy\Support\HeaderPropagation\HeaderContext;
use Swoolefy\Support\HeaderPropagation\HeaderPropagator;
use PHPUintTest\CoroutineTestCase;

/**
 * Auth 协程透传：FrameworkContext 优先级、Context 存 array、goApp 子协程可读 getUserId。
 */
final class AuthContextGoAppTest extends CoroutineTestCase
{
    protected function tearDown(): void
    {
        $this->runInCoroutine(static function (): void {
            FrameworkContext::clearUser();
            HeaderContext::clear();
        });
        parent::tearDown();
    }

    public function testFrameworkContextAuthPriorityAndHeaderFallback(): void
    {
        $this->runInCoroutine(function (): void {
            HeaderContext::clear();
            FrameworkContext::clearUser();

            HeaderContext::put(HeaderPropagator::HEADER_USER_ID, 'from-header');
            $this->assertSame('from-header', FrameworkContext::getUserId());

            FrameworkContext::setUser(new AuthUser(userId: 'from-auth', roles: ['operator'], tenantId: 'ten'));
            $this->assertSame('from-auth', FrameworkContext::getUserId());
            $this->assertSame('ten', FrameworkContext::getTenantId());
            $this->assertSame('from-auth', HeaderContext::get(HeaderPropagator::HEADER_USER_ID));
            $this->assertTrue(FrameworkContext::check());

            FrameworkContext::clearUser();
            $this->assertSame('from-auth', FrameworkContext::getUserId());

            HeaderContext::clear();
        });
    }

    public function testGoAppPropagatesAuthUserArray(): void
    {
        $this->runInCoroutine(function (): void {
            HeaderContext::clear();
            FrameworkContext::clearUser();
            FrameworkContext::setUser(new AuthUser(userId: 'go-user', roles: ['operator']));

            $childId = null;
            $wait = new Channel(1);
            goApp(static function () use (&$childId, $wait): void {
                $childId = FrameworkContext::getUserId();
                $wait->push(true);
            });
            $wait->pop(2);

            $this->assertSame('go-user', $childId);
        });
    }

    public function testAuthUserStoredAsArrayInContext(): void
    {
        $this->runInCoroutine(function (): void {
            HeaderContext::clear();
            FrameworkContext::clearUser();
            FrameworkContext::setUser(new AuthUser(userId: 'arr-only'));

            $raw = Context::get('__swoolefy_auth_user');
            $this->assertIsArray($raw);
            $this->assertSame('arr-only', $raw['userId'] ?? null);
        });
    }
}
