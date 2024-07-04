<?php
namespace Test\Module\Order\Controller;

use Swoolefy\Core\Application;
use Swoolefy\Core\Controller\BController;
use Swoolefy\Core\Log\Formatter\LineFormatter;
use Swoolefy\Http\RequestInput;

class LogOrderController extends BController
{
    /**
     * @return void
     * @see \Test\Module\Order\Validation\LogOrderValidation::testLog()
     */
    public function testLog(RequestInput $requestInput)
    {
        /**
         * @var \Swoolefy\Util\Log $log
         */
        $log = Application::getApp()->get('info_log');
        $formatter = new LineFormatter("%message%\n");
        $log->setFormatter($formatter);
        $log->setLogFilePath($log->getLogFilePath());
        $log->addInfo(['name' => 'bingcool','address'=>'深圳'],true, ['name'=>'bincool','sex'=>1,'address'=>'shenzhen']);

        $this->returnJson([
            'Controller' => $requestInput->getControllerId(),
            'Action' => $requestInput->getActionId().'-'.rand(1,1000)
        ]);
    }
}