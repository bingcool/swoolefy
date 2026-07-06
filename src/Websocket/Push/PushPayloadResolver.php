<?php

namespace Swoolefy\Websocket\Push;

/**
 * 投递前统一解析 push data（由业务 enricher 自定义处理）。
 *
 * 配置了 push.enricher 时，每次投递前都会调用 enricher；
 * 是否按 msg_id 查库、内联载荷是否原样透传，由 enricher 实现自行决定。
 */
class PushPayloadResolver
{
    /**
     * 解析并扩展 push data。
     *
     * @param string $event 事件名
     * @param mixed  $data  push 载荷，非数组时包装为 ['value' => $data]
     * @param int    $fd    目标连接 fd，透传给 enricher
     *
     * @return array|null 解析后的 data；null 表示 enricher 要求跳过该 fd 的投递
     */
    public static function resolve(string $event, $data, int $fd): ?array
    {
        if (!is_array($data)) {
            $data = ['value' => $data];
        }

        $enricher = PushPayloadEnricherFactory::get();
        if (!$enricher instanceof PushPayloadEnricherInterface) {
            return $data;
        }

        return $enricher->enrich($event, $data, $fd);
    }
}
