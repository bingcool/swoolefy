<?php

declare(strict_types=1);

namespace MY_APP_NAME;

use Swoolefy\Core\BootstrapInterface;
use Swoolefy\Http\Middleware\LocaleMiddleware;
use Swoolefy\Http\Middleware\SecurityHeadersMiddleware;
use Swoolefy\Http\RequestInput;
use Swoolefy\Http\ResponseOutput;

/**
 * 全局请求钩子（Protocol/conf.php → application_bootstrap）。
 *
 * 三行 I18n：
 * LocaleMiddleware::apply($requestInput);
 * $t = \Swoolefy\Core\Application::getApp()->get('translator');
 * $t->trans('hello');
 */
class Bootstrap implements BootstrapInterface
{
    public static function handle(RequestInput $requestInput, ResponseOutput $responseOutput)
    {
        SecurityHeadersMiddleware::apply($requestInput, $responseOutput);
        LocaleMiddleware::apply($requestInput);
    }
}
