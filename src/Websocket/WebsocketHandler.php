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

use Swoole\WebSocket\Frame;
use Swoolefy\Core\Application;
use Swoolefy\Core\Swfy;
use Swoolefy\Core\Swoole;
use Swoolefy\Core\ServiceDispatch;
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
     * @return mixed
     * @throws \Throwable
     */
    public function run(?int $fd, mixed $payload, array $extendData = [])
    {
        try {
            // parse data
            if ($this->isWorkerProcess()) {
                $payload = explode(static::EOF, $payload, 3);
                if (is_array($payload) && count($payload) == 3) {
                    list($service, $event, $params) = $payload;
                    if (is_string($params)) {
                        $params = json_decode($params, true) ?? $params;
                    }
                } else {
                    return Swfy::getServer()->push($fd, json_encode($this->errorMsg('Websocket Params Missing')), $opcode = 1, $finish = true);
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
                list($callable, $params) = $payload;
            }

            if ($callable) {

                if (!isset($isTaskProcess)) {
                    $service          = trim(str_replace('\\', '/', $service), '/');
                    $serviceHandle    = implode(self::EOF, [$service, $event]);
                    $routerMapService = Swfy::getRouterMapUri($serviceHandle);
                    $callable         = explode(self::EOF, $routerMapService);
                }

                $dispatcher = new ServiceDispatch($callable, $params);
                if (isset($isTaskProcess) && $isTaskProcess === true) {
                    list($from_worker_id, $task_id, $task) = $extendData;
                    $dispatcher->setFromWorkerIdAndTaskId($from_worker_id, $task_id, $task);
                }
                $dispatcher->dispatch();
            }

        } catch (\Throwable $throwable) {
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
     * @return array
     */
    private function errorMsg(string $msg = '')
    {
        if (Swfy::isWorkerProcess()) {
            $errorMsg = Application::buildResponseData(500, $msg);
        }
        return $errorMsg ?? [];
    }

    /**
     * auth
     * @return void
     */
    public function auth()
    {
    }
}

