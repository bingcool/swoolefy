<?php

namespace Test\Controller;

use Swoolefy\Annotation\ApiOperation;
use Swoolefy\Core\Controller\BController;
use Swoolefy\Support\FrameworkContext;

/**
 * Auth 登录态联调：路由 before 挂 AuthenticateMiddleware，缺 Bearer → 401。
 */
class AuthUserController extends BController
{
    /**
     * 返回当前登录用户（须 Bearer JWT）。
     *
     * Route: GET /api/auth-user/me
     * before: AuthenticateMiddleware
     *
     * 缺 token → HTTP 401（curl 验收）：
     ```bash
     curl -i -X GET 'http://127.0.0.1:9501/api/auth-user/me' \
       -H 'Accept: application/json'
     ```
     *
     * 非法 token → HTTP 401：
     ```bash
     curl -i -X GET 'http://127.0.0.1:9501/api/auth-user/me' \
       -H 'Accept: application/json' \
       -H 'Authorization: Bearer not.a.jwt'
     ```
     *
     * 合法 token（先用 Guard 签发后再替换 YOUR_JWT）：
     ```bash
     curl -i -X GET 'http://127.0.0.1:9501/api/auth-user/me' \
       -H 'Accept: application/json' \
       -H 'Authorization: Bearer YOUR_JWT'
     ```
     *
     * PHPUnit：`composer test:http -- --filter AuthUserControllerTest`
     * @see \PhpUintTest\Unit\Controller\AuthUserControllerTest
     */
    #[ApiOperation(description: '返回当前登录用户（须 Bearer JWT）')]
    public function me(): array
    {
        $user = FrameworkContext::userOrFail();

        return $user->toArray();
    }
}
