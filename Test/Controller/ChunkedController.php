<?php
namespace Test\Controller;

use Swoole\Coroutine;
use Swoolefy\Annotation\ChunkedResponse;
use Swoolefy\Core\Controller\BController;
use Swoolefy\Http\HttpChunkedResponse;
use Swoolefy\Http\RequestInput;
use Swoolefy\Http\ResponseOutput;

class ChunkedController extends BController
{
    /**
     * 测试 NDJSON 分块流（每行一个 JSON）。
     *
     * Route: GET /api/chunked/ndjson
     *
     ```bash
     curl -N 'http://127.0.0.1:9501/api/chunked/ndjson?count=3&interval=1'
     ```
     */
    #[ChunkedResponse]
    public function ndjson(RequestInput $requestInput, ResponseOutput $responseOutput): void
    {
        $count = max(1, (int) $requestInput->input('count', 10));
        $interval = max(0.1, (float) $requestInput->input('interval', 1));
        $message = (string) $requestInput->input('message', 'swoolefy chunked demo');

        $stream = new HttpChunkedResponse($responseOutput, 'application/x-ndjson; charset=utf-8');

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
     * 测试纯文本分块流逐行推送。
     *
     * Route: GET /api/chunked/text
     *
     ```bash
     curl -N 'http://127.0.0.1:9501/api/chunked/text?lines=5'
     ```
     */
    #[ChunkedResponse]
    public function text(RequestInput $requestInput, ResponseOutput $responseOutput): void
    {
        $lines = max(1, (int) $requestInput->input('lines', 10));

        $stream = new HttpChunkedResponse($responseOutput, 'text/plain; charset=utf-8');

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
