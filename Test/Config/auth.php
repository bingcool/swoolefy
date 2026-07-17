<?php

/**
 * Auth / JWT 配置（）。
 *
 * ## 环境变量
 * | 变量 | 说明 |
 * |------|------|
 * | AUTH_JWT_SECRET | HMAC 密钥；生产必填，勿用下方演示默认值 |
 * | AUTH_JWT_ALGO | HS256 / HS384 / HS512，默认 HS256 |
 * | AUTH_JWT_TTL | 签发侧建议 TTL（秒）；校验仍以 token 内 exp 为准 |
 * | AUTH_JWT_ISSUER | 非空则校验 iss |
 * | AUTH_JWT_AUDIENCE | 非空则校验 aud |
 *
 * ## claim 映射
 * id_claim / roles_claim / tenant_claim 对应 JwtAuthGuard 读取的 JWT 字段名。
 *
 * 组件加载：Config/component/auth.php → Application::get('auth.guard')
 * @see docs/Auth.md
 */

return [
    'jwt' => [
        // Test 默认密钥仅便于本地联调；生产务必 env 覆盖
        'secret' => env('AUTH_JWT_SECRET', 'test-auth-jwt-secret-change-me'),
        'algo' => env('AUTH_JWT_ALGO', 'HS256'),
        'ttl_seconds' => (int) env('AUTH_JWT_TTL', 3600),
        'issuer' => env('AUTH_JWT_ISSUER', ''),
        'audience' => env('AUTH_JWT_AUDIENCE', ''),
        // 优先 uid，缺失时 Guard 回退标准 sub
        'id_claim' => 'uid',
        'roles_claim' => 'roles',
        'tenant_claim' => 'tenant_id',
    ],
    'http' => [
        // 中间件当前固定读 authorization；此键预留给扩展
        'bearer_header' => 'authorization',
    ],
];
