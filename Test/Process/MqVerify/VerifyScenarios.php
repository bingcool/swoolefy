<?php

namespace Test\Process\MqVerify;

use Swoolefy\Support\Kafka\KafkaCommitMode;

/**
 * 全部验证场景和期望结果。Event::onInit() 按这里注册进程，VerifyReporter 按这里判定。
 */
final class VerifyScenarios
{
    public const ROCKETMQ_TOPIC = 'mq-verify';

    /**
     * @return list<array{name: string, broker: string, behaviour: string, mode: string, class: class-string}>
     */
    public static function all(): array
    {
        $list = [];
        foreach ([KafkaCommitMode::AFTER_HANDLED, KafkaCommitMode::AFTER_HANDLED_ASYNC, KafkaCommitMode::BEFORE_HANDLE] as $mode) {
            foreach (['normal', 'exception', 'kill'] as $behaviour) {
                $list[] = [
                    'name' => 'kafka-' . $mode . '-' . $behaviour,
                    'broker' => 'kafka',
                    'behaviour' => $behaviour,
                    'mode' => $mode,
                    'class' => KafkaVerifyConsumer::class,
                ];
            }
        }
        foreach (['normal', 'exception', 'kill'] as $behaviour) {
            $list[] = [
                'name' => 'amqp-' . $behaviour,
                'broker' => 'amqp',
                'behaviour' => $behaviour,
                'mode' => '',
                'class' => AmqpVerifyConsumer::class,
            ];
        }
        foreach (['normal', 'exception', 'kill', 'deadline'] as $behaviour) {
            $list[] = [
                'name' => 'rocketmq-' . $behaviour,
                'broker' => 'rocketmq',
                'behaviour' => $behaviour,
                'mode' => '',
                'class' => RocketmqVerifyConsumer::class,
            ];
        }

        return $list;
    }

    public static function kafkaTopic(string $run, string $name): string
    {
        return 'mqv-' . $run . '-' . $name;
    }

    public static function amqpQueue(string $run, string $name): string
    {
        return 'mqv_' . $run . '_' . str_replace('-', '_', $name);
    }

    public static function rocketmqGroup(string $run, string $name): string
    {
        return 'mqv-' . $run . '-' . $name;
    }

    public static function rocketmqTag(string $run, string $name): string
    {
        return 'mqv_' . $run . '_' . str_replace('-', '_', $name);
    }

    /**
     * @param array{name: string, broker: string, behaviour: string, mode: string} $scenario
     * @param list<array<string, mixed>> $events
     * @return array{0: bool, 1: string}
     */
    public static function evaluate(array $scenario, array $events, ?int $kafkaCommitted): array
    {
        $recv = [];
        $done = [];
        foreach ($events as $event) {
            $p = (string) ($event['p'] ?? '');
            if (($event['e'] ?? '') === 'recv') {
                $recv[$p][] = $event;
            } elseif (($event['e'] ?? '') === 'done') {
                $done[$p][] = $event;
            }
        }
        $r = static fn (string $p): int => count($recv[$p] ?? []);
        $d = static fn (string $p): int => count($done[$p] ?? []);
        $attempts = static fn (string $p): array => array_map(static fn ($e) => (int) ($e['attempt'] ?? 0), $recv[$p] ?? []);
        $redelivered = static fn (string $p): array => array_map(static fn ($e) => (bool) ($e['redelivered'] ?? false), $recv[$p] ?? []);

        $summary = [];
        foreach (['m0', 'm1', 'm2', 'm3'] as $p) {
            if ($r($p) === 0 && $d($p) === 0) {
                continue;
            }
            $part = $p . ' recv=' . $r($p) . ' done=' . $d($p);
            if ($scenario['broker'] === 'rocketmq') {
                $part .= ' attempts=' . implode('/', $attempts($p));
            }
            if ($scenario['broker'] === 'amqp') {
                $part .= ' redelivered=' . implode('/', array_map(static fn ($b) => $b ? 'Y' : 'N', $redelivered($p)));
            }
            $summary[] = $part;
        }
        if ($kafkaCommitted !== null) {
            $summary[] = 'committed=' . $kafkaCommitted;
        }
        $detail = implode(', ', $summary) ?: 'no events';

        $b = $scenario['behaviour'];
        $ok = match ($scenario['broker']) {
            'kafka' => self::kafkaOk($scenario['mode'], $b, $r, $d, $kafkaCommitted),
            'amqp' => self::amqpOk($b, $r, $d, $redelivered),
            'rocketmq' => self::rocketmqOk($b, $r, $d, $attempts),
        };

        return [$ok, $detail];
    }

    private static function kafkaOk(string $mode, string $b, \Closure $r, \Closure $d, ?int $committed): bool
    {
        if ($committed !== 3 || $d('m1') !== 1 || $d('m3') !== 1) {
            return false;
        }
        $atMostOnce = $mode === KafkaCommitMode::BEFORE_HANDLE;

        return match ($b) {
            'normal' => $r('m1') === 1 && $r('m2') === 1 && $r('m3') === 1 && $d('m2') === 1,
            // 至少一次：m2 原地重试一次后成功；至多一次：m2 已先提交，抛异常后丢弃
            'exception' => $atMostOnce
                ? $r('m2') === 1 && $d('m2') === 0
                : $r('m2') === 2 && $d('m2') === 1,
            // 至少一次：m2 处理中被 kill，重启后再投；异步提交时 m1 可能也再投一次
            // 至多一次：m2 处理前已提交，kill 后不再投递
            'kill' => $atMostOnce
                ? $r('m1') === 1 && $r('m2') === 1 && $d('m2') === 0
                : $r('m2') === 2 && $d('m2') === 1
                    && ($mode === KafkaCommitMode::AFTER_HANDLED_ASYNC ? $r('m1') <= 2 : $r('m1') === 1),
        };
    }

    private static function amqpOk(string $b, \Closure $r, \Closure $d, \Closure $redelivered): bool
    {
        if ($d('m1') !== 1 || $d('m2') !== 1 || $d('m3') !== 1 || $r('m1') !== 1 || $r('m3') !== 1) {
            return false;
        }

        return match ($b) {
            'normal' => $r('m2') === 1 && $redelivered('m2') === [false],
            // 抛异常后不 ack，同一条在内存里原地再处理，Broker 没有重投
            'exception' => $r('m2') === 2 && $redelivered('m2') === [false, false],
            // 未 ack 的 m2 随连接断开回到队列，重启后 redelivered=true
            'kill' => $r('m2') === 2 && $redelivered('m2') === [false, true],
        };
    }

    private static function rocketmqOk(string $b, \Closure $r, \Closure $d, \Closure $attempts): bool
    {
        if ($b === 'deadline') {
            // m3 落在本批截止时间之后，不处理也不 ack，不可见时间到期后第二次投递才处理
            return $attempts('m0') === [1] && $attempts('m1') === [1] && $attempts('m2') === [1]
                && $d('m3') === 1 && $r('m3') >= 1 && min($attempts('m3')) >= 2;
        }
        if ($attempts('m1') !== [1] || $r('m3') !== 1 || $d('m1') !== 1 || $d('m3') !== 1) {
            return false;
        }
        // kill 时 m3 若和 m2 在同一批，会随 m2 一起等不可见时间到期，第一次处理就是第 2 次投递
        if ($b !== 'kill' && $attempts('m3') !== [1]) {
            return false;
        }

        return match ($b) {
            'normal' => $attempts('m2') === [1] && $d('m2') === 1,
            // 未 ack 的 m2 在不可见时间结束后由 RocketMQ 第二次投递
            'exception', 'kill' => $attempts('m2') === [1, 2] && $d('m2') === 1,
        };
    }
}
