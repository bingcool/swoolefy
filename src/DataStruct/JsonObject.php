<?php
/**
 * +----------------------------------------------------------------------
 * | Common library of swoole
 * +----------------------------------------------------------------------
 * | Licensed ( https://opensource.org/licenses/MIT )
 * +----------------------------------------------------------------------
 * | Author: bingcool <bingcoolhuang@gmail.com || 2437667702@qq.com>
 * +----------------------------------------------------------------------
 */

namespace Swoolefy\DataStruct;

use ArrayAccess;
use ArrayIterator;
use Countable;
use IteratorAggregate;
use JsonSerializable;
use Swoolefy\Core\Dto\ArrayDto;
use Swoolefy\Exception\JsonObjectException;
use Traversable;

/**
 * JSON 对象类
 * 用于处理 JSON 数据结构
 * 支持嵌套的键值对操作，类似 Map<String, Object>
 *
 *
 * // 创建实例
 * $json = JsonObject::make([
 * 'name' => 'John',
 * 'age' => 30,
 * 'active' => true,
 * 'address' => [
 * 'city' => 'Beijing',
 * 'zip' => '100000'
 * ]
 * ]);
 *
 * // 类型安全获取
 * $name = $json->getString('name');
 * $age = $json->getInt('age');
 * $isActive = $json->getBool('active');
 * $address = $json->getObject('address');
 *
 * // 链式调用
 * $json->set('email', 'john@example.com')
 * ->set('phone', '1234567890')
 * ->del('age');
 *
 * // 数组访问
 * echo $json['name']; // John
 * $json['new_key'] = 'value';
 *
 * // JSON 转换
 * $jsonString = $json->toJson();
 * $prettyJson = $json->toPrettyJson();
 *
 * // 从 JSON 字符串创建
 * $json2 = JsonObject::fromJson('{"key": "value"}');
 */
class JsonObject implements ArrayAccess, Countable, IteratorAggregate, JsonSerializable, ArrayInterface
{
    /**
     * JSON 数据
     * @var array<string, mixed>
     */
    protected array $data = [];

    /**
     * 构造函数
     * @param array|string $data 初始数据（数组或 JSON 字符串）
     * @throws \JsonException
     */
    public function __construct($data = [])
    {
        if (is_string($data)) {
            $this->data = json_decode($data, true, 512, JSON_THROW_ON_ERROR);
        } elseif (is_array($data)) {
            $this->data = $data;
        } elseif ($data instanceof self) {
            $this->data = $data->toArray();
        } else {
            throw JsonObjectException::throw('JsonObject constructor expects array, string or JsonObject instance');
        }
    }

    /**
     * 创建 JsonObject 实例
     * @param array|string $data 初始数据
     * @return static
     * @throws \JsonException
     */
    public static function make($data = []): static
    {
        return new static($data);
    }

    /**
     * 从 JSON 字符串创建实例
     * @param string $json JSON 字符串
     * @return static
     * @throws \JsonException
     */
    public static function fromJson(string $json): static
    {
        return new static($json);
    }

    /**
     * 获取指定键的值
     * @param string $key 键名
     * @param mixed $default 默认值
     * @return mixed
     */
    public function get(string $key, mixed $default = null): mixed
    {
        return $this->data[$key] ?? $default;
    }

    /**
     * 获取字符串值
     * @param string $key 键名
     * @param string $default 默认值
     * @return string
     */
    public function getString(string $key, string $default = ''): string
    {
        $value = $this->get($key, $default);
        return is_string($value) ? $value : $default;
    }

    /**
     * 获取整数值
     * @param string $key 键名
     * @param int $default 默认值
     * @return int
     */
    public function getInt(string $key, int $default = 0): int
    {
        $value = $this->get($key, $default);
        return is_int($value) ? $value : $default;
    }

    /**
     * 获取浮点数值
     * @param string $key 键名
     * @param float $default 默认值
     * @return float
     */
    public function getFloat(string $key, float $default = 0.0): float
    {
        $value = $this->get($key, $default);
        return is_float($value) || is_int($value) ? (float)$value : $default;
    }

    /**
     * 获取布尔值
     * @param string $key 键名
     * @param bool $default 默认值
     * @return bool
     */
    public function getBool(string $key, bool $default = false): bool
    {
        $value = $this->get($key, $default);
        if (is_bool($value)) {
            return $value;
        }
        return $default;
    }

    /**
     * 获取嵌套的 JsonObject
     * @param string $key 键名
     * @param array $default 默认值
     * @return static|null
     */
    public function getObject(string $key, array $default = []): ?static
    {
        $value = $this->get($key, $default);
        if (is_array($value)) {
            return new static($value);
        }
        return $value instanceof static ? $value : null;
    }

    /**
     * 获取数组值
     * @param string $key 键名
     * @param array $default 默认值
     * @return array
     */
    public function getArray(string $key, array $default = []): array
    {
        $value = $this->get($key, $default);
        return is_array($value) ? $value : $default;
    }

    /**
     * 设置键值对
     * @param string $key 键名
     * @param mixed $value 值
     * @return static 返回自身以支持链式调用
     */
    public function set(string $key, mixed $value): static
    {
        $this->data[$key] = $value;
        return $this;
    }

    /**
     * 删除指定键
     * @param string $key 键名
     * @return static 返回自身以支持链式调用
     */
    public function del(string $key): static
    {
        unset($this->data[$key]);
        return $this;
    }

    /**
     * 判断是否包含指定键
     * @param string $key 键名
     * @return bool
     */
    public function has(string $key): bool
    {
        return array_key_exists($key, $this->data);
    }

    /**
     * 判断是否为空
     * @return bool
     */
    public function isEmpty(): bool
    {
        return empty($this->data);
    }

    /**
     * 获取所有键名
     * @return array
     */
    public function keys(): array
    {
        return array_keys($this->data);
    }

    /**
     * 获取所有值
     * @return array
     */
    public function values(): array
    {
        return array_values($this->data);
    }

    /**
     * 获取键值对数量
     * @return int
     */
    public function size(): int
    {
        return count($this->data);
    }

    /**
     * 清空所有数据
     * @return static 返回自身以支持链式调用
     */
    public function clear(): static
    {
        $this->data = [];
        return $this;
    }

    /**
     * 合并另一个 JsonObject
     * @param static|array $other 要合并的对象或数组
     * @return static 返回新实例
     */
    public function merge($other): static
    {
        if ($other instanceof self) {
            $otherData = $other->toArray();
        } elseif (is_array($other)) {
            $otherData = $other;
        } else {
            throw JsonObjectException::throw('Merge expects JsonObject or array');
        }
        
        return new static(array_merge($this->data, $otherData));
    }

    /**
     * 转换为数组
     * @return array
     */
    public function toArray(): array
    {
        return $this->data;
    }

    /**
     * 获取所有数据（别名方法）
     * @return array
     */
    public function all(): array
    {
        return $this->data;
    }

    /**
     * 转换为 JSON 字符串
     * @param int $options JSON 编码选项
     * @return string
     * @throws \JsonException
     */
    public function toJson(int $options = JSON_UNESCAPED_UNICODE): string
    {
        return json_encode($this->data, $options | JSON_THROW_ON_ERROR);
    }

    /**
     * 格式化的 JSON 字符串
     * @param int $options JSON 编码选项
     * @return string
     * @throws \JsonException
     */
    public function toPrettyJson(int $options = JSON_UNESCAPED_UNICODE): string
    {
        return json_encode($this->data, $options | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR);
    }

    // ArrayAccess 接口实现

    #[\ReturnTypeWillChange]
    public function offsetExists($offset): bool
    {
        return $this->has($offset);
    }

    #[\ReturnTypeWillChange]
    public function offsetGet($offset): mixed
    {
        return $this->get($offset);
    }

    #[\ReturnTypeWillChange]
    public function offsetSet($offset, $value): void
    {
        if (is_null($offset)) {
            throw JsonObjectException::throw('JsonObject does not support appending without key');
        }
        $this->set($offset, $value);
    }

    #[\ReturnTypeWillChange]
    public function offsetUnset($offset): void
    {
        $this->del($offset);
    }

    // Countable 接口实现

    public function count(): int
    {
        return $this->size();
    }

    // IteratorAggregate 接口实现

    #[\ReturnTypeWillChange]
    public function getIterator(): Traversable
    {
        return new ArrayIterator($this->data);
    }

    // JsonSerializable 接口实现

    #[\ReturnTypeWillChange]
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }

    /**
     * 字符串表示
     * @return string
     */
    public function __toString(): string
    {
        try {
            return $this->toJson();
        } catch (\JsonException $e) {
            return '{}';
        }
    }

    /**
     * 克隆对象
     */
    public function __clone()
    {
        $this->data = $this->deepCopy($this->data);
    }

    /**
     * 深度复制数组
     * @param array $data 要复制的数组
     * @return array
     */
    protected function deepCopy(array $data): array
    {
        $result = [];
        foreach ($data as $key => $value) {
            if (is_array($value)) {
                $result[$key] = $this->deepCopy($value);
            } elseif ($value instanceof self) {
                $result[$key] = clone $value;
            } else {
                $result[$key] = $value;
            }
        }
        return $result;
    }

    /**
     * 转换为深度属性数组,每一层级的对象属性都会转为数组
     */
    public function toDeepArray(): array
    {
        return (new ArrayDto)->valueToDeepArray($this->data);
    }
}
