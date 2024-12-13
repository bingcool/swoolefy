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

use Common\Library\Db\Facade\Db;
use Swoolefy\Core\BootstrapInterface;
use Swoolefy\Exception\SystemException;
use Swoolefy\Http\RequestInput;
use Swoolefy\Http\ResponseOutput;
use Test\Logger\RequestLog;

class Bootstrap implements BootstrapInterface
{
    public static function handle(RequestInput $requestInput, ResponseOutput $responseOutput)
    {
          \Swoolefy\Core\Coroutine\Context::set('lang_locale', 'zh_CN');
          $requestInput->setValue('name', 'boostrap');
          RequestLog::info('RequestLog RequestLog');
    }
}