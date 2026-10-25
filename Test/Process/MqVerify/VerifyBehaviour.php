<?php

declare(strict_types=1);

namespace Test\Process\MqVerify;

/**
 * 三种中间件共用的 onMessage() 行为：
 * - normal：直接成功
 * - exception：m2 第一次处理时抛异常
 * - kill：m2 第一次处理时进程 SIGKILL 自己，由 Swoole Manager 重新拉起
 * - deadline（仅 RocketMQ）：每条处理都较慢，用来触发“本批剩余消息不处理”
 */
final class VerifyBehaviour
{
    /**
     * @param array<string, mixed> $meta 中间件相关字段：attempt / redelivered / offset
     */
    public static function handle(string $run, string $scenario, string $behaviour, string $payload, array $meta): void
    {
        $recvBefore = 0;
        foreach (VerifyState::events($run, $scenario) as $event) {
            if (($event['e'] ?? '') === 'recv' && ($event['p'] ?? '') === $payload) {
                $recvBefore++;
            }
        }
        VerifyState::record($run, $scenario, ['e' => 'recv', 'p' => $payload] + $meta);

        if ($payload === 'm2' && $behaviour === 'exception' && $recvBefore === 0) {
            VerifyState::record($run, $scenario, ['e' => 'throw', 'p' => $payload]);
            throw new \RuntimeException('mq-verify boom on ' . $payload);
        }
        if ($payload === 'm2' && $behaviour === 'kill' && VerifyState::once($run, $scenario, 'killed')) {
            VerifyState::record($run, $scenario, ['e' => 'kill', 'p' => $payload]);
            VerifyState::log($scenario . ' SIGKILL self while handling m2, pid=' . getmypid());
            posix_kill(getmypid(), SIGKILL);
            \Swoole\Coroutine::sleep(5);
        }
        if ($behaviour === 'deadline') {
            \Swoole\Coroutine::sleep($payload === 'm0' ? 3.0 : 4.5);
        }
        VerifyState::record($run, $scenario, ['e' => 'done', 'p' => $payload] + $meta);
    }

    public static function exception(string $run, string $scenario, \Throwable $throwable): void
    {
        VerifyState::record($run, $scenario, [
            'e' => 'exception',
            'class' => get_class($throwable),
            'msg' => $throwable->getMessage(),
        ]);
        if (!str_contains($throwable->getMessage(), 'mq-verify boom')) {
            VerifyState::log($scenario . ' exception ' . get_class($throwable) . ': ' . $throwable->getMessage());
        }
    }
}
