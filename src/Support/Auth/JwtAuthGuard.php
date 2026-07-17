<?php
/**
 * +----------------------------------------------------------------------
 * | swoolefy framework bases on swoole extension development, we can use it easily!
 * +----------------------------------------------------------------------
 * | Licensed ( https://opensource.org/licenses/MIT )
 * +----------------------------------------------------------------------
 * | @see https://github.com/bingcool/swoolefy
 * +----------------------------------------------------------------------
 */

declare(strict_types=1);

namespace Swoolefy\Support\Auth;

use Swoole\Http\Status;
use Swoolefy\Library\Clock\SystemClock;
use Swoolefy\Library\Jwt\Configuration;
use Swoolefy\Library\Jwt\Signer;
use Swoolefy\Library\Jwt\Signer\Hmac\Sha256;
use Swoolefy\Library\Jwt\Signer\Hmac\Sha384;
use Swoolefy\Library\Jwt\Signer\Hmac\Sha512;
use Swoolefy\Library\Jwt\Signer\Key\InMemory;
use Swoolefy\Library\Jwt\Token\RegisteredClaims;
use Swoolefy\Library\Jwt\Validation\Constraint\IssuedBy;
use Swoolefy\Library\Jwt\Validation\Constraint\PermittedFor;
use Swoolefy\Library\Jwt\Validation\Constraint\SignedWith;
use Swoolefy\Library\Jwt\Validation\Constraint\ValidAt;

/**
 * 默认 JWT Guard：复用 `Swoolefy\Library\Jwt\*`，不重造解析器。
 *
 * ## 校验项
 * 1. HMAC 签名（SignedWith）
 * 2. 过期时间 exp（ValidAt + 系统时区时钟）
 * 3. 可选 iss / aud（配置非空时才加约束）
 *
 * ## Claim → AuthUser 映射（键名可配，见 Config/auth.php）
 * | Claim | AuthUser |
 * |-------|----------|
 * | id_claim（默认 uid），否则 sub | userId |
 * | roles_claim（默认 roles）数组或逗号串 | roles |
 * | tenant_claim（默认 tenant_id） | tenantId |
 * | 其余 | claims |
 * | — | via = jwt |
 *
 * ## 配置来源
 * 构造参数为 `Config/auth.php` 的 `jwt` 段；密钥禁止硬编码在业务控制器。
 * 组件注册：`Test/Config/component/auth.php` → `auth.guard`。
 *
 * @see AuthGuardInterface
 * @see docs/Auth.md
 */
final class JwtAuthGuard implements AuthGuardInterface
{
    /**
     * @param array<string, mixed> $jwtConfig auth.php 中 jwt 段
     *        secret / algo / issuer / audience / id_claim / roles_claim / tenant_claim
     */
    public function __construct(
        private readonly array $jwtConfig = [],
    ) {
    }

    /**
     * {@inheritdoc}
     *
     * 空 token → null；密钥未配置或校验失败 → AuthException(401)。
     */
    public function authenticate(array $credentials): ?AuthUser
    {
        $token = trim((string) ($credentials['token'] ?? $credentials['bearer'] ?? ''));
        // 无凭证：交给中间件决定强制 401 还是匿名放行
        if ($token === '') {
            return null;
        }

        $secret = (string) ($this->jwtConfig['secret'] ?? '');
        if ($secret === '') {
            throw new AuthException('AUTH_JWT_SECRET is not configured', Status::UNAUTHORIZED);
        }

        $configuration = Configuration::forSymmetricSigner(
            $this->resolveSigner(),
            InMemory::plainText($secret),
        );

        try {
            $parsed = $configuration->parser()->parse($token);
        } catch (\Throwable $e) {
            // 结构损坏、非 JWT 等
            throw new AuthException('Invalid token', Status::UNAUTHORIZED, $e);
        }

        // 签名 + 过期；iss/aud 仅在配置了才约束（空串表示不校验）
        $constraints = [
            new SignedWith($configuration->signer(), $configuration->verificationKey()),
            new ValidAt(SystemClock::fromSystemTimezone()),
        ];

        $issuer = (string) ($this->jwtConfig['issuer'] ?? '');
        if ($issuer !== '') {
            $constraints[] = new IssuedBy($issuer);
        }

        $audience = (string) ($this->jwtConfig['audience'] ?? '');
        if ($audience !== '') {
            $constraints[] = new PermittedFor($audience);
        }

        if (!$configuration->validator()->validate($parsed, ...$constraints)) {
            throw new AuthException('Invalid or expired token', Status::UNAUTHORIZED);
        }

        $claims = $parsed->claims()->all();
        $idClaim = (string) ($this->jwtConfig['id_claim'] ?? 'uid');
        $rolesClaim = (string) ($this->jwtConfig['roles_claim'] ?? 'roles');
        $tenantClaim = (string) ($this->jwtConfig['tenant_claim'] ?? 'tenant_id');

        // 优先业务 uid，其次标准 sub
        $id = (string) ($claims[$idClaim] ?? $claims[RegisteredClaims::SUBJECT] ?? '');
        if ($id === '') {
            throw new AuthException('Token missing user id claim', Status::UNAUTHORIZED);
        }

        // roles 支持 JSON 数组或 "a,b,c" 字符串
        $rolesRaw = $claims[$rolesClaim] ?? [];
        if (is_string($rolesRaw)) {
            $roles = $rolesRaw === '' ? [] : array_values(array_filter(array_map('trim', explode(',', $rolesRaw))));
        } elseif (is_array($rolesRaw)) {
            $roles = array_values(array_map('strval', $rolesRaw));
        } else {
            $roles = [];
        }

        $tenantId = isset($claims[$tenantClaim]) && $claims[$tenantClaim] !== ''
            ? (string) $claims[$tenantClaim]
            : null;

        // 已映射字段不重复塞进 claims，避免业务二次解析混淆
        $reserved = [$idClaim, RegisteredClaims::SUBJECT, $rolesClaim, $tenantClaim];
        $extra = [];
        foreach ($claims as $key => $value) {
            if (!in_array((string) $key, $reserved, true)) {
                $extra[(string) $key] = $value;
            }
        }

        return new AuthUser(
            userId: $id,
            roles: $roles,
            tenantId: $tenantId,
            claims: $extra,
            via: 'jwt',
        );
    }

    /** 按配置 algo 选择 HMAC Signer；未知值回落 HS256。 */
    private function resolveSigner(): Signer
    {
        $algo = strtoupper((string) ($this->jwtConfig['algo'] ?? 'HS256'));

        return match ($algo) {
            'HS384' => new Sha384(),
            'HS512' => new Sha512(),
            default => new Sha256(),
        };
    }
}
