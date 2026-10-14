<?php

declare(strict_types=1);

namespace Swoolefy\Support\Rocketmq\Dto;

use Swoolefy\Core\Dto\AbstractDto;

/**
 * rocketmq.producer.send 的请求。
 *
 * 这里的校验和 go-sidecar 的规则一致，只为提前报错、省一次 IPC，最终以 sidecar 为准。
 * 新增的可选字段（keys、properties、deliverAtMs、messageGroup）为空时不序列化，见 toArray()。
 */
final class SendMessageRequestDto extends AbstractDto
{
    /**
     * keys 最多个数。
     */
    public const MAX_KEYS = 32;

    /**
     * 单个 Key 最大字节数。
     */
    public const MAX_KEY_BYTES = 128;

    /**
     * properties 最多个数。
     */
    public const MAX_PROPERTIES = 32;

    /**
     * 属性 Key 最大字节数。
     */
    public const MAX_PROPERTY_KEY_BYTES = 64;

    /**
     * 属性 Value 最大字节数。
     */
    public const MAX_PROPERTY_VALUE_BYTES = 1024;

    /**
     * 所有属性 Key + Value 合计最大字节数。
     */
    public const MAX_PROPERTIES_BYTES = 16384;

    /**
     * messageGroup 最大字节数。
     */
    public const MAX_MESSAGE_GROUP_BYTES = 64;

    /**
     * 小于这个值的 deliverAtMs 按秒级时间戳误传处理（10^12 毫秒约等于 2001 年）。
     */
    private const MIN_MILLISECOND_TIMESTAMP = 1_000_000_000_000;

    /**
     * Topic，必须在 sidecar 的允许列表里。
     */
    protected string $topic = '';

    /**
     * 消息体的 base64，不能为空。
     */
    protected string $bodyBase64 = '';

    /**
     * 消息分类。消费组按 Tag 订阅过滤，空字符串表示不设置。
     */
    protected string $tag = '';

    /**
     * 业务 Key（订单号、用户号等），最多 32 个，每个不超过 128 字节且不含空白字符。
     * Broker 为每个 Key 建索引，用于在控制台按 Key 查消息；不唯一、不去重、不影响消费。
     *
     * @var list<string>
     */
    protected array $keys = [];

    /**
     * 自定义属性，消费端通过 ReceivedMessageDto::getProperties() 原样读回。
     * 最多 32 个；Key 只能是字母、数字、下划线、点、中划线，1–64 字节，不能以 "__" 开头（RocketMQ 系统属性前缀）；
     * Value 不超过 1024 字节；所有 Key + Value 合计不超过 16 KiB。
     *
     * @var array<string, string>
     */
    protected array $properties = [];

    /**
     * 相对延时（毫秒），从 sidecar 收到请求起算。0 表示立即投递。和 deliverAtMs 互斥。
     */
    protected int $delayMs = 0;

    /**
     * 绝对投递时间，Unix 毫秒时间戳（13 位）。0 表示不设置。和 delayMs 互斥。
     * 必须晚于当前时间，且不超过 40 天；RocketMQ 服务端默认最多 3 天，超过服务端上限会被拒绝。
     */
    protected int $deliverAtMs = 0;

    /**
     * 顺序消息分组，同组消息按发送顺序消费。只能发到 FIFO 类型的 Topic，不能和延时同时使用。
     * 不超过 64 字节且不含空白字符。
     */
    protected string $messageGroup = '';

    /**
     * 设置 Topic。
     */
    public function setTopic(string $topic): void
    {
        $this->topic = $topic;
    }

    /**
     * 读取 Topic。
     */
    public function getTopic(): string
    {
        return $this->topic;
    }

    /**
     * 设置消息体的 base64，原文用 base64_encode() 转换。
     */
    public function setBodyBase64(string $bodyBase64): void
    {
        $this->bodyBase64 = $bodyBase64;
    }

    /**
     * 读取消息体的 base64。
     */
    public function getBodyBase64(): string
    {
        return $this->bodyBase64;
    }

    /**
     * 设置消息 Tag，一条消息只有一个 Tag。
     */
    public function setTag(string $tag): void
    {
        $this->tag = $tag;
    }

    /**
     * 读取消息 Tag。
     */
    public function getTag(): string
    {
        return $this->tag;
    }

    /**
     * 重复的 Key 去掉，保留首次出现的顺序。只有一个 Key 时写 setKeys([$orderNo])。
     *
     * @param list<string> $keys
     * @throws \InvalidArgumentException 超过 32 个，或某个 Key 为空、超长、含空白字符
     */
    public function setKeys(array $keys): void
    {
        if (count($keys) > self::MAX_KEYS) {
            throw new \InvalidArgumentException('rocketmq keys must not exceed ' . self::MAX_KEYS);
        }
        $out = [];
        foreach ($keys as $key) {
            if (!is_string($key) || $key === '' || strlen($key) > self::MAX_KEY_BYTES || preg_match('/\s/u', $key) === 1) {
                throw new \InvalidArgumentException('rocketmq key must be a non-empty string up to ' . self::MAX_KEY_BYTES . ' bytes without whitespace');
            }
            $out[$key] = true;
        }
        $this->keys = array_map('strval', array_keys($out));
    }

    /**
     * 读取业务 Key（已去重）。
     *
     * @return list<string>
     */
    public function getKeys(): array
    {
        return $this->keys;
    }

    /**
     * 设置自定义属性，整体替换。规则见 $properties 的说明。
     *
     * @param array<string, string> $properties
     * @throws \InvalidArgumentException 个数、Key 格式、Value 类型或长度、总长度不合法
     */
    public function setProperties(array $properties): void
    {
        if (count($properties) > self::MAX_PROPERTIES) {
            throw new \InvalidArgumentException('rocketmq properties must not exceed ' . self::MAX_PROPERTIES);
        }
        // 列表形式的数组会被 json_encode 成 JSON 数组，sidecar 期望的是对象。
        // 只有 Key 恰好是 "0"、"1"… 连续数字时才会出现，直接拒绝。
        if ($properties !== [] && array_is_list($properties)) {
            throw new \InvalidArgumentException('rocketmq properties must be an associative array');
        }
        $total = 0;
        foreach ($properties as $key => $value) {
            $key = (string) $key;
            if (preg_match('/^[A-Za-z0-9_.\-]{1,' . self::MAX_PROPERTY_KEY_BYTES . '}$/', $key) !== 1 || str_starts_with($key, '__')) {
                throw new \InvalidArgumentException('rocketmq property key is invalid: ' . $key);
            }
            if (!is_string($value) || strlen($value) > self::MAX_PROPERTY_VALUE_BYTES) {
                throw new \InvalidArgumentException('rocketmq property value must be a string up to ' . self::MAX_PROPERTY_VALUE_BYTES . ' bytes: ' . $key);
            }
            $total += strlen($key) + strlen($value);
        }
        if ($total > self::MAX_PROPERTIES_BYTES) {
            throw new \InvalidArgumentException('rocketmq properties must not exceed ' . self::MAX_PROPERTIES_BYTES . ' bytes in total');
        }
        $this->properties = $properties;
    }

    /**
     * 读取自定义属性。
     *
     * @return array<string, string>
     */
    public function getProperties(): array
    {
        return $this->properties;
    }

    /**
     * 设置相对延时（毫秒），0 表示立即投递。
     *
     * @throws \InvalidArgumentException 负数
     */
    public function setDelayMs(int $delayMs): void
    {
        if ($delayMs < 0) {
            throw new \InvalidArgumentException('rocketmq delayMs must not be negative');
        }
        $this->delayMs = $delayMs;
    }

    /**
     * 读取相对延时（毫秒）。
     */
    public function getDelayMs(): int
    {
        return $this->delayMs;
    }

    /**
     * 设置绝对投递时间，0 表示不设置。
     *
     * @param int $deliverAtMs Unix 毫秒时间戳，例如 (int) (microtime(true) * 1000) + 30 * 60 * 1000
     * @throws \InvalidArgumentException 看起来是秒级时间戳
     */
    public function setDeliverAtMs(int $deliverAtMs): void
    {
        if ($deliverAtMs !== 0 && $deliverAtMs < self::MIN_MILLISECOND_TIMESTAMP) {
            throw new \InvalidArgumentException('rocketmq deliverAtMs must be a millisecond timestamp; got ' . $deliverAtMs . ', looks like seconds');
        }
        $this->deliverAtMs = $deliverAtMs;
    }

    /**
     * 读取绝对投递时间（Unix 毫秒），0 表示未设置。
     */
    public function getDeliverAtMs(): int
    {
        return $this->deliverAtMs;
    }

    /**
     * 设置顺序消息分组，空字符串表示普通消息。
     *
     * @throws \InvalidArgumentException 超长或含空白字符
     */
    public function setMessageGroup(string $messageGroup): void
    {
        if (strlen($messageGroup) > self::MAX_MESSAGE_GROUP_BYTES || preg_match('/\s/u', $messageGroup) === 1) {
            throw new \InvalidArgumentException('rocketmq messageGroup must be up to ' . self::MAX_MESSAGE_GROUP_BYTES . ' bytes without whitespace');
        }
        $this->messageGroup = $messageGroup;
    }

    /**
     * 读取顺序消息分组。
     */
    public function getMessageGroup(): string
    {
        return $this->messageGroup;
    }

    /**
     * 字段之间的互斥规则。setter 只能看到单个字段，所以发送前再统一检查一次。
     *
     * @throws \InvalidArgumentException delayMs 与 deliverAtMs 同时设置，或 messageGroup 与延时同时设置
     */
    public function validate(): void
    {
        if ($this->delayMs > 0 && $this->deliverAtMs !== 0) {
            throw new \InvalidArgumentException('rocketmq delayMs and deliverAtMs are exclusive');
        }
        if ($this->messageGroup !== '' && ($this->delayMs > 0 || $this->deliverAtMs !== 0)) {
            throw new \InvalidArgumentException('rocketmq messageGroup cannot be combined with delayMs or deliverAtMs');
        }
    }

    /**
     * 新增的可选字段为空时不输出：空 properties 会被编码成 JSON 数组 []，sidecar 按对象解析会拒绝。
     */
    public function toArray(): array
    {
        $out = [
            'topic' => $this->topic,
            'bodyBase64' => $this->bodyBase64,
            'tag' => $this->tag,
            'delayMs' => $this->delayMs,
        ];
        if ($this->keys !== []) {
            $out['keys'] = $this->keys;
        }
        if ($this->properties !== []) {
            $out['properties'] = $this->properties;
        }
        if ($this->deliverAtMs !== 0) {
            $out['deliverAtMs'] = $this->deliverAtMs;
        }
        if ($this->messageGroup !== '') {
            $out['messageGroup'] = $this->messageGroup;
        }

        return $out;
    }
}
