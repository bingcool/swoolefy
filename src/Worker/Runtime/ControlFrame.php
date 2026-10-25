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

use Swoolefy\Exception\WorkerException;

/**
 * CLI ↔ Worker Master 控制通道的帧编解码：4 字节大端长度 + JSON 负载。
 *
 * 读侧必须按帧长取数，不能读到 EOF 为止；半包与 EOF 用 null / false 区分，
 * 否则客户端中途断开只能等连接 deadline 才释放。
 *
 * 请求与响应共用一个上限：响应里最大的内容是 status 文本，实测远小于 1 MiB；
 * 上限压低后 Master 才敢把响应整帧放进内存再排空。
 */
final class ControlFrame
{
    /** 请求帧最大负载（字节） */
    public const MAX_REQUEST_BYTES = 1048576;

    /** 响应帧最大负载（字节） */
    public const MAX_RESPONSE_BYTES = 1048576;

    /** 4 字节大端长度头 */
    private const HEADER_BYTES = 4;

    /**
     * 编码完整一帧并校验长度上限。
     */
    public static function encode(string $payload, int $maxBytes = self::MAX_REQUEST_BYTES): string
    {
        $length = strlen($payload);
        if ($length < 1) {
            throw new WorkerException('控制帧负载不能为空');
        }
        if ($length > $maxBytes) {
            throw new WorkerException("控制帧负载超限: {$length} 字节，上限 {$maxBytes} 字节");
        }

        return pack('N', $length) . $payload;
    }

    /**
     * 阻塞写完整一帧；短写继续写，写不出去即抛异常，绝不静默丢弃。
     *
     * @param resource $stream
     */
    public static function write($stream, string $payload, int $maxBytes = self::MAX_REQUEST_BYTES): void
    {
        $frame = self::encode($payload, $maxBytes);
        $total = strlen($frame);
        $written = 0;

        while ($written < $total) {
            $bytes = @fwrite($stream, substr($frame, $written));
            if ($bytes === false || $bytes === 0) {
                throw new WorkerException('控制帧写入失败: 连接不可写');
            }
            $written += $bytes;
        }
    }

    /**
     * 读一帧：凑齐返回负载；半包返回 null 且残料留在 $buffer；
     * 对端关闭或读写失败返回 false；长度头非法或超限抛 WorkerException。
     *
     * 非阻塞流上 fread 返回空串属于「暂时没数据」；阻塞流上靠 stream timeout。
     *
     * @param resource $stream
     * @return string|null|false
     */
    public static function tryRead($stream, string &$buffer, int $maxBytes = self::MAX_REQUEST_BYTES): string|null|false
    {
        // 粘包时缓冲里可能已有完整帧，先提取，避免多发一次阻塞读
        $payload = self::extract($buffer, $maxBytes);
        if ($payload !== null) {
            return $payload;
        }

        $chunk = @fread($stream, 65536);
        if ($chunk === false) {
            return false;
        }

        if ($chunk === '') {
            return feof($stream) ? false : null;
        }

        $buffer .= $chunk;

        return self::extract($buffer, $maxBytes);
    }

    /**
     * 从缓冲中取一帧；半包返回 null，长度非法抛异常。
     */
    private static function extract(string &$buffer, int $maxBytes): ?string
    {
        if (strlen($buffer) < self::HEADER_BYTES) {
            return null;
        }

        $length = (int) unpack('Nlength', substr($buffer, 0, self::HEADER_BYTES))['length'];
        if ($length < 1 || $length > $maxBytes) {
            throw new WorkerException("控制帧长度非法: {$length} 字节，上限 {$maxBytes} 字节");
        }

        if (strlen($buffer) < self::HEADER_BYTES + $length) {
            return null;
        }

        $payload = substr($buffer, self::HEADER_BYTES, $length);
        $buffer = (string) substr($buffer, self::HEADER_BYTES + $length);

        return $payload;
    }
}
