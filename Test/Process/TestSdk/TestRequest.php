<?php

namespace Test\Process\TestSdk;

use GenerateSdk\Swoolefy\Test\Module\Order\Client\LogOrderApi;
use GenerateSdk\Swoolefy\Test\Module\Order\Request\LogContentDto;
use GenerateSdk\Swoolefy\Test\Module\Order\Request\LogSaveRequest;

use GenerateSdk\Swoolefy\Test\Module\Order\Response\LogContentRespDto;
use GenerateSdk\Swoolefy\Test\Module\Order\Response\LogResponse;
use GenerateSdk\Swoolefy\Test\Module\Order\Response\SmallCategoryRespDto;

use GuzzleHttp\Client;
use Swoolefy\Core\BaseServer;
use Swoolefy\Core\Coroutine\GoWaitGroup;
use Swoolefy\Core\Process\AbstractProcess;
use Swoolefy\Core\SyncPipe;
use Swoolefy\Util\CovertProperty;
use Test\App;

class TestRequest extends AbstractProcess
{
    public function run()
    {
        //$this->requestTest();
        $this->responseTest();
    }

    protected function requestTest()
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

    protected function responseTest()
    {
        $data = [
            'code' => 0,
            'msg' => 'ok',
            'data' => [
                [
                    'name' => 'test1',
                    'value' => 'value string',
                    'categories' => [
                        [
                            'cateId' => 1,
                            'cateName' => 'category 1',
                            'subCategories' => [
                                [
                                    'subCateId' => 11,
                                    'subCateName' => 'sub category 11',
                                    'smallCategories' => [
                                        [
                                            'smallCategoryId' => 111,
                                            'smallCategoryName' => 'small category 111',
                                        ],
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
                [
                    'name' => 'test2',
                    'value' => 'value string 2',
                    'categories' => [],
                ],
            ],
        ];

        /** @var LogResponse $response */
        $response = \GenerateSdk\Swoolefy\Test\Support\CovertProperty::toCovertDeepProperty($data, LogResponse::class);
        $firstLogContent = $response->getData()[0] ?? null;
        var_dump($response->getCode());
        var_dump($response);
        var_dump($response->getData()[0]->getCategories()[0]->getCateName());
//        var_dump($response->getData()[0]->getCategories()[0]->getSubCategories()[0]->getSubCateName());
//        var_dump($response->getData()[0]->getCategories()[0]->getSubCategories()[0]->toArray()['smallCategories'][0] instanceof SmallCategoryRespDto);
//        var_dump($response->getData()[0]->getCategories()[0]->getSubCategories()[0]->getSmallCategories()[0]->getSmallCategoryName());
    }
}
