<?php

declare(strict_types=1);

namespace PhpUintTest\Unit\Support\Auth;

use Swoolefy\Support\Auth\AuthException;
use Swoolefy\Support\Auth\AuthUser;
use Swoolefy\Support\Auth\JwtAuthGuard;
use PhpUintTest\TestCase;

/**
 * Auth 模块 JWT Guard 验收（goApp / Context 形态见 Coroutine AuthContextGoAppTest）。
 */
final class AuthModuleTest extends TestCase
{
    /**
     * @return array{
     *     secret: string,
     *     algo: string,
     *     id_claim: string,
     *     roles_claim: string,
     *     tenant_claim: string,
     *     issuer: string,
     *     audience: string
     * }
     */
    private function jwtTestConfig(): array
    {
        return [
            'secret' => 'test-auth-jwt-secret-change-me',
            'algo' => 'HS256',
            'id_claim' => 'uid',
            'roles_claim' => 'roles',
            'tenant_claim' => 'tenant_id',
            'issuer' => '',
            'audience' => '',
        ];
    }

    /**
     * @param list<string> $roles
     */
    private function issueTestJwt(string $uid, array $roles = [], ?string $tenantId = null, int $ttl = 3600): string
    {
        $guard = new JwtAuthGuard($this->jwtTestConfig());

        return $guard->generateToken(
            new AuthUser(userId: $uid, roles: $roles, tenantId: $tenantId),
            $ttl,
        );
    }

    /**
     * 验证：JwtAuthGuard 解析有效 JWT 后正确映射 userId、角色、租户及 via 来源标识。
     */
    public function testJwtAuthGuardMapsClaims(): void
    {
        $guard = new JwtAuthGuard($this->jwtTestConfig());
        $user = $guard->authenticate(['token' => $this->issueTestJwt('100', ['operator', 'admin'], 't1')]);

        $this->assertInstanceOf(AuthUser::class, $user);
        $this->assertSame('100', $user->userId);
        $this->assertTrue($user->hasRole('admin'));
        $this->assertSame('t1', $user->tenantId);
        $this->assertSame('jwt', $user->via);
    }

    /**
     * 验证：空 token 传入 authenticate 时返回 null，不抛异常。
     */
    public function testJwtAuthGuardEmptyTokenReturnsNull(): void
    {
        $guard = new JwtAuthGuard($this->jwtTestConfig());
        $this->assertNull($guard->authenticate(['token' => '']));
    }

    /**
     * 验证：非法 JWT 字符串被拒绝并抛出 HTTP 401 的 AuthException。
     */
    public function testJwtAuthGuardRejectsBadToken(): void
    {
        $guard = new JwtAuthGuard($this->jwtTestConfig());
        try {
            $guard->authenticate(['token' => 'not.a.jwt']);
            $this->fail('should throw');
        } catch (AuthException $e) {
            $this->assertSame(401, $e->getCode());
        }
    }

    /**
     * 验证：超过 TTL 的过期 token 被拒绝，返回 401 及 "Token expired" 消息。
     */
    public function testJwtAuthGuardRejectsExpiredToken(): void
    {
        $guard = new JwtAuthGuard($this->jwtTestConfig());
        $token = $guard->generateToken(new AuthUser(userId: 'exp-user'), 1);
        sleep(2);

        try {
            $guard->authenticate(['token' => $token]);
            $this->fail('should throw');
        } catch (AuthException $e) {
            $this->assertSame(401, $e->getCode());
            $this->assertSame('Token expired', $e->getMessage());
        }
    }

    /**
     * 验证：generateToken 签发的 JWT 可往返认证，自定义 claims 与角色完整保留。
     */
    public function testJwtAuthGuardGenerateTokenRoundTrip(): void
    {
        $guard = new JwtAuthGuard($this->jwtTestConfig());
        $issued = new AuthUser(
            userId: '42',
            roles: ['operator', 'admin'],
            tenantId: 'acme',
            claims: ['dept' => 'ops'],
        );

        $token = $guard->generateToken($issued, 1800);
        $this->assertNotSame('', $token);

        $user = $guard->authenticate(['token' => $token]);
        $this->assertInstanceOf(AuthUser::class, $user);
        $this->assertSame('42', $user->userId);
        $this->assertTrue($user->hasRole('admin'));
        $this->assertSame('acme', $user->tenantId);
        $this->assertSame('ops', $user->claims['dept'] ?? null);
        $this->assertSame('jwt', $user->via);
    }
}
