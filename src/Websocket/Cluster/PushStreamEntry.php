<?php

namespace Swoolefy\Websocket\Cluster;

/**
 * 统一解析 Redis Stream 读结果（兼容 phpredis / Predis 不同返回结构）。
 *
 * Stream 条目固定单字段 `payload`，值为 PushMessage::encode() 的 JSON 字符串。
 */
class PushStreamEntry
{
    /** XADD 字段名，与 PushStreamPublisher 写入时一致 */
    public const FIELD_PAYLOAD = 'payload';

    /**
     * 解析 XREADGROUP 返回值。
     *
     * @return array<int, array{id: string, payload: string}>
     */
    public static function fromXReadGroupResult(array $result, string $streamKey): array
    {
        $streamMessages = $result[$streamKey] ?? null;
        if (!is_array($streamMessages)) {
            return [];
        }

        return self::normalizeMessages($streamMessages);
    }

    /**
     * 解析 XAUTOCLAIM 返回值：[nextStart, [[id, fields], ...]]。
     *
     * @return array{0: string, 1: array<int, array{id: string, payload: string}>}
     */
    public static function fromXAutoClaimResult(array $result): array
    {
        $nextStart = '0-0';
        $messages = [];

        if (isset($result[0]) && is_string($result[0])) {
            $nextStart = $result[0];
            $messages = is_array($result[1] ?? null) ? self::normalizeClaimedMessages($result[1]) : [];
        } elseif (isset($result['next']) || isset($result['messages'])) {
            $nextStart = (string) ($result['next'] ?? $result[0] ?? '0-0');
            $raw = $result['messages'] ?? $result[1] ?? [];
            $messages = is_array($raw) ? self::normalizeClaimedMessages($raw) : [];
        }

        return [$nextStart, $messages];
    }

    /**
     * @param array<string|int, array<string, string>> $streamMessages entryId => fields
     *
     * @return array<int, array{id: string, payload: string}>
     */
    private static function normalizeMessages(array $streamMessages): array
    {
        $entries = [];
        foreach ($streamMessages as $entryId => $fields) {
            if (!is_string($entryId) || !is_array($fields)) {
                continue;
            }
            $payload = self::extractPayload($fields);
            if ($payload === null) {
                continue;
            }
            $entries[] = ['id' => $entryId, 'payload' => $payload];
        }

        return $entries;
    }

    /**
     * @param array<int, mixed> $claimed XAUTOCLAIM 消息列表
     *
     * @return array<int, array{id: string, payload: string}>
     */
    private static function normalizeClaimedMessages(array $claimed): array
    {
        $entries = [];
        foreach ($claimed as $item) {
            if (!is_array($item)) {
                continue;
            }

            if (isset($item[0], $item[1]) && is_string($item[0])) {
                $fields = self::pairFields($item[1]);
                $payload = self::extractPayload($fields);
                if ($payload !== null) {
                    $entries[] = ['id' => $item[0], 'payload' => $payload];
                }
                continue;
            }

            if (isset($item['id'], $item['fields']) && is_array($item['fields'])) {
                $payload = self::extractPayload($item['fields']);
                if ($payload !== null) {
                    $entries[] = ['id' => (string) $item['id'], 'payload' => $payload];
                }
            }
        }

        return $entries;
    }

    /**
     * phpredis 有时返回关联数组 field=>value，有时返回扁平 [k,v,k,v]。
     *
     * @param array<int|string, mixed> $rawFields
     *
     * @return array<string, string>
     */
    private static function pairFields($rawFields): array
    {
        if (!is_array($rawFields)) {
            return [];
        }

        if (array_key_exists(self::FIELD_PAYLOAD, $rawFields)) {
            return [self::FIELD_PAYLOAD => (string) $rawFields[self::FIELD_PAYLOAD]];
        }

        $fields = [];
        $values = array_values($rawFields);
        for ($i = 0; $i < count($values) - 1; $i += 2) {
            $fields[(string) $values[$i]] = (string) $values[$i + 1];
        }

        return $fields;
    }

    /**
     * @param array<string, string> $fields
     */
    private static function extractPayload(array $fields): ?string
    {
        if (!array_key_exists(self::FIELD_PAYLOAD, $fields)) {
            return null;
        }

        $payload = (string) $fields[self::FIELD_PAYLOAD];

        return $payload === '' ? null : $payload;
    }
}
