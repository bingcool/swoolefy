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
use Swoolefy\Core\ResponseFormatter;
use Swoolefy\Core\Coroutine\Context as SwooleContent;

class UdpHandler extends Swoole implements HandlerInterface
{
    /**
     * 数据分隔符
     */
    const EOF = SWOOLEFY_EOF_FLAG;

    /**
     * @var array|null
     */
    protected $clientInfo = null;

    /**
     * __construct
     */
    public function __construct()
    {
        parent::__construct();
    }

    /**
     * @param array $clientInfo
     * @return void
     */
    public function setClientInfo(array $clientInfo)
    {
        $this->clientInfo = $clientInfo;
    }

    /**
     * @return array|null
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
                try {
                    $packet = UdpPacket::parse((string) $payload, $this->clientInfo ?? []);
                } catch (\InvalidArgumentException $exception) {
                    $this->sendErrorResponse($exception->getMessage());
                    return false;
                }

                $this->setUdpPacket($packet);
                $this->setMixedParams($packet->getParams());
                $this->setServiceHandle($packet->getEndpoint());

                parent::run(null, $payload);

                $endPoint = $packet->getEndpoint();
                $params = $packet->getParams();
            } else {
                $isTaskProcess = true;
                foreach ($contextData as $key => $value) {
                    SwooleContent::set($key, $value);
                }
                list($callable, $params) = $payload;
            }

            if (isset($endPoint) || isset($callable)) {
                if ($isTaskProcess === false) {
                    try {
                        list($beforeMiddleware, $callable, $afterMiddleware) = ServiceDispatch::getEndPointMapService($endPoint);
                    } catch (\Throwable $throwable) {
                        $this->sendErrorResponse($throwable->getMessage());
                        return false;
                    }
                    $dispatcher = new ServiceDispatch($callable, $params);
                } else if ($isTaskProcess === true) {
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
     * @param string $msg
     * @param int $code
     * @param array|null $clientInfo
     * @return bool
     */
    protected function sendErrorResponse(string $msg, int $code = -1, ?array $clientInfo = null): bool
    {
        if (!Swfy::isWorkerProcess()) {
            return false;
        }

        $clientInfo = $clientInfo ?? $this->clientInfo;
        if (empty($clientInfo['address']) || empty($clientInfo['port'])) {
            return false;
        }

        $responseDto = ResponseFormatter::formatDataDto($code, $msg);
        $data = json_encode($responseDto->toArray(), JSON_UNESCAPED_UNICODE);
        $serverSocket = (int) ($clientInfo['server_socket'] ?? -1);

        return Swfy::getServer()->sendto(
            $clientInfo['address'],
            (int) $clientInfo['port'],
            $data,
            $serverSocket
        );
    }

    /**
     * author
     * @return mixed
     */
    public function author()
    {
    }
}
