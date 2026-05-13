<?php
namespace Test\Module\Order\Controller;

use GenerateSdk\Swoolefy\Test\Support\SdkCovertProperty;
use Swoolefy\Annotation\ApiController;
use Swoolefy\Annotation\ApiOperation;
use Swoolefy\Annotation\ApiProperty;
use Swoolefy\Core\Application;
use Swoolefy\Core\Controller\BController;
use Swoolefy\Core\Log\Formatter\LineFormatter;
use Swoolefy\Http\RequestInput;
use Test\Module\Order\Request\LogContentDto;
use Test\Module\Order\Request\LogContentPageRequest;
use Test\Module\Order\Request\LogSaveRequest;
use Test\Module\Order\Response\LogCategoryDto;
use Test\Module\Order\Response\LogContentPageResult;
use Test\Module\Order\Response\LogContentPageResultResponse;
use Test\Module\Order\Response\LogContentRespDto;
use Test\Module\Order\Response\LogItemDto;
use Test\Module\Order\Response\LogResponse;
use Test\Module\Order\Service\LogOrderService;

#[ApiController(
    description: '日志模块控制器'
)]
class LogOrderController extends BController
{
    /**
     * php8.4的属性hook钩子函数get拦截惰性加载获取实例
     * @var LogOrderService
     */
    protected LogOrderService $logOrderService {
        get {
            return $this->logOrderService ??= new LogOrderService();
        }
    }

    public function logOrder(): ?LogResponse
    {
        $this->logOrderService->logOrder();
        $this->logOrderService->logOrder();
        $response = new LogResponse();
        return $response;
    }

    /**
     * @return array<LogResponse>
     * @see \Test\Module\Order\Validation\LogOrderValidation::testLog()
     *
     */
    #[ApiOperation(
        description: '测试日志'
    )]
    public function testLog(LogSaveRequest $request): ?LogResponse
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

        return null;
    }

    public function testRequest(?LogSaveRequest $request): ?LogResponse
    {
        $response = new LogResponse();
        $logContentRespDto = new LogContentRespDto();
        $logContentRespDto->setValue($request->getLogContents()[0]->getValue());
        $logContentRespDto->setName($request->getLogContents()[0]->getName());
        $logContentRespDto->setCategories($request->getLogContents()[0]->getCategories());
        $response->addLogContent($logContentRespDto);
        return $response;
    }

    public function testRequest1(?string $name, array $userIds = []): ?LogResponse
    {
        var_dump($name, $userIds);
        $response = new LogResponse();
        return $response;
    }

    public function testPageRequest(LogContentPageRequest $request): LogContentPageResultResponse
    {
        $this->logOrderService->logOrder();
        $this->logOrderService->logId = 10;
        var_dump(spl_object_id($this->logOrderService));
        var_dump(spl_object_id($this->logOrderService));
        $this->logOrderService->logOrder();

        $response = new LogContentPageResultResponse();
        $logContentPageResult = new LogContentPageResult();
        $logContentPageResult->setTotal(100);
        $logContentDto = new LogContentDto();
        $logContentDto->setValue('test');
        $logContentDto->setName('test');
        $logContentPageResult->addListItem($logContentDto);
        $response->setData($logContentPageResult);
        return $response;
    }
}