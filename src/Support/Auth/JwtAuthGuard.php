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

use DateTimeImmutable;
use DateTimeZone;
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
 * 默认 JWT Guard：复用 `Swoolefy\Library\Jwt\*`，签发与解析共用同一配置。
 *
 * ## 校验项（authenticate）
 * 1. HMAC 签名（SignedWith）
 * 2. 过期时间 exp（`Token::isExpired`，失败文案 `Token expired`）
 * 3. nbf / iat（ValidAt）及可选 iss / aud（配置非空时才加约束）
 *
 * ## Claim ↔ AuthUser（键名可配，见 Config/auth.php）
 * | Claim | AuthUser |
 * |-------|----------|
 * | id_claim（默认 uid），否则 sub | userId |
 * | roles_claim（默认 roles）数组或逗号串 | roles |
 * | tenant_claim（默认 tenant_id） | tenantId |
 * | 其余 | claims |
 * | — | via = jwt（仅解析侧写入） |
 *
 * ## 配置来源
 * 构造参数为 `Config/auth.php` 的 `jwt` 段；密钥禁止硬编码在业务控制器。
 * 组件注册：`Test/Config/component/auth.php` → `auth.guard`。
 *
 * 若需自定义，可另写实现类并替换组件注册，例如：
 * ```php
 * 'auth.guard' => static function () {
 *     $config = include APP_PATH . '/Config/auth.php';
 *     return new NewJwtAuthGuard($config['jwt'] ?? []);
 * },
 * ```
 *
 * @see AuthGuardInterface
 * @see docs/Auth.md
 */
final class JwtAuthGuard implements AuthGuardInterface
{
    /**
     * @param array<string, mixed> $jwtConfig auth.php 中 jwt 段
     *        secret / algo / ttl_seconds / issuer / audience / id_claim / roles_claim / tenant_claim
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

        $configuration = $this->jwtConfiguration();

        try {
            $parsed = $configuration->parser()->parse($token);
        } catch (\Throwable $e) {
            // 结构损坏、非 JWT 等
            throw new AuthException('Parse Token Error', Status::UNAUTHORIZED, $e);
        }

        // 先验签：伪造 token 即使带过期 claim 也不应优先暴露「已过期」
        if (!$configuration->validator()->validate(
            $parsed,
            new SignedWith($configuration->signer(), $configuration->verificationKey()),
        )) {
            throw new AuthException('Invalid Token', Status::UNAUTHORIZED);
        }

        // 显式过期校验（与 Token::isExpired / ValidAt::assertExpiration 同一时钟基准）
        $now = new DateTimeImmutable('now', new DateTimeZone(date_default_timezone_get()));
        if ($parsed->isExpired($now)) {
            throw new AuthTokenExpirationException('token expired', Status::UNAUTHORIZED);
        }

        // nbf / iat；iss/aud 仅在配置非空时约束
        $constraints = [
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
            throw new AuthException('Invalid token', Status::UNAUTHORIZED);
        }

        $claims = $parsed->claims()->all();
        $idClaim = $this->idClaim();
        $rolesClaim = $this->rolesClaim();
        $tenantClaim = $this->tenantClaim();

        // 优先业务 uid，其次标准 sub
        $userId = (string) ($claims[$idClaim] ?? $claims[RegisteredClaims::SUBJECT] ?? '');
        if ($userId === '') {
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
            userId: $userId,
            roles: $roles,
            tenantId: $tenantId,
            claims: $extra,
            via: 'jwt',
        );
    }

    /**
     * {@inheritdoc}
     *
     * 使用与 authenticate 相同的 secret / algo / claim 名 / 可选 iss·aud。
     */
    public function generateToken(AuthUser $user, ?int $ttlSeconds = null): string
    {
        if ($user->userId === '') {
            throw new AuthException('AuthUser userId is required to generate token', Status::UNAUTHORIZED);
        }

        $configuration = $this->jwtConfiguration();
        $ttl = $ttlSeconds ?? (int) ($this->jwtConfig['ttl_seconds'] ?? 3600);
        if ($ttl <= 0) {
            throw new AuthException('JWT ttl_seconds must be positive', Status::UNAUTHORIZED);
        }

        $now = new DateTimeImmutable('now', new DateTimeZone(date_default_timezone_get()));
        $idClaim = $this->idClaim();
        $rolesClaim = $this->rolesClaim();
        $tenantClaim = $this->tenantClaim();

        $builder = $configuration->builder()
            ->issuedAt($now)
            ->expiresAt($now->modify('+' . $ttl . ' second'))
            // 标准 sub + 业务 id_claim 双写，authenticate 优先读 id_claim
            ->relatedTo($user->userId)
            ->withClaim($idClaim, $user->userId)
            ->withClaim($rolesClaim, array_values($user->roles));

        if ($user->tenantId !== null && $user->tenantId !== '') {
            $builder = $builder->withClaim($tenantClaim, $user->tenantId);
        }

        $issuer = (string) ($this->jwtConfig['issuer'] ?? '');
        if ($issuer !== '') {
            $builder = $builder->issuedBy($issuer);
        }

        $audience = (string) ($this->jwtConfig['audience'] ?? '');
        if ($audience !== '') {
            $builder = $builder->permittedFor($audience);
        }

        // 附加 claims；勿覆盖已映射的顶层字段
        $reserved = [$idClaim, RegisteredClaims::SUBJECT, $rolesClaim, $tenantClaim];
        foreach ($user->claims as $key => $value) {
            $name = (string) $key;
            if ($name === '' || in_array($name, $reserved, true)) {
                continue;
            }
            $builder = $builder->withClaim($name, $value);
        }

        try {
            return $builder
                ->getToken($configuration->signer(), $configuration->signingKey())
                ->toString();
        } catch (\Throwable $e) {
            throw new AuthException('Failed to generate token', Status::UNAUTHORIZED, $e);
        }
    }

    /** 构建对称签名 Configuration；secret 为空则 401。 */
    private function jwtConfiguration(): Configuration
    {
        $secret = (string) ($this->jwtConfig['secret'] ?? '');
        if ($secret === '') {
            throw new AuthException('AUTH_JWT_SECRET is not configured', Status::UNAUTHORIZED);
        }

        return Configuration::forSymmetricSigner(
            $this->resolveSigner(),
            InMemory::plainText($secret),
        );
    }

    private function idClaim(): string
    {
        return (string) ($this->jwtConfig['id_claim'] ?? 'uid');
    }

    private function rolesClaim(): string
    {
        return (string) ($this->jwtConfig['roles_claim'] ?? 'roles');
    }

    private function tenantClaim(): string
    {
        return (string) ($this->jwtConfig['tenant_claim'] ?? 'tenant_id');
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
