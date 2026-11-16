<?php

declare(strict_types=1);

namespace Test\Process\DbTaskProcess;

use RuntimeException;
use Swoole\Coroutine;

/**
 * 消费样例的业务 handler。
 *
 * payload 是 claim 表里 JSON 列的原文，格式：
 *   {"taskId": 1001, "delayMs": 20, "alwaysFail": false}
 *
 * taskId 是业务标识，只用于日志/结果钩子；claim 行号在 ClaimResult::$rowId 里。
 * 正常返回即成功，抛异常即失败（由 ClaimRunner 写回 fail_reason 并安排重试）。
 * 空闲一轮会收到 null，这里是打心跳的位置，返回值不会被写库。
 */
final class DbTaskHandler
{
    public function handle(?string $payload): int|string|null
    {
        if ($payload === null) {
            return null;
        }

        $data = json_decode($payload, true);
        if (!is_array($data)) {
            throw new RuntimeException('invalid payload: not a json object');
        }
        $taskId = (int) ($data['taskId'] ?? 0);
        if ($taskId <= 0) {
            throw new RuntimeException('invalid payload: missing taskId');
        }

        // 模拟可协作的业务耗时。阻塞型扩展要自己设网络超时，TimeoutGuard 中断不了它们。
        $delayMs = (int) ($data['delayMs'] ?? 0);
        if ($delayMs > 0) {
            Coroutine::sleep($delayMs / 1000);
        }

        // 演示失败路径：alwaysFail 的行重试 5 次后进入 dead（status=3）。
        if (!empty($data['alwaysFail'])) {
            throw new RuntimeException('demo failure for task ' . $taskId);
        }

        return $taskId;
    }
}
