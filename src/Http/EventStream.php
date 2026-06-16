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
use Swoolefy\Core\Application;

/**
 * Server-Sent Events (SSE) 流式响应封装。
 *
 * 参考 Hyperf\Engine\Http\EventStream，适配 swoolefy 的 Swoole Response / ResponseOutput。
 *
 * 典型用法：
 * ```php
 * public function stream(ResponseOutput $response): void
 * {
 *     $stream = new EventStream($response);
 *     foreach ($this->producer() as $chunk) {
 *         if (!$stream->send($chunk)) {
 *             break; // 客户端已断开，停止推送
 *         }
 *         \Swoole\Coroutine::sleep(1);
 *     }
 *     $stream->end();
 * }
 * ```
 */
class EventStream
{
    /**
     * SSE 初始化后不允许被额外 headers 覆盖的关键响应头。
     */
    private const RESERVED_HEADERS = [
        'content-type',
        'transfer-encoding',
        'cache-control',
        'connection',
    ];

    private SwooleResponse $response;

    /**
     * 是否已主动结束 SSE 连接。
     */
    private bool $ended = false;

    /**
     * 自动递增事件 ID 计数器（配合 send(..., autoId: true) 使用）。
     */
    private int $eventId = 0;

    /**
     * @param SwooleResponse|ResponseOutput $response Swoole 原生响应或 swoolefy 响应包装
     * @param array<string, string> $headers 额外响应头（不会覆盖 SSE 必需头）
     */
    public function __construct(SwooleResponse|ResponseOutput $response, array $headers = [])
    {
        $this->response = $response instanceof ResponseOutput
            ? $response->getSwooleResponse()
            : $response;

        // 先发送 SSE 响应头，再开始分块写入
        $this->sendStreamHeaders($headers);

        // 标记当前请求已输出响应，避免 HttpRoute::emitActionResult() 再次写 JSON
        $this->markRequestEnded();
    }

    /**
     * 写入原始 SSE 数据块。
     *
     * 与 Hyperf 的 write() 类似，可直接写入已格式化的内容，例如：
     * `data: {"time":"..."}\n\n`
     *
     * @return bool false 表示客户端已断开或连接不可写
     */
    public function write(string $data): bool
    {
        if ($this->ended || !$this->response->isWritable()) {
            return false;
        }

        // Swoole write() 返回 false 时，通常表示前端已关闭 SSE 连接
        return $this->response->write($data) !== false;
    }

    /**
     * 按 SSE 规范发送一个完整事件。
     *
     * @param mixed $data 字符串直接发送；数组/对象自动 json_encode
     * @param string|null $event 事件类型（对应前端 addEventListener 的 type）
     * @param string|null $id 事件 ID（浏览器断线重连时会带上 Last-Event-ID）
     * @param int|null $retry 建议重连间隔（毫秒）
     * @param bool $autoId 未传 id 时自动递增
     */
    public function send(
        mixed $data,
        ?string $event = null,
        ?string $id = null,
        ?int $retry = null,
        bool $autoId = false
    ): bool {
        if ($autoId && ($id === null || $id === '')) {
            $id = (string) ++$this->eventId;
        }

        return $this->write($this->formatEvent($data, $event, $id, $retry));
    }

    /**
     * 发送 SSE 注释行（以冒号开头），常用于心跳保活。
     */
    public function comment(string $comment = ''): bool
    {
        $normalized = str_replace(["\r\n", "\r"], "\n", $comment);
        $lines = array_map(static fn (string $line): string => ':' . $line, explode("\n", $normalized));

        return $this->write(implode("\n", $lines) . "\n\n");
    }

    /**
     * 发送空注释心跳，避免中间代理因长时间无数据而断开连接。
     */
    public function heartbeat(): bool
    {
        return $this->comment('heartbeat');
    }

    /**
     * 结束 SSE 连接。
     *
     * @param string|null $data 结束前可选写入最后一段原始数据
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
     * 初始化 SSE 响应头。
     *
     * @param array<string, string> $headers
     */
    protected function sendStreamHeaders(array $headers): void
    {
        // SSE 协议要求的 Content-Type
        $this->response->header('Content-Type', 'text/event-stream; charset=utf-8');
        // 分块传输，配合 Swoole Response::write() 流式输出
        $this->response->header('Transfer-Encoding', 'chunked');
        // 禁止缓存，确保浏览器实时消费事件流
        $this->response->header('Cache-Control', 'no-cache, no-transform');
        // 禁用 Nginx 等反向代理缓冲，否则客户端看不到逐条推送
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
     * 通知 swoolefy 当前请求已由 SSE 接管响应。
     */
    protected function markRequestEnded(): void
    {
        $app = Application::getApp();
        if (is_object($app)) {
            $app->setEnd();
        }
    }

    /**
     * 将业务数据格式化为 SSE 事件文本。
     */
    protected function formatEvent(mixed $data, ?string $event, ?string $id, ?int $retry): string
    {
        $lines = [];

        if ($event !== null && $event !== '') {
            $lines[] = 'event: ' . $event;
        }
        if ($id !== null && $id !== '') {
            $lines[] = 'id: ' . $id;
        }
        if ($retry !== null && $retry > 0) {
            $lines[] = 'retry: ' . $retry;
        }

        // SSE 规范：data 字段每行都需要独立的 "data: " 前缀
        foreach ($this->normalizeDataLines($data) as $line) {
            $lines[] = 'data: ' . $line;
        }

        // 事件以空行结束（双换行）
        return implode("\n", $lines) . "\n\n";
    }

    /**
     * @return string[]
     */
    protected function normalizeDataLines(mixed $data): array
    {
        if (is_string($data)) {
            $text = $data;
        } elseif (is_array($data) || $data instanceof \JsonSerializable) {
            $text = json_encode($data, JSON_UNESCAPED_UNICODE);
            if ($text === false) {
                $text = (string) $data;
            }
        } elseif (is_object($data)) {
            $text = json_encode($data, JSON_UNESCAPED_UNICODE);
            if ($text === false) {
                $text = (string) $data;
            }
        } else {
            $text = (string) $data;
        }

        if ($text === '') {
            return [''];
        }

        return explode("\n", str_replace(["\r\n", "\r"], "\n", $text));
    }
}
