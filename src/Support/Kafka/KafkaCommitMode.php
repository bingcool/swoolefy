<?php

declare(strict_types=1);

namespace Swoolefy\Support\Kafka;

/**
 * 消费端 offset 提交方式，由消费 handler 的 $commitMode 选择。三种都由框架显式提交，librdkafka 的 enable.auto.commit 始终关闭。
 *
 * AFTER_HANDLED（默认）：onMessage() 成功后同步 commit()。至少一次：失败原地重试同一条，进程崩溃后从上次提交处重新投递。
 *
 * AFTER_HANDLED_ASYNC：onMessage() 成功后 commitAsync()，不等 broker 应答，吞吐更高。仍是至少一次，
 * 但崩溃 / 重平衡时，已处理、异步提交还没落地的那几条会再投递，重复比 AFTER_HANDLED 多。提交结果由 offset_commit_cb 写日志。
 *
 * BEFORE_HANDLE：onMessage() 之前同步 commit()，提交成功才交给业务。至多一次：onMessage() 抛异常或进程崩溃，这条不会再投递，
 * 只记错误日志（含 topic / partition / offset）。提交失败时不调用 onMessage()，稍后重试提交，所以不会出现“没提交却已处理”。
 * 适合宁可丢、不能重复，又不方便做幂等的场景，例如发通知。
 *
 * 不提供 librdkafka 自动提交：它在 consume() 返回时就记下 offset 并定时提交，不看 onMessage() 结果，
 * 崩溃时既可能丢、也可能重复，两头都不保证。需要高吞吐用 AFTER_HANDLED_ASYNC，需要先提交用 BEFORE_HANDLE。
 */
final class KafkaCommitMode
{
    public const AFTER_HANDLED = 'after_handled';

    public const AFTER_HANDLED_ASYNC = 'after_handled_async';

    public const BEFORE_HANDLE = 'before_handle';

    public static function assertValid(string $mode): void
    {
        if (!in_array($mode, [self::AFTER_HANDLED, self::AFTER_HANDLED_ASYNC, self::BEFORE_HANDLE], true)) {
            throw new KafkaException('kafka commit mode is invalid: ' . $mode);
        }
    }
}
