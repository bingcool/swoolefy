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

use Swoolefy\Core\Swfy;
use Swoolefy\Core\Swoole;
use Swoolefy\Core\ServiceDispatch;
use Swoolefy\Core\HandlerInterface;

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
     * @return mixed
     * @throws \Throwable
     */
    public function run(?int $fd, $payload, array $extendData = [])
    {
        try {
            parent::run(null, $payload);
            if ($this->isWorkerProcess()) {
                $dataGramItems = explode(static::EOF, $payload, 3);
                if (count($dataGramItems) == 3) {
                    list($service, $event, $params) = $dataGramItems;
                    if (is_string($params)) {
                        $params = json_decode($params, true) ?? $params;
                    }
                } else if (count($dataGramItems) == 2) {
                    list($service, $event) = $dataGramItems;
                    $params = [];
                } else {
                    throw new \Exception('Udp dataGramItems parse error');
                }
                if ($service && $event) {
                    $callable = [$service, $event];
                }
            } else {
                $isTaskProcess = true;
                list($callable, $params) = $payload;
            }

            if ($callable) {
                if (!isset($isTaskProcess)) {
                    $service          = trim(str_replace('\\', DIRECTORY_SEPARATOR, $service), DIRECTORY_SEPARATOR);
                    $serviceHandle    = implode(self::EOF, [$service, $event]);
                    $this->setServiceHandle($serviceHandle);
                    list($beforeHandle, $callable, $afterHandle) = Swfy::getRouterMapService($serviceHandle);
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