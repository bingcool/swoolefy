<?php

namespace Swoolefy\Websocket\Push;

/**
 * 将 callable / [实例, 方法] 适配为 PushPayloadEnricherInterface。
 *
 * 用于配置中的匿名函数、闭包，以及 `[MessagePushEnricher::class, 'enrich']` 形式。
 *
 * callable 签名须与接口一致：
 * `function (string $event, array $data, int $fd): ?array`
 */
class CallablePushPayloadEnricher implements PushPayloadEnricherInterface
{
    private $callable;

    public function __construct(callable $callable)
    {
        $this->callable = $callable;
    }

    public function enrich(string $event, array $data, int $fd): ?array
    {
        $result = ($this->callable)($event, $data, $fd);

        if ($result === null) {
            // null = 业务明确要求跳过该 fd 投递
            return null;
        }

        // 非数组返回值视为无效，回退为原始 data（防御性处理）
        return is_array($result) ? $result : $data;
    }
}
