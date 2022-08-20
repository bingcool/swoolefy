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
     * init->bootstrap
     * @param mixed $recv
     * @return void
     */
    public function init($recv)
    {
    }

    /**
     * init->bootstrap
     * @param mixed $recv
     * @return void
     */
    public function bootstrap($recv)
    {
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
     * @param mixed $payload
     * @param mixed $clientInfo
     * @param array $extendData
     * @return mixed
     * @throws \Throwable
     */
    public function run($payload, $clientInfo, array $extendData = [])
    {
        try {
            parent::run($fd = null, $payload);
            $this->clientInfo = $clientInfo;
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
     * author
     * @return mixed
     */
    public function author()
    {
    }
}