<?php

declare(strict_types=1);

namespace Swoolefy\Cmd\Infrastructure;

use Swoolefy\Exception\WorkerException;
use Swoolefy\Worker\Dto\ControlRequest;
use Swoolefy\Worker\Dto\ControlResponse;
use Swoolefy\Worker\Runtime\ControlFrame;

/**
 * Worker 控制面客户端：connect → 写一帧 → 读一帧 → close。
 *
 * 同步阻塞实现，CLI 与 HTTP（协程 hook 下自动挂起）共用；按帧长读数，
 * 绝不读到 EOF 为止——服务端回帧后立刻关闭连接。
 */
final class ControlClient
{
    public function __construct(private float $connectTimeout = 5.0)
    {
    }

    /**
     * 发送一个请求并等待响应。传输失败不抛异常，返回 ok=false 的响应，
     * 调用方可以直接把 message 打印给用户。
     */
    public function request(
        string $socketPath,
        ControlRequest $request,
        float $timeoutSeconds = 10.0
    ): ControlResponse {
        if ($socketPath === '') {
            return ControlResponse::failure('Worker 控制面 socket 路径为空');
        }

        $timeoutSeconds = max(0.1, $timeoutSeconds);
        $stream = $this->connect($socketPath, $error);
        if ($stream === null) {
            return ControlResponse::failure($error);
        }

        try {
            stream_set_blocking($stream, true);
            ControlFrame::write($stream, $request->toJson(), ControlFrame::MAX_REQUEST_BYTES);

            return ControlResponse::fromJson($this->readFrame($stream, $timeoutSeconds));
        } catch (\Throwable $throwable) {
            return ControlResponse::failure($throwable->getMessage());
        } finally {
            @fclose($stream);
        }
    }

    /**
     * @return resource|null
     */
    private function connect(string $socketPath, ?string &$error)
    {
        $errno = 0;
        $errstr = '';

        // 连接失败是 warning；局部吞掉，避免被框架的 error handler 转成异常
        set_error_handler(static fn (): bool => true);
        try {
            $stream = stream_socket_client(
                'unix://' . $socketPath,
                $errno,
                $errstr,
                $this->connectTimeout
            );
        } finally {
            restore_error_handler();
        }

        if (!is_resource($stream)) {
            $error = "连接 Worker 控制面失败({$errno}): " . ($errstr !== '' ? $errstr : $socketPath);

            return null;
        }

        return $stream;
    }

    /**
     * @param resource $stream
     */
    private function readFrame($stream, float $timeoutSeconds): string
    {
        $deadline = microtime(true) + $timeoutSeconds;
        $buffer = '';

        while (true) {
            $remaining = $deadline - microtime(true);
            if ($remaining <= 0) {
                throw new WorkerException('等待 Worker 控制面响应超时');
            }

            $seconds = (int) floor($remaining);
            $microseconds = (int) (($remaining - $seconds) * 1000000);
            stream_set_timeout($stream, $seconds, $microseconds);

            $payload = ControlFrame::tryRead($stream, $buffer, ControlFrame::MAX_RESPONSE_BYTES);
            if ($payload !== null && $payload !== false) {
                return $payload;
            }
            if ($payload === false) {
                throw new WorkerException('Worker 控制面连接已关闭，未收到完整响应');
            }

            $meta = stream_get_meta_data($stream);
            if (!empty($meta['timed_out'])) {
                throw new WorkerException('等待 Worker 控制面响应超时');
            }
        }
    }
}
