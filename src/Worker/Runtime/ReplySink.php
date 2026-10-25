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

declare(strict_types=1);

namespace Swoolefy\Worker\Runtime;

use Swoolefy\Worker\Dto\ControlResponse;

/**
 * 一条 CLI 连接的回包对象：显式非阻塞 + 出站缓冲 + 排空后关闭。
 *
 * 不变量：一个请求恰好回一帧；首个响应入队后 hasResponded() 恒为 true，
 * 后续响应直接丢弃。处理链上不得把回包推迟到下一个 tick——真要推迟，
 * 调用方必须自己捕获 ReplySink 并负责超时关闭。
 */
final class ReplySink
{
    /** 出站缓冲超过该值视为异常客户端，直接断开（字节） */
    private const MAX_PENDING_BYTES = 2097152;

    /** @var resource|null */
    private $stream;

    private string $out = '';

    private bool $closed = false;

    private bool $responded = false;

    private bool $closeAfterFlush = false;

    private string $nonce = '';

    /** @var array<int, string> 已入队的完整帧，便于断言与排查 */
    private array $sentFrames = [];

    /**
     * @param resource|null $stream   连接流；null 仅用于单测（入队但不写内核）
     * @param callable|null $onClosed 连接真正关闭后回调，由服务端清理 Event/timer
     */
    public function __construct($stream = null, private $onClosed = null)
    {
        $this->stream = $stream;
    }

    public function setNonce(string $nonce): void
    {
        $this->nonce = $nonce;
    }

    public function getNonce(): string
    {
        return $this->nonce;
    }

    /**
     * 回一帧纯文本响应，写出后关闭连接。
     */
    public function respondText(string $message, bool $ok = true): void
    {
        $this->respond($ok
            ? ControlResponse::success($message, '', $this->nonce)
            : ControlResponse::failure($message, $this->nonce));
    }

    /**
     * 回一帧带 data 的响应（status 表格），写出后关闭连接。
     */
    public function respondData(string $data, string $message = '', bool $ok = true): void
    {
        $this->respond($ok
            ? ControlResponse::success($message, $data, $this->nonce)
            : ControlResponse::failure($message, $this->nonce));
    }

    /**
     * 直写小帧并立刻关闭连接。专用于 STOP：shutdown 会在 Event::exit() 处终结进程，
     * 之后没有任何 tick 能继续排空缓冲。
     */
    public function respondNow(string $message, bool $ok = true, string $data = ''): void
    {
        $this->enqueue($ok
            ? ControlResponse::success($message, $data, $this->nonce)
            : ControlResponse::failure($message, $this->nonce));

        if ($this->stream !== null && $this->out !== '') {
            $bytes = @fwrite($this->stream, $this->out);
            if ($bytes !== false && $bytes > 0) {
                $this->out = (string) substr($this->out, $bytes);
            }
        }

        $this->close();
    }

    public function respond(ControlResponse $response): void
    {
        $this->enqueue($response);
        $this->closeAfterFlush = true;
        $this->flush();
    }

    /**
     * 把出站缓冲尽量写进内核；EAGAIN 时保留残料等可写事件。
     */
    public function flush(): void
    {
        if ($this->closed) {
            return;
        }

        if ($this->stream === null) {
            $this->out = '';
            $this->maybeCloseAfterFlush();
            return;
        }

        while ($this->out !== '') {
            $bytes = @fwrite($this->stream, $this->out);
            if ($bytes === false) {
                $this->close();
                return;
            }
            if ($bytes === 0) {
                return;
            }
            $this->out = (string) substr($this->out, $bytes);
        }

        $this->maybeCloseAfterFlush();
    }

    public function wantsWrite(): bool
    {
        return !$this->closed && $this->out !== '';
    }

    public function hasResponded(): bool
    {
        return $this->responded;
    }

    public function isClosed(): bool
    {
        return $this->closed;
    }

    /**
     * @return resource|null
     */
    public function getStream()
    {
        return $this->stream;
    }

    /**
     * @return array<int, string>
     */
    public function sentFrames(): array
    {
        return $this->sentFrames;
    }

    public function close(): void
    {
        if ($this->closed) {
            return;
        }
        $this->closed = true;
        $this->out = '';

        $stream = $this->stream;
        $this->stream = null;
        if (is_resource($stream)) {
            @\Swoole\Event::del($stream);
            @fclose($stream);
        }

        if ($this->onClosed !== null) {
            $onClosed = $this->onClosed;
            $this->onClosed = null;
            $onClosed($this);
        }
    }

    /**
     * 只入队首帧；编码失败时退化为错误帧，响应本身不受影响。
     */
    private function enqueue(ControlResponse $response): void
    {
        if ($this->closed || $this->responded) {
            return;
        }
        $this->responded = true;

        try {
            $frame = ControlFrame::encode($response->toJson(), ControlFrame::MAX_RESPONSE_BYTES);
        } catch (\Throwable $throwable) {
            $fallback = ControlResponse::failure('控制响应过大，已丢弃: ' . $throwable->getMessage(), $response->nonce);
            $frame = ControlFrame::encode($fallback->toJson(), ControlFrame::MAX_RESPONSE_BYTES);
        }

        $this->sentFrames[] = $frame;
        $this->out .= $frame;

        if (strlen($this->out) > self::MAX_PENDING_BYTES) {
            $this->close();
        }
    }

    private function maybeCloseAfterFlush(): void
    {
        if ($this->closeAfterFlush && $this->out === '') {
            $this->close();
        }
    }
}
