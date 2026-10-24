<?php

declare(strict_types=1);

namespace PHPUintTest\Unit\Support\Auth;

use Swoolefy\Library\Jwt\Token\RegisteredClaimGiven;
use Swoolefy\Support\Auth\AuthException;
use Swoolefy\Support\Auth\AuthUser;
use Swoolefy\Support\Auth\JwtAuthGuard;
use PHPUintTest\TestCase;

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
        $this->assertFalse($user->rolesResolved);
        $this->assertSame([], $user->roles);
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
     * 验证：generateToken 签发的 JWT 可往返认证。角色不写入 token，验票后角色仍未加载。
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
        $payload = $this->decodePayload($token);
        $this->assertArrayNotHasKey('roles', $payload);

        $user = $guard->authenticate(['token' => $token]);
        $this->assertInstanceOf(AuthUser::class, $user);
        $this->assertSame('42', $user->userId);
        $this->assertFalse($user->rolesResolved);
        $this->assertSame('acme', $user->tenantId);
        $this->assertSame('ops', $user->claims['dept'] ?? null);
        $this->assertSame('jwt', $user->via);
    }

    /**
     * 验证：authenticate 不把 JWT 注册声明复制进 AuthUser::claims。
     */
    public function testJwtAuthGuardClaimsExcludeRegisteredClaims(): void
    {
        $guard = new JwtAuthGuard($this->jwtTestConfig());
        $token = $guard->generateToken(new AuthUser(userId: '7', claims: ['dept' => 'ops']));

        $user = $guard->authenticate(['token' => $token]);

        $this->assertInstanceOf(AuthUser::class, $user);
        $this->assertSame(['dept' => 'ops'], $user->claims);
    }

    /**
     * 验证：authenticate 得到的 AuthUser 可直接用于刷新签发，不再因 iat/exp 抛 RegisteredClaimGiven。
     */
    public function testJwtAuthGuardCanReissueAuthenticatedUser(): void
    {
        $guard = new JwtAuthGuard($this->jwtTestConfig());
        $authed = $guard->authenticate([
            'token' => $guard->generateToken(new AuthUser(userId: '88', claims: ['dept' => 'ops'])),
        ]);

        $refreshed = $guard->generateToken($authed);
        $payload = $this->decodePayload($refreshed);

        $this->assertSame('88', $payload['uid'] ?? null);
        $this->assertSame('88', $payload['sub'] ?? null);
        $this->assertSame('ops', $payload['dept'] ?? null);
        $this->assertArrayHasKey('iat', $payload);
        $this->assertArrayHasKey('exp', $payload);
    }

    /**
     * 验证：业务 claims 与注册声明重名时忽略，标准字段只由签发参数决定。
     */
    public function testGenerateTokenIgnoresRegisteredClaimNamesInClaims(): void
    {
        $guard = new JwtAuthGuard($this->jwtTestConfig());
        $token = $guard->generateToken(new AuthUser(
            userId: '9',
            claims: ['jti' => 'forged-jti', 'nbf' => 1234567890, 'aud' => 'forged-aud', 'dept' => 'ops'],
        ));

        $payload = $this->decodePayload($token);

        $this->assertArrayNotHasKey('jti', $payload);
        $this->assertArrayNotHasKey('nbf', $payload);
        $this->assertArrayNotHasKey('aud', $payload);
        $this->assertSame('ops', $payload['dept'] ?? null);
    }

    /**
     * 验证：id_claim 配成标准 sub 时仍可签发与解析（不得重复写入注册声明）。
     */
    public function testGenerateTokenSupportsSubAsIdClaim(): void
    {
        $guard = new JwtAuthGuard(array_replace($this->jwtTestConfig(), ['id_claim' => 'sub']));
        $token = $guard->generateToken(new AuthUser(userId: '42'));
        $payload = $this->decodePayload($token);

        $this->assertSame('42', $payload['sub'] ?? null);
        $this->assertArrayNotHasKey('uid', $payload);
        $this->assertSame('42', $guard->authenticate(['token' => $token])?->userId);
    }

    /**
     * 验证：映射 claim 配成其它注册声明时，生成失败统一包装成 AuthException。
     */
    public function testGenerateTokenWrapsInvalidClaimMapping(): void
    {
        $guard = new JwtAuthGuard(array_replace($this->jwtTestConfig(), ['id_claim' => 'jti']));

        try {
            $guard->generateToken(new AuthUser(userId: '1'));
            $this->fail('should throw');
        } catch (AuthException $e) {
            $this->assertSame(401, $e->getCode());
            $this->assertSame('Failed to generate token', $e->getMessage());
            $this->assertInstanceOf(RegisteredClaimGiven::class, $e->getPrevious());
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function decodePayload(string $token): array
    {
        $payload = json_decode(base64_decode(strtr(explode('.', $token)[1], '-_', '+/')), true);
        $this->assertIsArray($payload);

        return $payload;
    }
}
