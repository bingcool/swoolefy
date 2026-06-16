<?php
namespace Test\Controller;

use Swoole\Coroutine;
use Swoolefy\Core\Controller\BController;
use Swoolefy\Http\ChunkedResponse;
use Swoolefy\Http\RequestInput;
use Swoolefy\Http\ResponseOutput;

class ChunkedController extends BController
{
    /**
     * NDJSON 分块流示例：每行一个 JSON 对象。
     *
     * 访问示例：
     * GET /api/chunked/ndjson
     * GET /api/chunked/ndjson?count=5&interval=0.5&message=hello
     *
     * curl 测试：
     * curl -N "http://127.0.0.1:9501/api/chunked/ndjson?count=3"
     */
    public function ndjson(RequestInput $requestInput, ResponseOutput $responseOutput): void
    {
        $count = max(1, (int) $requestInput->input('count', 10));
        $interval = max(0.1, (float) $requestInput->input('interval', 1));
        $message = (string) $requestInput->input('message', 'swoolefy chunked demo');

        $stream = new ChunkedResponse($responseOutput, 'application/x-ndjson; charset=utf-8');

        for ($index = 1; $index <= $count; $index++) {
            if (!$stream->isWritable()) {
                break;
            }

            if (!$stream->writeJson([
                'index' => $index,
                'total' => $count,
                'message' => $message,
                'time' => date('Y-m-d H:i:s'),
            ]) || !$stream->write("\n")) {
                break;
            }

            if ($index < $count) {
                Coroutine::sleep($interval);
            }
        }

        $stream->end();
    }

    /**
     * 纯文本分块流示例。
     *
     * 访问示例：
     * GET /api/chunked/text?lines=10
     */
    public function text(RequestInput $requestInput, ResponseOutput $responseOutput): void
    {
        $lines = max(1, (int) $requestInput->input('lines', 10));

        $stream = new ChunkedResponse($responseOutput, 'text/plain; charset=utf-8');

        for ($line = 1; $line <= $lines; $line++) {
            if (!$stream->writeln(sprintf('[%s] chunked line %d/%d', date('Y-m-d H:i:s'), $line, $lines))) {
                break;
            }
            if ($line < $lines) {
                Coroutine::sleep(0.5);
            }
        }

        $stream->end();
    }
}
