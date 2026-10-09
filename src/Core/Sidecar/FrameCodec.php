<?php

declare(strict_types=1);

namespace Swoolefy\Core\Sidecar;

use Swoole\Coroutine\Client;

/**
 * 4 字节大端长度 + 一个 JSON 对象。读写都要循环到完整。
 */
final class FrameCodec
{
    public const JSON_FLAGS = JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES;

    public static function encode(string $json, int $maxFrameBytes): string
    {
        $length = strlen($json);
        if ($length < 1 || $length > $maxFrameBytes) {
            throw new SidecarException('frame length out of range');
        }

        return pack('N', $length) . $json;
    }

    public static function writeAll(Client $client, string $bytes, float $deadline, bool &$wrote): void
    {
        $offset = 0;
        $total = strlen($bytes);
        while ($offset < $total) {
            $remain = $deadline - microtime(true);
            if ($remain <= 0) {
                if ($wrote) {
                    throw new OutcomeUnknownException('deadline exceeded');
                }
                throw new SidecarException('deadline exceeded before any byte');
            }
            $client->set(['timeout' => $remain]);
            $n = $client->send(substr($bytes, $offset), $remain);
            if (!is_int($n) || $n <= 0) {
                if ($wrote) {
                    throw new OutcomeUnknownException('short write');
                }
                throw new SidecarException('send failed before any byte');
            }
            $wrote = true;
            $offset += $n;
        }
    }

    public static function readFrame(Client $client, int $maxFrameBytes, float $deadline): string
    {
        // Coroutine\Client::recv() 可能一次返回超过请求长度的数据，多出来的字节要留给 body。
        $pending = '';
        $header = self::readExact($client, 4, $deadline, $pending);
        $unpacked = unpack('Nlen', $header);
        $length = (int) ($unpacked['len'] ?? 0);
        if ($length < 1 || $length > $maxFrameBytes) {
            throw new OutcomeUnknownException('response frame length out of range');
        }

        return self::readExact($client, $length, $deadline, $pending);
    }

    private static function readExact(Client $client, int $want, float $deadline, string &$pending): string
    {
        $buf = '';
        while (strlen($buf) < $want) {
            if ($pending !== '') {
                $buf .= $pending;
                $pending = '';
                continue;
            }
            $remain = self::applyTimeout($client, $deadline);
            // Coroutine\Client::recv 的参数是超时秒数，不是字节数，并且可能一次返回整帧。
            $chunk = $client->recv($remain);
            if (!is_string($chunk) || $chunk === '') {
                throw new OutcomeUnknownException('short read');
            }
            $buf .= $chunk;
        }
        if (strlen($buf) > $want) {
            $pending = substr($buf, $want);
            $buf = substr($buf, 0, $want);
        }

        return $buf;
    }

    private static function applyTimeout(Client $client, float $deadline): float
    {
        $remain = $deadline - microtime(true);
        if ($remain <= 0) {
            throw new OutcomeUnknownException('deadline exceeded');
        }
        $client->set(['timeout' => $remain]);

        return $remain;
    }
}
