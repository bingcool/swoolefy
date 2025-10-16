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
use Swoolefy\Core\Coroutine\Context as SwooleContent;

class WebsocketHandler extends Swoole implements HandlerInterface
{

    /**
     * 数据分隔符
     */
    const EOF = SWOOLEFY_EOF_FLAG;

    /**
     * 内部指定的ping的endPoint末端
     */
    const PingEndPoint = 'swoolefy/websocket/ping';

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
                $dataGramItems = explode(static::EOF, $payload, 2);
                if (count($dataGramItems) == 2) {
                    list($endPoint, $params) = $dataGramItems;
                    if (is_string($params)) {
                        $params = json_decode($params, true) ?? $params;
                        if (!is_array($params)) {
                            return Swfy::getServer()->push($fd, $this->buildErrorMsg('Websocket Params must be json string'), 1, true);
                        }
                    }
                } else {
                    return Swfy::getServer()->push($fd, $this->buildErrorMsg('Websocket Payload Parse Error, Payload: '.$payload), 1, true);
                }

                // heartbeat
                if ($this->ping($endPoint)) {
                    $pingFrame = new Frame;
                    $pingFrame->opcode = WEBSOCKET_OPCODE_PONG;
                    return Swfy::getServer()->push($fd, $pingFrame);
                }
            }

            parent::run($fd, $payload);
            if ($this->isWorkerProcess()) {
                $isTaskProcess = false;
            } else {
                $isTaskProcess = true;
                foreach ($contextData as $key => $value) {
                    SwooleContent::set($key, $value);
                }
                list($callable, $params) = $payload;
            }

            if (isset($callable) || isset($callable)) {
                if ($isTaskProcess === false) {
                    $endPoint = trim(str_replace('\\', DIRECTORY_SEPARATOR, $endPoint), DIRECTORY_SEPARATOR);
                    $this->setServiceHandle($endPoint);
                    list($beforeMiddleware, $callable, $afterMiddleware) = ServiceDispatch::getEndPointMapService($endPoint);
                    $dispatcher = new ServiceDispatch($callable, $params);
                }else if ($isTaskProcess === true) {
                    $dispatcher = new ServiceDispatch($callable, $params);
                    list($fromWorkerId, $taskId, $task) = $extendData;
                    $dispatcher->setFromWorkerIdAndTaskId($fromWorkerId, $taskId, $task);
                }

                if (isset($beforeMiddleware)) {
                    $dispatcher->setBeforeMiddleware($beforeMiddleware);
                }

                if (isset($afterMiddleware)) {
                    $dispatcher->setAfterMiddleware($afterMiddleware);
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
     * @param string $endPoint
     * @return bool
     */
    public function ping(string $endPoint)
    {
        if (strtolower($endPoint) == self::PingEndPoint) {
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

