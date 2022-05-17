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

class UdpHandler extends Swoole implements HandlerInterface
{

    /**
     * 数据分隔符
     */
    const EOF = '::';

    /**
     * $client_info
     * @var null
     */
    protected $client_info = null;

    /**
     * __construct
     * @param array $config
     */
    public function __construct(array $config = [])
    {
        parent::__construct($config);
    }

    /**
     * init 当执行run方法时,首先会执行init->bootstrap
     * @param mixed $recv
     * @return void
     */
    public function init($recv)
    {
    }

    /**
     * bootstrap 当执行run方法时,首先会执行init->bootstrap
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
        return $this->client_info;
    }

    /**
     * run 完成初始化后，路由匹配和创建访问实例
     * @param int $fd
     * @param mixed $payload
     * @return mixed
     * @throws \Throwable
     */
    public function run($payload, $clientInfo, array $extend_data = [])
    {
        try {
            parent::run($fd = null, $payload);
            $this->client_info = $clientInfo;
            if ($this->isWorkerProcess()) {
                $dataGramItems = explode(static::EOF, $payload);
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
                $dispatcher = new ServiceDispatch($callable, $params);
                if (isset($isTaskProcess) && $isTaskProcess === true) {
                    list($from_worker_id, $task_id, $task) = $extend_data;
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
     * @return
     */
    public function author()
    {
    }
}