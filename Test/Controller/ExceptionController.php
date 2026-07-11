<?php
namespace Test\Controller;

use Swoolefy\Annotation\ApiOperation;
use Swoolefy\Core\Controller\BController;

class ExceptionController extends BController
{
    /**
     * @Api 测试触发错误与异常处理
     *
     * curl -X GET 'http://127.0.0.1:9501/api/exception/test'
     */
    #[ApiOperation(description: '测试触发错误与异常处理')]
    public function test(): array
    {
        var_dump('test exception');
        trigger_error('trigger error');
        return [
            'name'=>'bingcool-'.rand(1,1000)
        ];
    }
}
