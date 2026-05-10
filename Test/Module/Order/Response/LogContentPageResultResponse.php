<?php
namespace Test\Module\Order\Response;

use Swoolefy\Http\BasePageResultResponse;
use Swoolefy\Http\BaseResponse;

class LogContentPageResultResponse extends BasePageResultResponse
{
    protected LogContentPageResult $data;

    /**
     * @return void
     */
    public function __construct()
    {
        $this->data = new LogContentPageResult;
    }
}