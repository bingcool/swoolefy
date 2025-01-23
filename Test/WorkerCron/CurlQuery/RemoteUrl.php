<?php
namespace Test\WorkerCron\CurlQuery;

use Common\Library\HttpClient\RawResponse;
use Swoolefy\Worker\Dto\CronUrlTaskMetaDto;

class RemoteUrl {

    /**
     * 回调处理函数
     *
     * @param RawResponse $response
     * @return void
     */
    public function handle(RawResponse $response, CronUrlTaskMetaDto $taskMetaDto)
    {
        // 做简单的响应处理
        var_dump($response->getDecodeBody());
    }
}