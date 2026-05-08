<?php
namespace Test\Module\Order\Controller;

use GenerateSdk\Swoolefy\Test\Support\SdkCovertProperty;
use Swoolefy\Core\Application;
use Swoolefy\Core\Controller\BController;
use Swoolefy\Core\Log\Formatter\LineFormatter;
use Swoolefy\Http\RequestInput;
use Test\Module\Order\Request\LogContentDto;
use Test\Module\Order\Request\LogSaveRequest;
use Test\Module\Order\Response\LogCategoryDto;
use Test\Module\Order\Response\LogContentRespDto;
use Test\Module\Order\Response\LogItemDto;
use Test\Module\Order\Response\LogResponse;

class LogOrderController extends BController
{
    /**
     * @return array<LogResponse>
     * @see \Test\Module\Order\Validation\LogOrderValidation::testLog()
     */
    public function testLog(LogSaveRequest $request): LogResponse
    {
        $logIds = $request->getLogIds();

        $logContents = $request->getLogContents();
        foreach ($logContents as $logContent) {
            var_dump($logContent->getValue());
            var_dump($logContent->getCategories()[0]->getSubCategories());
        }

        /**
         * @var \Swoolefy\Util\Log $log
         */
        $log = Application::getApp()->get('info_log');
        $formatter = new LineFormatter("%message%\n");
        $log->setFormatter($formatter);
        $log->setLogFilePath($log->getLogFilePath());
        $log->addInfo(['name' => 'bingcool','address'=>'深圳'],true, ['name'=>'bincool','sex'=>1,'address'=>'shenzhen']);

        $logResponse = new LogResponse();
        $logItem     = new LogItemDto();
        $logItem->setId(11);
        $logItem->setLogName('test log');

        $category = new LogCategoryDto();
        $category->setCateType(111111111111111111);
        $category->setCateId(34566);
        $category->setCateName('category test');

        $logItem->addCategory($category);

        $logResponse->addLogItemDto($logItem);



        return $logResponse;
    }

    public function testRequest(LogSaveRequest $request): LogResponse
    {
        $response = new LogResponse();
        $logContentRespDto = new LogContentRespDto();
        $logContentRespDto->setValue($request->getLogContents()[0]->getValue());
        $logContentRespDto->setName($request->getLogContents()[0]->getName());
        $logContentRespDto->setCategories($request->getLogContents()[0]->getCategories());
        $response->addLogContent($logContentRespDto);
        return $response;
    }
}