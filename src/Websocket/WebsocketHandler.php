<?php
/**
 * +----------------------------------------------------------------------
 * | swoolefy framework bases on swoole extension development, we can use it easily!
 * +----------------------------------------------------------------------
 * | Licensed ( https://opensource.org/licenses/MIT )
 * +----------------------------------------------------------------------
 * | @see https://github.com/bingcool/swoolefy
 * +----------------------------------------------------------------------
 */

namespace Swoolefy\Websocket;

use Swoolefy\Core\Swfy;
use Swoolefy\Core\Swoole;
use Swoole\WebSocket\Frame;
use Swoolefy\Core\ServiceDispatch;
use Swoolefy\Core\ResponseFormatter;
use Swoolefy\Core\HandlerInterface;

class WebsocketHandler extends Swoole implements HandlerInterface
{

    /**
     * 数据分隔符
     */
    const EOF = SWOOLEFY_EOF_FLAG;

    /**
     * __construct
     * @param array $config
     */
    public function __construct(array $config = [])
    {
        parent::__construct($config);
    }

    /**
     * run
     * @param int $fd
     * @param mixed $payload
     * @param array $extendData
     * @param array $contextData
     * @return mixed
     * @throws \Throwable
     */
    public function run($fd, $payload, array $extendData = [], array $contextData = [])
    {
        try {
            // parse data
            if ($this->isWorkerProcess()) {
                $payload = explode(static::EOF, $payload, 3);
                if (is_array($payload) && count($payload) == 3) {
                    list($service, $event, $params) = $payload;
                    if (is_string($params)) {
                        $params = json_decode($params, true) ?? $params;
                        if (!is_array($params)) {
                            return Swfy::getServer()->push($fd, $this->buildErrorMsg('Websocket Params must be json string'), 1, true);
                        }
                    }
                } else {
                    return Swfy::getServer()->push($fd, $this->buildErrorMsg('Websocket Params Missing'), 1, true);
                }

                // heartbeat
                if ($this->ping($event)) {
                    $pingFrame = new Frame;
                    $pingFrame->opcode = WEBSOCKET_OPCODE_PONG;
                    return Swfy::getServer()->push($fd, $pingFrame);
                }
            }

            parent::run($fd, $payload);
            if ($this->isWorkerProcess()) {
                if ($service && $event) {
                    $callable = [$service, $event];
                }
            } else {
                $isTaskProcess = true;
                foreach ($contextData as $key => $value) {
                    \Swoolefy\Core\Coroutine\Context::set($key, $value);
                }
                list($callable, $params) = $payload;
            }

            if ($callable) {
                if (!isset($isTaskProcess)) {
                    $service          = trim(str_replace('\\', DIRECTORY_SEPARATOR, $service), DIRECTORY_SEPARATOR);
                    $serviceHandle    = implode(self::EOF, [$service, $event]);
                    $this->setServiceHandle($serviceHandle);
                    list($beforeHandle, $callable, $afterHandle) = ServiceDispatch::getRouterMapService($serviceHandle);
                }

                $dispatcher = new ServiceDispatch($callable, $params);
                if (isset($isTaskProcess) && $isTaskProcess === true) {
                    list($fromWorkerId, $taskId, $task) = $extendData;
                    $dispatcher->setFromWorkerIdAndTaskId($fromWorkerId, $taskId, $task);
                }

                if (isset($beforeHandle)) {
                    $dispatcher->setBeforeHandle($beforeHandle);
                }

                if (isset($afterHandle)) {
                    $dispatcher->setAfterHandle($afterHandle);
                }

                $dispatcher->dispatch();
            }
        } catch (\Throwable $throwable) {
            ServiceDispatch::getErrorHandle()->errorMsg($throwable->getMessage(), -1);
            throw $throwable;
        } finally {
            if (!$this->isDefer) {
                parent::end();
            }
        }

    }

    /**
     * ping
     * @param string $evnet
     * @return bool
     */
    public function ping(string $event)
    {
        if (strtolower($event) == 'ping') {
            return true;
        }
        return false;
    }

    /**
     * @param string $msg
     * @return string
     */
    private function buildErrorMsg(string $msg = '')
    {
        if (Swfy::isWorkerProcess()) {
            $errorMsg = ResponseFormatter::buildResponseData(-1, $msg);
        }
        return json_encode($errorMsg ?? []);
    }

    /**
     * auth
     * @return void
     */
    public function auth()
    {
    }
}

