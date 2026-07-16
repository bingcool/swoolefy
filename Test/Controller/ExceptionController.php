<?php
namespace Test\Controller;

use Swoolefy\Annotation\ApiOperation;
use Swoolefy\Core\Controller\BController;

class ExceptionController extends BController
{
    /**
     * 测试触发错误与异常处理。
     *
     * Route: GET /api/exception/test
     *
     ```bash
     curl -X GET 'http://127.0.0.1:9501/api/exception/test' \
       -H 'Accept: application/json'
     ```
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
