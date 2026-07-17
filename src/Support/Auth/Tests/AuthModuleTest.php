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

/**
 * Auth 模块验收测试（对应 docs/Auth.md Phase 1 验收焦点）。
 *
 * ## 覆盖
 * | 用例 | 要点 |
 * |------|------|
 * | JwtAuthGuard | claim 映射、空 token→null、非法 token→401、generateToken 往返 |
 * | FrameworkContext | Auth 优先 / Header 兜底、setUser 回写透传头 |
 * | goApp | Context 存 array，子协程可读 getUserId |
 * | Context 形态 | swoolefy_auth_user 必须是 array 非 object |
 *
 * ## 运行环境
 * 须在 Swoole 协程内执行（文件末尾 `Coroutine\run`），因为 HeaderContext /
 * FrameworkContext / goApp 都依赖协程 Context。
 *
 * ```bash
 * php src/Support/Auth/Tests/AuthModuleTest.php
 * # 或
 * composer test:auth
 * ```
 */

use Swoolefy\Support\Auth\AuthException;
use Swoolefy\Support\Auth\AuthUser;
use Swoolefy\Support\Auth\JwtAuthGuard;
use Swoolefy\Support\FrameworkContext;
use Swoolefy\Support\HeaderPropagation\HeaderContext;
use Swoolefy\Support\HeaderPropagation\HeaderPropagator;

// vendor + Test 应用常量（APP_PATH / CONFIG_PATH 等），与其它 Support 单测一致
require dirname(__DIR__, 4) . '/vendor/autoload.php';
require dirname(__DIR__, 2) . '/Tests/SwoolefyTestBootstrap.php';

/**
 * 断言失败则抛 RuntimeException，由底部 runner 捕获并标记 [FAIL]。
 *
 * @param bool   $condition 为 false 时失败
 * @param string $message   失败原因（写入异常 message）
 */
function assertTrue(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

/**
 * 用例通过时打印 [PASS]，便于 CLI 逐条观察进度。
 */
function pass(string $name): void
{
    echo "[PASS] {$name}\n";
}

/**
 * 构造与 Test/Config/auth.php 对齐的 JWT Guard 配置（密钥与签发侧一致）。
 *
 * - issuer / audience 置空：本测试不校验 iss/aud，只验签名与 exp
 * - id_claim=uid：与 issueTestJwt 写入的 claim 名一致
 *
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
function jwtTestConfig(): array
{
    return [
        // 须与 issueTestJwt 签名密钥相同，否则 SignedWith 失败
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
 * 经 JwtAuthGuard::generateToken 签发测试 JWT（与 authenticate 同一路径）。
 *
 * @param string        $uid      → AuthUser.userId / claim uid+sub
 * @param list<string>  $roles    → AuthUser.roles
 * @param string|null   $tenantId 非 null 时 → AuthUser.tenantId
 * @param int           $ttl      过期秒数
 */
function issueTestJwt(string $uid, array $roles = [], ?string $tenantId = null, int $ttl = 3600): string
{
    $guard = new JwtAuthGuard(jwtTestConfig());

    return $guard->generateToken(
        new AuthUser(userId: $uid, roles: $roles, tenantId: $tenantId),
        $ttl,
    );
}

/**
 * 验证：合法 JWT 经 JwtAuthGuard 后，claim 正确映射到 AuthUser 字段。
 *
 * 覆盖：userId / roles(hasRole) / tenantId / via=jwt。
 */
function testJwtAuthGuardMapsClaims(): void
{
    $guard = new JwtAuthGuard(jwtTestConfig());
    // uid=100, roles 含 admin, tenant_id=t1
    $user = $guard->authenticate(['token' => issueTestJwt('100', ['operator', 'admin'], 't1')]);

    assertTrue($user instanceof AuthUser, 'user');
    assertTrue($user->userId === '100', 'userId');
    assertTrue($user->hasRole('admin'), 'admin role');
    assertTrue($user->tenantId === 't1', 'tenant');
    // 默认 Guard 通道标记，便于审计区分 api_key / system
    assertTrue($user->via === 'jwt', 'via');

    pass('jwt auth guard maps claims');
}

/**
 * 验证：空 token 返回 null（匿名），不抛异常。
 *
 * 约定：null 由中间件决定——强制登录 → 401，可选登录 → 放行。
 */
function testJwtAuthGuardEmptyTokenReturnsNull(): void
{
    $guard = new JwtAuthGuard(jwtTestConfig());
    assertTrue($guard->authenticate(['token' => '']) === null, 'empty null');

    pass('jwt empty token null');
}

/**
 * 验证：非空但非法 JWT 抛 AuthException，且 HTTP code=401。
 *
 * 有凭证却校验失败时，绝不能当匿名 null 放行。
 */
function testJwtAuthGuardRejectsBadToken(): void
{
    $guard = new JwtAuthGuard(jwtTestConfig());
    try {
        $guard->authenticate(['token' => 'not.a.jwt']);
        // 未抛异常则失败
        assertTrue(false, 'should throw');
    } catch (AuthException $e) {
        // App 层按 getCode() 写回响应，须为 401 而非 0/500
        assertTrue($e->getCode() === 401, '401');
    }

    pass('jwt rejects bad token');
}

/**
 * 验证：已过期 JWT 抛 AuthException，文案为 Token expired，code=401。
 */
function testJwtAuthGuardRejectsExpiredToken(): void
{
    $guard = new JwtAuthGuard(jwtTestConfig());
    // ttl=1 后 sleep，确保 isExpired 为 true
    $token = $guard->generateToken(new AuthUser(userId: 'exp-user'), 1);
    sleep(2);

    try {
        $guard->authenticate(['token' => $token]);
        assertTrue(false, 'should throw');
    } catch (AuthException $e) {
        assertTrue($e->getCode() === 401, '401');
        assertTrue($e->getMessage() === 'Token expired', 'expired message');
    }

    pass('jwt rejects expired token');
}

/**
 * 验证：generateToken → authenticate 往返，字段与签发侧一致。
 */
function testJwtAuthGuardGenerateTokenRoundTrip(): void
{
    $guard = new JwtAuthGuard(jwtTestConfig());
    $issued = new AuthUser(
        userId: '42',
        roles: ['operator', 'admin'],
        tenantId: 'acme',
        claims: ['dept' => 'ops'],
    );

    $token = $guard->generateToken($issued, 1800);
    assertTrue($token !== '', 'token non-empty');

    $user = $guard->authenticate(['token' => $token]);
    assertTrue($user instanceof AuthUser, 'user');
    assertTrue($user->userId === '42', 'userId');
    assertTrue($user->hasRole('admin'), 'admin');
    assertTrue($user->tenantId === 'acme', 'tenant');
    assertTrue(($user->claims['dept'] ?? null) === 'ops', 'extra claim');
    assertTrue($user->via === 'jwt', 'via');

    pass('jwt generateToken round-trip');
}

/**
 * 验证：getUserId / getTenantId 的优先级与 setUser 副作用。
 *
 * 优先级：AuthUser（setUser） > HeaderContext 透传头 > default。
 * setUser 还会把 userId/tenantId 回写到白名单头，供出站传播。
 */
function testFrameworkContextAuthPriorityAndHeaderFallback(): void
{
    // 隔离：避免同进程其它用例残留身份 / 透传头
    HeaderContext::clear();
    FrameworkContext::clearUser();

    // ① 未 setUser：仅透传头 → 与改造前内部服务行为兼容
    HeaderContext::put(HeaderPropagator::HEADER_USER_ID, 'from-header');
    assertTrue(FrameworkContext::getUserId() === 'from-header', 'header fallback');

    // ② setUser 后：Auth 优先，覆盖半可信 Header
    FrameworkContext::setUser(new AuthUser(userId: 'from-auth', roles: ['operator'], tenantId: 'ten'));
    assertTrue(FrameworkContext::getUserId() === 'from-auth', 'auth priority');
    assertTrue(FrameworkContext::getTenantId() === 'ten', 'tenant auth');
    // setUser 同步回写 x-user-id，出站 HeaderPropagator 与登录态一致
    assertTrue(HeaderContext::get(HeaderPropagator::HEADER_USER_ID) === 'from-auth', 'header rewritten');
    assertTrue(FrameworkContext::check(), 'check');

    // ③ clearUser 只删 Context 快照，不回滚已写入的 Header（请求结束由 HttpServer defer 清）
    FrameworkContext::clearUser();
    assertTrue(FrameworkContext::getUserId() === 'from-auth', 'header still after clearUser');

    HeaderContext::clear();
    pass('framework context auth priority');
}

/**
 * 验证：goApp 子协程能读到父协程 setUser 的身份（依赖 Context 存 array）。
 *
 * goApp 拷贝 Context 时跳过 object；若误存 AuthUser 对象，子协程 getUserId 会为 null。
 */
function testGoAppPropagatesAuthUserArray(): void
{
    HeaderContext::clear();
    FrameworkContext::clearUser();
    FrameworkContext::setUser(new AuthUser(userId: 'go-user', roles: ['operator']));

    $childId = null;
    // Channel：父协程等待子协程写完再断言（避免竞态）
    $wait = new Swoole\Coroutine\Channel(1);
    goApp(static function () use (&$childId, $wait): void {
        // 子协程内应能还原 Auth 快照
        $childId = FrameworkContext::getUserId();
        $wait->push(true);
    });
    // 超时 2s，防止子协程未启动导致死等
    $wait->pop(2);

    assertTrue($childId === 'go-user', 'goApp sees user id');

    FrameworkContext::clearUser();
    HeaderContext::clear();
    pass('goApp propagates auth array');
}

/**
 * 验证：FrameworkContext::setUser 在协程 Context 中写入的是 array，不是 AuthUser 对象。
 *
 * 键名固定为 __swoolefy_auth_user；结构须含 userId，与 AuthUser::toArray() 一致。
 */
function testAuthUserObjectNotStoredInContext(): void
{
    HeaderContext::clear();
    FrameworkContext::clearUser();
    FrameworkContext::setUser(new AuthUser(userId: 'arr-only'));

    // 直接读底层 Context，确认形态（而非只测 user() 能还原）
    $raw = Swoolefy\Core\Coroutine\Context::get('__swoolefy_auth_user');
    assertTrue(is_array($raw), 'context stores array');
    assertTrue(($raw['userId'] ?? '') === 'arr-only', 'userId in array');

    FrameworkContext::clearUser();
    pass('auth user stored as array');
}

// ---------------------------------------------------------------------------
// 用例注册表：name => 函数名（便于 [FAIL] 日志定位）
// ---------------------------------------------------------------------------
$tests = [
    'jwt maps claims' => 'testJwtAuthGuardMapsClaims',
    'jwt empty null' => 'testJwtAuthGuardEmptyTokenReturnsNull',
    'jwt bad token' => 'testJwtAuthGuardRejectsBadToken',
    'jwt expired token' => 'testJwtAuthGuardRejectsExpiredToken',
    'jwt generateToken round-trip' => 'testJwtAuthGuardGenerateTokenRoundTrip',
    'framework context priority' => 'testFrameworkContextAuthPriorityAndHeaderFallback',
    'goApp propagate' => 'testGoAppPropagatesAuthUserArray',
    'context array only' => 'testAuthUserObjectNotStoredInContext',
];

/**
 * 入口：在协程调度器内顺序跑完全部用例。
 * 任一断言失败 → stderr 打 [FAIL] 并 exit(1)；全部通过 → 打印汇总行。
 */
Swoole\Coroutine\run(static function () use ($tests): void {
    foreach ($tests as $name => $fn) {
        try {
            $fn();
        } catch (Throwable $e) {
            fwrite(STDERR, "[FAIL] {$name}: {$e->getMessage()}\n");
            exit(1);
        }
    }
    echo "All Auth module tests passed.\n";
});
