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

use Swoole\Http\Request;
use Swoole\Http\Response;
use Swoole\WebSocket\Server;
use Swoole\WebSocket\Frame;
use Swoolefy\Core\Swfy;
use Swoolefy\EventInterface\WebsocketEventInterface;

abstract class WebsocketEventServer extends WebsocketServer implements WebsocketEventInterface
{

    /**
     * __construct
     * @param array $config
     * @throws \Exception
     */
    public function __construct(array $config = [])
    {
        parent::__construct($config);
    }

    /**
     * onWorkerStart
     * @param Server $server
     * @param int $worker_id
     * @return void
     */
    abstract public function onWorkerStart(Server $server, int $worker_id);

    /**
     * onOpen
     * @param Server $server
     * @param object $request
     * @return void
     */
    abstract public function onOpen(Server $server, Request $request);

    /**
     * onRequest
     * @param Request $request
     * @param Response $response
     * @return void
     */
    public function onRequest(Request $request, Response $response)
    {
        $appInstance = new \Swoolefy\Core\App();
        $appInstance->run($request, $response);
    }

    /**
     * onMessage
     * @param Server $server
     * @param Frame $frame
     * @return void
     * @throws \Throwable
     */
    public function onMessage(Server $server, Frame $frame)
    {
        $fd     = $frame->fd;
        $data   = $frame->data;
        $opcode = $frame->opcode;
        $finish = $frame->finish;

        if ($finish) {
            if ($opcode == WEBSOCKET_OPCODE_TEXT) {
                $appConf = \Swoolefy\Core\Swfy::getAppConf();
                $appInstance = new WebsocketHandler();
                $appInstance->run($fd, $data);
            } else if ($opcode == WEBSOCKET_OPCODE_BINARY) {
                static::onMessageFromBinary($server, $frame);
            } else if ($opcode == WEBSOCKET_OPCODE_PING) {
                $pingFrame = new Frame;
                $pingFrame->opcode = WEBSOCKET_OPCODE_PONG;
                $server->push($frame->fd, $pingFrame);
            } else if ($opcode == WEBSOCKET_OPCODE_CLOSE) {
                static::onMessageFromClose($server, $frame);
            }
        } else {
            // close
            if (method_exists($server, 'disconnect')) {
                $server->disconnect($fd, $code = 1009, $reason = "");
            }
        }

    }

    /**
     * onTask
     * @param Server $server
     * @param int $task_id
     * @param int $from_worker_id
     * @param mixed $data
     * @param mixed $task
     * @return bool
     * @throws \Throwable
     */
    public function onTask(Server $server, int $task_id, int $from_worker_id, $data, $task = null)
    {
        list($callable, $taskData, $contextData, $fd) = $data;
        $appInstance = new WebsocketHandler();
        $appInstance->run($fd, [$callable, $taskData], [$from_worker_id, $task_id, $task], $contextData ?? []);
        return true;
    }

    /**
     * onFinish
     * @param Server $server
     * @param int $task_id
     * @param mixed $data
     * @return void
     */
    abstract public function onFinish(Server $server, int $task_id, $data);

    /**
     * onPipeMessage
     * @param Server $server
     * @param int $from_worker_id
     * @param mixed $message
     * @return void
     */
    abstract public function onPipeMessage(Server $server, int $from_worker_id, $message);

    /**
     * onClose
     * @param Server $server
     * @param int $fd
     * @return void
     */
    abstract public function onClose(Server $server, int $fd);

    /**
     * onMessageFromBinary
     * @param Server $server
     * @param Frame $frame
     * @return void
     */
    abstract public function onMessageFromBinary(Server $server, Frame $frame);

    /**
     * onMessageFromClose
     * @param Server $server
     * @param mixed $frame
     * @return void
     */
    abstract public function onMessageFromClose(Server $server, Frame $frame);

}
