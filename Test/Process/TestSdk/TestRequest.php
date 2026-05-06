<?php

namespace Test\Process\TestSdk;

use GenerateSdk\Swoolefy\Test\Module\Order\Client\LogOrderApi;
use GenerateSdk\Swoolefy\Test\Module\Order\Request\LogContentDto;
use GenerateSdk\Swoolefy\Test\Module\Order\Request\LogSaveRequest;
use GuzzleHttp\Client;
use Swoolefy\Core\BaseServer;
use Swoolefy\Core\Coroutine\GoWaitGroup;
use Swoolefy\Core\Process\AbstractProcess;
use Swoolefy\Core\SyncPipe;
use Test\App;

class TestRequest extends AbstractProcess
{
    public function run()
    {
        $LogOrderApi = new LogOrderApi(new Client());
        $LogSaveRequest = new LogSaveRequest();
        $LogSaveRequest->setLogIds([1,2,3,4,5]);

        $logContent = new LogContentDto();
        $logContent->setName("test1");
        $logContent->setValue("value string");
        $logContent->setCategories([2344,456]);
        $LogSaveRequest->addLogContent($logContent);

        $LogSaveRequest->setLogContentDto($logContent);
        var_dump($LogSaveRequest->toDeepArray());
    }
}
