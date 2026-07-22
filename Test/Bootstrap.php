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

namespace Test;

use Swoolefy\Core\BootstrapInterface;
use Swoolefy\Http\Middleware\LocaleMiddleware;
use Swoolefy\Http\Middleware\SecurityHeadersMiddleware;
use Swoolefy\Http\RequestInput;
use Swoolefy\Http\ResponseOutput;
use Test\Logger\RequestLog;

class Bootstrap implements BootstrapInterface
{
    public static function handle(RequestInput $requestInput, ResponseOutput $responseOutput)
    {
        \Swoolefy\Core\Coroutine\Context::set('tenant_id', 0);
        SecurityHeadersMiddleware::apply($requestInput, $responseOutput);
        // 语言协商 → Context lang_locale（供 translator 组件）
        LocaleMiddleware::apply($requestInput);
        $requestInput->setValue('name', 'boostrap');
        RequestLog::info('RequestLog RequestLog');
    }
}
