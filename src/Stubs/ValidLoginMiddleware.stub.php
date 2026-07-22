<?php

/**
 * @deprecated 请直接使用 {@see \Swoolefy\Http\Middleware\AuthenticateMiddleware}。
 * create 应用不再生成本文件；存量应用可删除后改挂 AuthenticateMiddleware。
 *
 * @see docs/Auth.md
 */

namespace MY_APP_NAME\Middleware\Route;

use Swoolefy\Http\Middleware\AuthenticateMiddleware;
use Swoolefy\Http\RequestInput;
use Swoolefy\Http\ResponseOutput;

/**
 * @deprecated 委托 AuthenticateMiddleware；勿再 var_dump。
 */
class ValidLoginMiddleware extends AuthenticateMiddleware
{
    public function handle(RequestInput $requestInput, ResponseOutput $responseOutput)
    {
        parent::handle($requestInput, $responseOutput);
    }
}
