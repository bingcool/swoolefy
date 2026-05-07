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
use Traversable;

/**
 * 字符串数组集合类
 * 专门用于处理字符串类型的一维数组
 */
class ArrayString implements ArrayAccess, Countable, IteratorAggregate, JsonSerializable
{
    /**
     * 字符串数据集
     * @var string[]
     */
    protected array $items = [];

    public function __construct($items = [])
    {
        $this->items = $this->convertToStringArray($items);
    }

    public static function make($items = [])
    {
        return new static($items);
    }

    /**
     * 是否为空
     * @access public
     * @return bool
     */
    public function isEmpty(): bool
    {
        return empty($this->items);
    }

    /**
     * 添加字符串
     * @access public
     * @param string $value 字符串
     * @return void
     */
    public function add(string $value): void
    {
        $this->items[] = $value;
    }

    /**
     * 转换为纯字符串数组
     * @access public
     * @return string[]
     */
    public function toArray(): array
    {
        return $this->items;
    }

    public function all(): array
    {
        return $this->items;
    }

    /**
     * 合并字符串数组
     *
     * @access public
     * @param mixed $items 字符串数据
     * @return static
     */
    public function merge($items): static
    {
        return new static(array_merge($this->items, $this->convertToStringArray($items)));
    }

    /**
     * 返回数组中所有的值（重新索引）
     * @access public
     * @return static
     */
    public function values(): static
    {
        return new static(array_values($this->items));
    }

    /**
     * 去除重复值
     * @access public
     * @return static
     */
    public function distinct(): static
    {
        return new static(array_unique($this->items, SORT_STRING));
    }

    /**
     * 删除数组的最后一个元素（出栈）
     *
     * @access public
     * @return string|null
     */
    public function pop(): ?string
    {
        $value = array_pop($this->items);
        return is_null($value) ? null : (string)$value;
    }

    /**
     * 通过使用用户自定义函数，归约数组
     *
     * @access public
     * @param callable $callback 回调函数
     * @param mixed    $initial 初始值
     * @return mixed
     */
    public function reduce(callable $callback, mixed $initial = null): mixed
    {
        return array_reduce($this->items, $callback, $initial);
    }

    /**
     * 以相反的顺序返回数组
     *
     * @access public
     * @return static
     */
    public function reverse(): static
    {
        return new static(array_reverse($this->items));
    }

    /**
     * 删除数组中首个元素，并返回被删除元素的值
     *
     * @access public
     * @return string|null
     */
    public function shift(): ?string
    {
        $value = array_shift($this->items);
        return is_null($value) ? null : (string)$value;
    }

    /**
     * 在数组结尾追加一个字符串值
     * @access public
     * @param string $value 字符串值
     * @return $this
     */
    public function push(string $value): static
    {
        $this->items[] = $value;
        return $this;
    }

    /**
     * 对每个元素执行回调
     *
     * @access public
     * @param callable $callback 回调函数，接收(string $value, int $key): string|false
     * @return $this
     */
    public function each(callable $callback): static
    {
        foreach ($this->items as $key => $item) {
            $result = $callback($item, $key);
            if (false === $result) {
                break;
            }
            if (is_string($result)) {
                $this->items[$key] = $result;
            }
        }
        return $this;
    }

    /**
     * 用回调函数映射数组中的元素
     * @access public
     * @param callable $callback 回调函数，接收(string $value): string
     * @return static
     */
    public function map(callable $callback): static
    {
        return new static(array_map($callback, $this->items));
    }

    /**
     * 过滤数组中的元素
     * @access public
     * @param callable|null $callback 回调函数，接收(string $value): bool
     * @return static
     */
    public function filter(?callable $callback = null): static
    {
        if ($callback) {
            return new static(array_filter($this->items, $callback));
        }
        return new static(array_filter($this->items));
    }

    /**
     * 升序排序（按字母顺序）
     * @access public
     * @return static
     */
    public function sortAsc(): static
    {
        $items = $this->items;
        sort($items, SORT_STRING);
        return new static($items);
    }

    /**
     * 降序排序（按字母顺序）
     * @access public
     * @return static
     */
    public function sortDesc(): static
    {
        $items = $this->items;
        rsort($items, SORT_STRING);
        return new static($items);
    }

    /**
     * 自定义排序
     * @access public
     * @param callable $callback 比较函数
     * @return static
     */
    public function sort(?callable $callback = null): static
    {
        $items = $this->items;
        if ($callback) {
            usort($items, $callback);
        } else {
            sort($items, SORT_STRING);
        }
        return new static($items);
    }

    /**
     * 获取第一个元素
     * @access public
     * @return string|null
     */
    public function first(): ?string
    {
        return empty($this->items) ? null : array_first($this->items);
    }

    /**
     * 获取最后一个元素
     * @access public
     * @return string|null
     */
    public function last(): ?string
    {
        return empty($this->items) ? null : array_last($this->items);
    }

    /**
     * 获取差集（在当前数组中但不在参数数组中的元素）
     * @access public
     * @param array $arr 用于比较的整数数组
     * @return static
     */
    public function diff(array $arr): static
    {
        return new static(array_diff($this->items, $this->convertToStringArray($arr)));
    }

    /**
     * 获取交集（同时存在于当前数组和参数数组中的元素）
     * @access public
     * @param array $arr 用于比较的整数数组
     * @return static
     */
    public function intersect(array $arr): static
    {
        return new static(array_intersect($this->items, $this->convertToStringArray($arr)));
    }

    /**
     * 截取数组
     *
     * @access public
     * @param int  $offset       起始位置
     * @param int  $length       截取长度
     * @param bool $preserveKeys 是否保留键名
     * @return static
     */
    public function slice(int $offset, ?int $length = null, bool $preserveKeys = false): static
    {
        return new static(array_slice($this->items, $offset, $length, $preserveKeys));
    }

    /**
     * 将数组元素连接成字符串
     * @access public
     * @param string $glue 分隔符
     * @return string
     */
    public function implode(string $glue = ''): string
    {
        return implode($glue, $this->items);
    }

    /**
     * 将所有元素转换为大写
     * @access public
     * @return static
     */
    public function toUpper(): static
    {
        return new static(array_map('strtoupper', $this->items));
    }

    /**
     * 将所有元素转换为小写
     * @access public
     * @return static
     */
    public function toLower(): static
    {
        return new static(array_map('strtolower', $this->items));
    }

    /**
     * 去除所有元素的首尾空白字符
     * @access public
     * @return static
     */
    public function trim(): static
    {
        return new static(array_map('trim', $this->items));
    }

    // ArrayAccess
    #[\ReturnTypeWillChange]
    public function offsetExists($offset): bool
    {
        return array_key_exists($offset, $this->items);
    }

    #[\ReturnTypeWillChange]
    public function offsetGet($offset)
    {
        return $this->items[$offset];
    }

    #[\ReturnTypeWillChange]
    public function offsetSet($offset, $value)
    {
        if (!is_string($value)) {
            throw new \InvalidArgumentException('ArrayString only accepts string values');
        }
        
        if (is_null($offset)) {
            $this->items[] = $value;
        } else {
            $this->items[$offset] = $value;
        }
    }

    #[\ReturnTypeWillChange]
    public function offsetUnset($offset)
    {
        unset($this->items[$offset]);
    }

    //Countable
    public function count(): int
    {
        return count($this->items);
    }

    //IteratorAggregate
    #[\ReturnTypeWillChange]
    public function getIterator(): Traversable
    {
        return new ArrayIterator($this->items);
    }

    //JsonSerializable
    #[\ReturnTypeWillChange]
    public function jsonSerialize()
    {
        return $this->toArray();
    }

    /**
     * 转换当前数据集为JSON字符串
     * @access public
     * @param integer $options json参数
     * @return string
     */
    public function toJson(int $options = JSON_UNESCAPED_UNICODE): string
    {
        return json_encode($this->toArray(), $options);
    }

    public function __toString()
    {
        return $this->toJson();
    }

    /**
     * 转换为字符串数组
     *
     * @access protected
     * @param mixed $items 数据
     * @return string[]
     * @throws \InvalidArgumentException
     */
    protected function convertToStringArray($items): array
    {
        if ($items instanceof self) {
            return $items->all();
        }

        $items = (array) $items;
        
        // 验证所有元素都是字符串
        foreach ($items as $key => $value) {
            if (!is_string($value)) {
                throw new \InvalidArgumentException(
                    "ArrayString only accepts string values. Invalid value at key '{$key}': " . gettype($value)
                );
            }
        }
        
        return $items;
    }
}
