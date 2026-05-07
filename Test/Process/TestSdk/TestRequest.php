<?php

namespace Test\Process\TestSdk;

use GenerateSdk\Swoolefy\Test\Module\Order\Client\LogOrderApi;
use GenerateSdk\Swoolefy\Test\Module\Order\Request\CategoryDto;
use GenerateSdk\Swoolefy\Test\Module\Order\Request\LogContentDto;
use GenerateSdk\Swoolefy\Test\Module\Order\Request\LogSaveRequest;

use GenerateSdk\Swoolefy\Test\Module\Order\Request\SubCategoryDto;
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
        goAfter(3000, function (){
            $this->requestTest();
        });
        //$this->responseTest();
    }

    protected function requestTest()
    {
        $LogOrderApi = new LogOrderApi(new Client(
            [
                'base_uri' => 'http://127.0.0.1:9501',
                'timeout' => 10.0,
            ]
        ));
        $LogSaveRequest = new LogSaveRequest();
        $LogSaveRequest->setLogIds([1,2,3,4,5]);

        $logContent = new LogContentDto();
        $logContent->setName("test1");
        $logContent->setValue("value string");

        $categoryDto  = new CategoryDto();
        $categoryDto->setCateId(1);
        $categoryDto->setCateName("category 1");

        $subCategoryDto = new SubCategoryDto();
        $subCategoryDto->setSubCateId(11);
        $subCategoryDto->setSubCateName("sub category 11");

        $categoryDto->addSubCategoryDto($subCategoryDto);

        $logContent->setCategories([$categoryDto, $categoryDto]);
        $LogSaveRequest->addLogContent($logContent);

        $LogSaveRequest->addLogContent($logContent);
        $response = $LogOrderApi->testRequest($LogSaveRequest);
        if (is_object($response)) {
            $list = $response->getData();
            foreach ($list as $logContentRespDto) {
                var_dump($logContentRespDto->getCategories()[0]->getCateName());
            }
            var_dump($response->getTraceId());
        }else {
            var_dump($response);
        }


    }

    protected function responseTest()
    {
        $data = [
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
                    'categories' => [
                        [
                            'cateId' => 2,
                            'cateName' => 'category 2',
                            'subCategories' => [
                                [
                                    'subCateId' => 12,
                                    'subCateName' => 'sub category 12',
                                    //'smallCategories' => []
                                ]
                            ]
                        ]
                    ],
                ],
            ],
        ];

        /** @var LogResponse $response */
        $response = \GenerateSdk\Swoolefy\Test\Support\SdkCovertProperty::toCovertDeepProperty($data, LogResponse::class);
        $firstLogContent = $response->getData()[0] ?? null;
        var_dump($response->getCode());
        var_dump($response);
        var_dump($response->getData()[0]->getCategories()[0]->getCateName());
//        var_dump($response->getData()[0]->getCategories()[0]->getSubCategories()[0]->getSubCateName());
//        var_dump($response->getData()[0]->getCategories()[0]->getSubCategories()[0]->toArray()['smallCategories'][0] instanceof SmallCategoryRespDto);
//        var_dump($response->getData()[0]->getCategories()[0]->getSubCategories()[0]->getSmallCategories()[0]->getSmallCategoryName());
    }
}
