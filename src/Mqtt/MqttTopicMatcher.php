<?php

declare(strict_types=1);

namespace Swoolefy\Mqtt;

/**
 * MQTT 主题过滤器匹配（MQTT-3.1.1 / 5.0 规范）。
 *
 * 规则摘要：
 * - `+` 匹配单层；`#` 匹配多层且必须是 filter 最后一个层级（单独 `#` 除外）
 * - 比较时区分大小写；`$SYS/` 等系统主题按普通字符串处理
 */
final class MqttTopicMatcher
{
    /**
     * 判断订阅 filter 是否匹配 publish topic。
     *
     * @param string $filter 客户端 SUBSCRIBE 时的 topic filter（可含 + / #）
     * @param string $topic  发布者 PUBLISH 的 topic name（不可含通配符）
     */
    public static function matches(string $filter, string $topic): bool
    {
        // 快速路径：完全一致则直接命中
        if ($filter === $topic) {
            return true;
        }

        // 单独 '#' 匹配任意 topic（含空层级）
        if ($filter === '#') {
            return true;
        }

        $filterParts = explode('/', $filter);
        $topicParts = explode('/', $topic);

        // MQTT 规范：# 只能是 filter 最后一个层级（如 a/#/b 非法）
        $hashIndex = array_search('#', $filterParts, true);
        if ($hashIndex !== false && $hashIndex !== count($filterParts) - 1) {
            return false;
        }

        $filterLen = count($filterParts);
        $topicLen = count($topicParts);

        // 双指针逐层比对：i 走 filter，j 走 topic
        for ($i = 0, $j = 0; $i < $filterLen; $i++, $j++) {
            $part = $filterParts[$i];

            // # 出现在末尾：剩余 topic 层级全部匹配
            if ($part === '#') {
                return true;
            }

            // + 匹配当前 topic 单层，但不消耗 filter 层级（continue 后 i++ 继续）
            if ($part === '+') {
                if ($j >= $topicLen) {
                    return false; // topic 已无层级可匹配
                }
                continue;
            }

            // 字面量层级必须完全一致
            if ($j >= $topicLen || $part !== $topicParts[$j]) {
                return false;
            }
        }

        // filter 遍历完时，topic 也必须恰好消耗完（防止 a/b 匹配 a/b/c）
        return $j === $topicLen;
    }
}
