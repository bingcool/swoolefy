<?php
namespace Test\Controller;

use Swoole\Coroutine\Channel;
use Swoolefy\Annotation\ApiOperation;
use Swoolefy\Core\Controller\BController;
use Swoolefy\Core\Log\LogManager;

/**
 * 各类日志通道手工验证（进程级 LogManager，非 Application 协程单例）。
 *
 * 路由见 Test/Router/Common/Log.php
 *
 * 组件注册：Test/Config/component/log.php
 * - info_log / error_log：业务主动写入
 * - system_error_log：由 Test\Exception\ExceptionHandle::shutHalt 在异常通道中写入
 */
class LogController extends BController
{
    /**
     * 测试 info 日志写入（进程级 LogManager::getLogger('info_log')）。
     *
     * Route: GET /api/log/info
     *
     ```bash
     curl -X GET 'http://127.0.0.1:9501/api/log/info' \
       -H 'Accept: application/json'
     ```
     */
    #[ApiOperation(description: '测试 info_log 进程级日志写入')]
    public function info(): array
    {
        /** @var \Swoolefy\Util\Log|false $logger */
        $logger = LogManager::getInstance()->getLogger('info_log');
        if (!is_object($logger)) {
            return [
                'ok' => false,
                'channel' => 'info_log',
                'msg' => 'Missing info_log component',
            ];
        }

        $msg = 'LogController::info test-' . date('Y-m-d H:i:s') . '-' . rand(1, 9999);
        $logger->addInfo($msg, false, [
            'scene' => 'log_controller_info',
            'cid' => \Swoole\Coroutine::getCid(),
        ]);

        return [
            'ok' => true,
            'channel' => 'info_log',
            'wrote' => $msg,
            'log_file' => $logger->getLogFilePath(),
            'hint' => '查看 LOG_PATH/cli/info.log（按小时分文件时以实际 log_file 为准）',
        ];
    }

    /**
     * 测试 error 日志写入（进程级 LogManager::getLogger('error_log')）。
     *
     * Route: GET /api/log/error
     *
     ```bash
     curl -X GET 'http://127.0.0.1:9501/api/log/error' \
       -H 'Accept: application/json'
     ```
     */
    #[ApiOperation(description: '测试 error_log 进程级日志写入')]
    public function error(): array
    {
        /** @var \Swoolefy\Util\Log|false $logger */
        $logger = LogManager::getInstance()->getLogger('error_log');
        if (!is_object($logger)) {
            return [
                'ok' => false,
                'channel' => 'error_log',
                'msg' => 'Missing error_log component',
            ];
        }

        $msg = 'LogController::error test-' . date('Y-m-d H:i:s') . '-' . rand(1, 9999);
        $logger->addError($msg, false, [
            'scene' => 'log_controller_error',
            'cid' => \Swoole\Coroutine::getCid(),
        ]);

        return [
            'ok' => true,
            'channel' => 'error_log',
            'wrote' => $msg,
            'log_file' => $logger->getLogFilePath(),
            'hint' => '查看 LOG_PATH/cli/error.log',
        ];
    }

    /**
     * 触发未捕获异常，由框架 ExceptionHandle 写入 system_error_log。
     *
     * 勿在本方法内 try/catch 吞掉；走 App::run → ExceptionHandle::response → shutHalt。
     *
     * Route: GET /api/log/system-error
     *
     ```bash
     curl -X GET 'http://127.0.0.1:9501/api/log/system-error' \
       -H 'Accept: application/json'
     ```
     */
    #[ApiOperation(description: '抛异常触发 system_error_log 自动记录')]
    public function systemError(): array
    {
        throw new \RuntimeException(
            'LogController::systemError intentional exception for system_error_log-' . rand(1, 9999)
        );
    }

    /**
     * 嵌套协程（goApp）内写入 info_log / error_log，验证协程中也能写日志。
     *
     * Route: GET /api/log/goapp
     *
     ```bash
     curl -X GET 'http://127.0.0.1:9501/api/log/goapp' \
       -H 'Accept: application/json'
     ```
     */
    #[ApiOperation(description: '测试 goApp 嵌套协程内 info/error 日志写入')]
    public function goAppLog(): array
    {
        $parentCid = \Swoole\Coroutine::getCid();
        $channel = new Channel(1);
        $suffix = date('Y-m-d H:i:s') . '-' . rand(1, 9999);

        goApp(function () use ($channel, $suffix, $parentCid) {
            $l1Cid = \Swoole\Coroutine::getCid();
            /** @var \Swoolefy\Util\Log|false $infoLogger */
            $infoLogger = LogManager::getInstance()->getLogger('info_log');
            $infoMsg = 'LogController::goApp L1 info-' . $suffix;
            if (is_object($infoLogger)) {
                $infoLogger->addInfo($infoMsg, false, [
                    'scene' => 'log_controller_goapp_l1',
                    'parent_cid' => $parentCid,
                    'cid' => $l1Cid,
                ]);
            }

            goApp(function () use ($channel, $suffix, $parentCid, $l1Cid, $infoMsg, $infoLogger) {
                $l2Cid = \Swoole\Coroutine::getCid();
                /** @var \Swoolefy\Util\Log|false $errorLogger */
                $errorLogger = LogManager::getInstance()->getLogger('error_log');
                $errorMsg = 'LogController::goApp L2 error-' . $suffix;
                if (is_object($errorLogger)) {
                    $errorLogger->addError($errorMsg, false, [
                        'scene' => 'log_controller_goapp_l2',
                        'parent_cid' => $parentCid,
                        'l1_cid' => $l1Cid,
                        'cid' => $l2Cid,
                    ]);
                }

                $channel->push([
                    'ok' => is_object($infoLogger) && is_object($errorLogger),
                    'parent_cid' => $parentCid,
                    'l1_cid' => $l1Cid,
                    'l2_cid' => $l2Cid,
                    'info_wrote' => $infoMsg,
                    'error_wrote' => $errorMsg,
                    'info_log_file' => is_object($infoLogger) ? $infoLogger->getLogFilePath() : null,
                    'error_log_file' => is_object($errorLogger) ? $errorLogger->getLogFilePath() : null,
                ]);
            });
        });

        $result = $channel->pop(3.0);
        if ($result === false) {
            return [
                'ok' => false,
                'channel' => ['info_log', 'error_log'],
                'msg' => 'goApp nested log write timeout',
            ];
        }

        return array_merge($result, [
            'hint' => '查看 info_log / error_log 文件中是否含 L1 info 与 L2 error 两条记录',
        ]);
    }
}
