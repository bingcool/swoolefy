<?php

namespace Swoolefy\Websocket;

use Swoole\WebSocket\Frame;
use Swoolefy\Core\Swfy;

/**
 * WebSocket 分片帧重组器（RFC 6455 §5.4）。
 *
 * ## 职责
 * Swoole 可能把一条逻辑消息拆成多帧交给业务：首帧带 TEXT/BINARY opcode 且 `finish=false`，
 * 中间为 CONTINUATION，末帧 `finish=true`。本类在进入 Socket.IO / 原生 WS 业务前把分片拼回完整 Frame。
 *
 * ## 状态机（按 fd）
 * | 收到 | 行为 |
 * |------|------|
 * | TEXT/BINARY + finish=false | 写入 `$buffers[$fd]`，返回 null |
 * | CONTINUATION + finish=false | 追加 data，更新时间戳，返回 null |
 * | CONTINUATION/数据帧 + finish=true | 拼出完整 Frame（opcode 取自首帧），清空缓冲 |
 * | 完整 TEXT/BINARY（finish=true） | 不建缓冲，直接返回该帧 |
 * | PING/PONG/CLOSE | **原样返回**，且**不碰**半包缓冲（控制帧可插在分片中间） |
 *
 * ## 生命周期与清理
 * - 连接 `close`：{@see WebsocketServer} 调用 {@see clear()}
 * - 协议错误 / 超限：抛 {@see WebsocketFrameException} 并清空该 fd 缓冲
 * - 半包超时：Worker 心跳 tick 调用 {@see cleanupStale()}（防僵尸半包占内存）
 *
 * ## 配置
 * - `websocket.max_fragment_payload`：单连接累计 payload 上限（字节）
 * - 未配时回退 `setting.package_max_length`，再默认 2MB
 * - `websocket.fragment_idle_timeout`：半包空闲秒数，默认 {@see DEFAULT_FRAGMENT_IDLE_TIMEOUT}
 */
class WebsocketFrameAssembler
{
    /** RFC 6455 CONTINUATION opcode（续帧本身不携带业务 opcode） */
    public const OPCODE_CONTINUE = 0x0;

    /** 默认半包空闲超时（秒）；可被 websocket.fragment_idle_timeout 覆盖 */
    public const DEFAULT_FRAGMENT_IDLE_TIMEOUT = 60;

    /**
     * 按连接 fd 隔离的半包缓冲。
     *
     * - opcode：首帧业务类型（TEXT/BINARY），拼完整帧时写回
     * - data：已收到的 payload 拼接
     * - updated_at：最近一次追加/写入时间，供 {@see cleanupStale()} 老化
     *
     * @var array<int, array{opcode:int,data:string,updated_at:int}>
     */
    private static array $buffers = [];

    /**
     * 喂入一帧，尝试输出重组后的完整消息帧。
     *
     * 调用方（{@see WebsocketServer} message 回调）应：
     * - 返回 null：继续等后续分片，不要进业务
     * - 返回 Frame：进入 Socket.IO / 原生 WS 分发
     * - 抛 WebsocketFrameException：断连并 clear（由外层 catch）
     *
     * @return Frame|null 完整帧；null 表示该 fd 仍在等待后续分片
     */
    public static function feed(Frame $frame): ?Frame
    {
        $fd = (int) $frame->fd;
        $opcode = (int) $frame->opcode;
        $finish = (bool) $frame->finish;
        $data = (string) $frame->data;

        // 控制帧可插在分片序列中间：必须原样上送，且保留半包缓冲
        if (self::isControlOpcode($opcode)) {
            return $frame;
        }

        // 续帧：依赖已有首帧缓冲
        if ($opcode === self::OPCODE_CONTINUE) {
            return self::feedContinuation($fd, $data, $finish);
        }

        // 数据帧：可能是分片首帧，也可能是单帧完整消息
        if ($opcode === WEBSOCKET_OPCODE_TEXT || $opcode === WEBSOCKET_OPCODE_BINARY) {
            return self::feedDataFrame($fd, $opcode, $data, $finish);
        }

        // 未知 opcode：清掉可能残留的半包，避免脏状态影响后续帧
        self::clear($fd);

        return $frame;
    }

    /**
     * 清除指定连接的半包缓冲。
     *
     * 典型调用点：连接 close、分片协议错误、payload 超限之后。
     */
    public static function clear(int $fd): void
    {
        unset(self::$buffers[$fd]);
    }

    /**
     * 清空全部半包缓冲（Worker 热重启 / 单测隔离）。
     */
    public static function reset(): void
    {
        self::$buffers = [];
    }

    /**
     * 清除空闲超过阈值的半包缓冲（防御性资源治理）。
     *
     * 仅依赖 close/错误路径时，恶意或异常客户端可能留下永不完成的半包；
     * 由心跳扫描定期调用本方法。超时只删缓冲，不断开连接（连接空闲由
     * {@see WebsocketConnectionManager::disconnectExpired()} 另责）。
     *
     * @param int $maxIdleSeconds 空闲秒数；≤0 时读配置 / 默认值
     * @return int 本次清理的 fd 数量
     */
    public static function cleanupStale(int $maxIdleSeconds = 0): int
    {
        if ($maxIdleSeconds <= 0) {
            $maxIdleSeconds = self::fragmentIdleTimeout();
        }
        if ($maxIdleSeconds <= 0 || self::$buffers === []) {
            return 0;
        }

        $deadline = time() - $maxIdleSeconds;
        $removed = 0;
        foreach (self::$buffers as $fd => $buffer) {
            $updatedAt = (int) ($buffer['updated_at'] ?? 0);
            // updated_at 缺失或为 0 视为异常条目，不误删（正常路径总会写入时间戳）
            if ($updatedAt > 0 && $updatedAt < $deadline) {
                unset(self::$buffers[$fd]);
                $removed++;
            }
        }

        return $removed;
    }

    /**
     * 当前半包缓冲条数（测试断言 / 指标观测）。
     */
    public static function pendingCount(): int
    {
        return count(self::$buffers);
    }

    /**
     * 处理 TEXT / BINARY 数据帧。
     *
     * - finish=false：必须是分片**首帧**；若该 fd 已有缓冲则协议违例
     * - finish=true：单帧完整消息；顺带清掉该 fd 残留缓冲后直接返回
     *
     * @return Frame|null null=已缓存首片，等待 CONTINUATION
     */
    private static function feedDataFrame(int $fd, int $opcode, string $data, bool $finish): ?Frame
    {
        if (!$finish) {
            // 同一连接上不允许重叠的分片序列（未收齐又开新首帧）
            if (isset(self::$buffers[$fd])) {
                throw new WebsocketFrameException('unexpected data frame while fragment buffer exists');
            }
            self::assertPayloadSize($fd, $data);
            self::$buffers[$fd] = [
                'opcode' => $opcode, // 续帧无 opcode，拼完整帧时用此值
                'data' => $data,
                'updated_at' => time(),
            ];

            return null;
        }

        // 完整单帧：丢弃可能残留的半包，避免脏缓冲污染
        self::clear($fd);

        return self::buildFrame($fd, $opcode, $data);
    }

    /**
     * 处理 CONTINUATION 续帧。
     *
     * 必须先有 TEXT/BINARY 首帧缓冲；末片 finish=true 时用首帧 opcode 组装完整 Frame。
     *
     * @return Frame|null null=仍有后续分片
     */
    private static function feedContinuation(int $fd, string $data, bool $finish): ?Frame
    {
        // 孤儿续帧：无首帧却收到 CONTINUATION
        if (!isset(self::$buffers[$fd])) {
            throw new WebsocketFrameException('continuation frame without initial fragment');
        }

        self::$buffers[$fd]['data'] .= $data;
        self::$buffers[$fd]['updated_at'] = time(); // 刷新空闲计时，避免慢分片被误清
        // 校验累计长度（非整帧长度）：防止分片拼接撑爆内存
        self::assertPayloadSize($fd, self::$buffers[$fd]['data']);

        if (!$finish) {
            return null;
        }

        $buffer = self::$buffers[$fd];
        self::clear($fd);

        // opcode 取自首帧；续帧自身 opcode 恒为 0
        return self::buildFrame($fd, (int) $buffer['opcode'], (string) $buffer['data']);
    }

    /**
     * 构造业务侧可见的完整 Frame（finish 恒为 true）。
     */
    private static function buildFrame(int $fd, int $opcode, string $data): Frame
    {
        $frame = new Frame();
        $frame->fd = $fd;
        $frame->opcode = $opcode;
        $frame->data = $data;
        $frame->finish = true;

        return $frame;
    }

    /**
     * 是否为控制帧 opcode（CLOSE / PING / PONG）。
     *
     * 控制帧不得参与分片拼接，也不应清空半包缓冲。
     */
    private static function isControlOpcode(int $opcode): bool
    {
        return in_array($opcode, [
            WEBSOCKET_OPCODE_CLOSE,
            WEBSOCKET_OPCODE_PING,
            WEBSOCKET_OPCODE_PONG,
        ], true);
    }

    /**
     * 累计 payload 超限则清缓冲并抛错，由外层 disconnect。
     *
     * @throws WebsocketFrameException
     */
    private static function assertPayloadSize(int $fd, string $data): void
    {
        if (strlen($data) > self::maxPayload()) {
            self::clear($fd);
            throw new WebsocketFrameException('fragment payload exceeds max_fragment_payload');
        }
    }

    /**
     * 解析单连接分片累计 payload 上限（字节）。
     *
     * 优先级：websocket.max_fragment_payload → setting.package_max_length → 2MB。
     * Swfy 未就绪（如单测）时走默认 2MB，避免抛错阻断重组逻辑。
     */
    private static function maxPayload(): int
    {
        try {
            $conf = Swfy::getConf();
            $websocket = is_array($conf['websocket'] ?? null) ? $conf['websocket'] : [];
            $max = (int) ($websocket['max_fragment_payload'] ?? 0);
            if ($max > 0) {
                return $max;
            }

            $setting = is_array($conf['setting'] ?? null) ? $conf['setting'] : [];

            return (int) ($setting['package_max_length'] ?? 2 * 1024 * 1024);
        } catch (\Throwable $throwable) {
            return 2 * 1024 * 1024;
        }
    }

    /**
     * 半包空闲超时秒数：配置 fragment_idle_timeout，否则 {@see DEFAULT_FRAGMENT_IDLE_TIMEOUT}。
     */
    private static function fragmentIdleTimeout(): int
    {
        try {
            $conf = Swfy::getConf();
            $websocket = is_array($conf['websocket'] ?? null) ? $conf['websocket'] : [];
            $timeout = (int) ($websocket['fragment_idle_timeout'] ?? 0);
            if ($timeout > 0) {
                return $timeout;
            }
        } catch (\Throwable $throwable) {
            // 配置不可用时使用类常量默认值
        }

        return self::DEFAULT_FRAGMENT_IDLE_TIMEOUT;
    }
}
