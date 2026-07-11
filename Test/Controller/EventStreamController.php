<?php
namespace Test\Controller;

use Swoole\Coroutine;
use Swoolefy\Annotation\StreamResponse;
use Swoolefy\Core\Controller\BController;
use Swoolefy\Http\EventStream;
use Swoolefy\Http\RequestInput;
use Swoolefy\Http\ResponseOutput;

class EventStreamController extends BController
{
    /**
     * @Api 测试 SSE 按固定间隔推送 JSON 消息
     *
     * curl -N 'http://127.0.0.1:9501/api/sse/stream?count=5&interval=0.5'
     */
    #[StreamResponse]
    public function stream(RequestInput $requestInput, ResponseOutput $responseOutput): void
    {
        $count = max(1, (int) $requestInput->input('count', 10));
        $interval = max(0.1, (float) $requestInput->input('interval', 1));
        $message = (string) $requestInput->input('message', 'swoolefy sse demo');

        $stream = new EventStream($responseOutput);

        // 建议浏览器断线后 3 秒重连
        $stream->send(['status' => 'connected', 'message' => $message], retry: 3000, autoId: true);

        for ($index = 1; $index <= $count; $index++) {
            if (!$stream->isWritable()) {
                break;
            }

            if (!$stream->send([
                'index' => $index,
                'total' => $count,
                'message' => $message,
                'time' => date('Y-m-d H:i:s'),
            ], autoId: true)) {
                // write 返回 false 时，通常表示客户端已关闭连接
                break;
            }

            if ($index < $count) {
                Coroutine::sleep($interval);
            }
        }

        if ($stream->isWritable()) {
            $stream->send(['status' => 'done'], event: 'complete', autoId: true);
        }

        $stream->end();
    }

    /**
     * @Api 测试 SSE 长连接心跳与 tick 事件保活
     *
     * curl -N 'http://127.0.0.1:9501/api/sse/tick?seconds=10'
     */
    #[StreamResponse]
    public function tick(RequestInput $requestInput, ResponseOutput $responseOutput): void
    {
        $seconds = max(1, (int) $requestInput->input('seconds', 30));
        $stream = new EventStream($responseOutput);

        $stream->send([
            'status' => 'connected',
            'seconds' => $seconds,
        ], retry: 3000, autoId: true);

        for ($second = 1; $second <= $seconds; $second++) {
            if (!$stream->isWritable()) {
                break;
            }

            // 每秒发送一次 tick 事件
            if (!$stream->send([
                'second' => $second,
                'time' => date('Y-m-d H:i:s'),
            ], event: 'tick', autoId: true)) {
                break;
            }

            // 中间再补一条 comment 心跳，避免代理层因空闲断开
            if (!$stream->heartbeat()) {
                break;
            }

            if ($second < $seconds) {
                Coroutine::sleep(1);
            }
        }

        if ($stream->isWritable()) {
            $stream->send(['status' => 'done'], event: 'complete', autoId: true);
        }

        $stream->end();
    }
}
