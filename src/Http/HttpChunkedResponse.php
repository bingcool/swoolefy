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

namespace Swoolefy\Http;

use Swoole\Http\Response as SwooleResponse;
use Swoole\Http\Status;
use Swoolefy\Core\Application;

/**
 * 通用 HTTP 分块流式响应（Chunked Transfer-Encoding）。
 *
 * 适用于大文件导出、日志 tail、NDJSON 流、逐段 JSON 输出等非 SSE 场景。
 * 与 EventStream 共用同一套模式：先设 chunked 响应头 + setEnd()，再 write() 分块输出。
 *
 * 典型用法：
 * ```php
 * public function export(ResponseOutput $response): void
 * {
 *     $stream = new HttpChunkedResponse($response, 'application/x-ndjson');
 *     foreach ($this->rows() as $row) {
 *         if (!$stream->writeJson($row) || !$stream->write("\n")) {
 *             break;
 *         }
 *     }
 *     $stream->end();
 * }
 * ```
 * ```
 * // 大文件流式输出
 * $stream = new HttpChunkedResponse($response, 'text/csv', [
 *      'Content-Disposition' => 'attachment; filename="export.csv"',
 * ]);
 * $stream->write("id,name\n");
 * $stream->writeFile('/data/large-export.csv');
 * $stream->end();
 * ```
 */
class HttpChunkedResponse
{
    /**
     * 初始化后不允许被额外 headers 覆盖的关键响应头。
     */
    private const RESERVED_HEADERS = [
        'content-type',
        'transfer-encoding',
        'cache-control',
        'connection',
    ];

    private SwooleResponse $response;

    /**
     * 是否已主动结束流式连接。
     */
    private bool $ended = false;

    /**
     * @param SwooleResponse|ResponseOutput $response Swoole 原生响应或 swoolefy 响应包装
     * @param string $contentType 响应 Content-Type，默认 application/octet-stream
     * @param array<string, string> $headers 额外响应头（不会覆盖 chunked 必需头）
     * @param int $status HTTP 状态码，默认 200
     */
    public function __construct(
        SwooleResponse|ResponseOutput $response,
        string $contentType = 'application/octet-stream',
        array $headers = [],
        int $status = Status::OK
    ) {
        $this->response = $response instanceof ResponseOutput
            ? $response->getSwooleResponse()
            : $response;

        if ($status > 0) {
            $this->response->status($status);
        }

        // 先发送 chunked 响应头，再开始分块写入
        $this->sendChunkedHeaders($contentType, $headers);

        // 标记当前请求已输出响应，避免 HttpRoute::emitActionResult() 再次写 JSON
        $this->markRequestEnded();
    }

    /**
     * 写入原始数据块。
     *
     * @return bool false 表示客户端已断开或连接不可写
     */
    public function write(string $data): bool
    {
        if ($this->ended || !$this->response->isWritable()) {
            return false;
        }

        if ($data === '') {
            return true;
        }

        // Swoole write() 返回 false 时，通常表示客户端已关闭连接
        return $this->response->write($data) !== false;
    }

    /**
     * 写入一行文本（自动追加换行符）。
     */
    public function writeln(string $line = ''): bool
    {
        return $this->write($line . "\n");
    }

    /**
     * 将数组/对象编码为 JSON 后写入（不自动换行，适合自行拼接 NDJSON）。
     */
    public function writeJson(mixed $data, int $flags = JSON_UNESCAPED_UNICODE): bool
    {
        $encoded = json_encode($data, $flags);
        if ($encoded === false) {
            return false;
        }

        return $this->write($encoded);
    }

    /**
     * 按固定块大小读取本地文件并流式输出（适合大文件下载前的自定义流）。
     *
     * @return bool false 表示读取失败、客户端断开或写入失败
     */
    public function writeFile(string $filePath, int $chunkSize = 2097152): bool
    {
        if (!is_readable($filePath)) {
            return false;
        }

        $handle = fopen($filePath, 'rb');
        if ($handle === false) {
            return false;
        }

        try {
            while (!feof($handle)) {
                $chunk = fread($handle, $chunkSize);
                if ($chunk === false) {
                    return false;
                }
                if ($chunk === '') {
                    break;
                }
                if (!$this->write($chunk)) {
                    return false;
                }
            }
        } finally {
            fclose($handle);
        }

        return true;
    }

    /**
     * 结束分块流式连接。
     *
     * @param string|null $data 结束前可选写入最后一段数据
     */
    public function end(?string $data = null): void
    {
        if ($this->ended) {
            return;
        }

        if ($data !== null && $data !== '') {
            $this->write($data);
        }

        if ($this->response->isWritable()) {
            $this->response->end();
        }

        $this->ended = true;
    }

    /**
     * 当前连接是否仍可写入。
     */
    public function isWritable(): bool
    {
        return !$this->ended && $this->response->isWritable();
    }

    public function getSwooleResponse(): SwooleResponse
    {
        return $this->response;
    }

    /**
     * 初始化 chunked 流式响应头。
     *
     * @param array<string, string> $headers
     */
    protected function sendChunkedHeaders(string $contentType, array $headers): void
    {
        $this->response->header('Content-Type', $contentType);
        // 显式 chunked，配合 Swoole Response::write() 流式输出
        $this->response->header('Transfer-Encoding', 'chunked');
        // 实时流默认不缓存；静态导出可在 $headers 中覆盖
        $this->response->header('Cache-Control', 'no-cache, no-transform');
        // 禁用 Nginx 等反向代理缓冲，否则客户端看不到逐段数据
        $this->response->header('X-Accel-Buffering', 'no');
        $this->response->header('Connection', 'keep-alive');

        foreach ($headers as $name => $value) {
            if ($value === null || $value === '') {
                continue;
            }
            if (in_array(strtolower((string) $name), self::RESERVED_HEADERS, true)) {
                continue;
            }
            $this->response->header((string) $name, (string) $value);
        }
    }

    /**
     * 通知 swoolefy 当前请求已由流式响应接管。
     */
    protected function markRequestEnded(): void
    {
        $app = Application::getApp();
        if (is_object($app)) {
            $app->setEnd();
        }
    }
}
