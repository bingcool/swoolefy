<?php
namespace Test\WorkerCron\CurlQuery;

use Common\Library\HttpClient\RawResponse;

class RemoteUrl {

    /**
     * 回调处理函数
     *
     * @param RawResponse $response
     * @return void
     */
    public function handle(RawResponse $response) {
        // 做简单的响应处理
        var_dump($response->getHeaders());
    }
}