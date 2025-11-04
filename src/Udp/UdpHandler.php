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

namespace Swoolefy\Udp;

use Swoolefy\Core\Swoole;
use Swoolefy\Core\ServiceDispatch;
use Swoolefy\Core\HandlerInterface;
use Swoolefy\Core\Coroutine\Context as SwooleContent;

class UdpHandler extends Swoole implements HandlerInterface
{

    /**
     * 数据分隔符
     */
    const EOF = SWOOLEFY_EOF_FLAG;

    /**
     * $client_info
     * @var mixed
     */
    protected $clientInfo = null;

    /**
     * __construct
     * @param array $config
     */
    public function __construct(array $config = [])
    {
        parent::__construct($config);
    }

    /**
     * @param $clientInfo
     * @return void
     */
    public function setClientInfo($clientInfo)
    {
        $this->clientInfo = $clientInfo;
    }

    /**
     * @return mixed
     */
    public function getClientInfo()
    {
        return $this->clientInfo;
    }

    /**
     * run
     * @param int|null $fd
     * @param mixed $payload
     * @param array $extendData
     * @param array $contextData
     * @return mixed
     * @throws \Throwable
     */
    public function run(?int $fd, $payload, array $extendData = [], array $contextData = [])
    {
        try {
            parent::run(null, $payload);
            if ($this->isWorkerProcess()) {
                $isTaskProcess = false;
                $dataGramItems = explode(static::EOF, $payload, 2);
                if (count($dataGramItems) == 2) {
                    list($endPoint, $params) = $dataGramItems;
                    if (is_string($params)) {
                        $params = json_decode($params, true) ?? $params;
                        if (!is_array($params)) {
                            throw new \Exception('Udp params must be json string');
                        }
                    }
                } else if (count($dataGramItems) == 1) {
                    $endPoint = current($dataGramItems);
                    $params   = [];
                } else {
                    throw new \Exception('Udp dataGramItems Parse Error, Payload: ' . $payload);
                }
            } else {
                $isTaskProcess = true;
                foreach ($contextData as $key => $value) {
                    SwooleContent::set($key, $value);
                }
                list($callable, $params) = $payload;
            }

            if (isset($endPoint) || isset($callable) ) {
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
            throw $throwable;
        } finally {
            if (!$this->isDefer) {
                parent::end();
            }
        }
    }

    /**
     * author
     * @return mixed
     */
    public function author()
    {
    }
}