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
 * 整数数组集合类
 * 专门用于处理整数类型的一维数组
 */
class ArrayInteger implements ArrayAccess, Countable, IteratorAggregate, JsonSerializable, ArrayInterface
{
    /**
     * 整数数据集
     * @var int[]
     */
    protected array $items = [];

    public function __construct($items = [])
    {
        $this->items = $this->convertToIntegerArray($items);
    }

    public static function make($items = []): static
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
     * 添加一个整数
     * @access public
     * @param int $value 整数
     * @return void
     */
    public function add(int $value): static
    {
        $this->items[] = $value;
        return $this;
    }

    /**
     * 转换为纯整数数组
     * @access public
     * @return int[]
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
     * 合并整数数组
     *
     * @access public
     * @param mixed $items 整数数据
     * @return static
     */
    public function merge($items): static
    {
        return new static(array_merge($this->items, $this->convertToIntegerArray($items)));
    }

    /**
     * 交换数组中的键和值
     *
     * @access public
     * @return static
     */
    public function flip(): static
    {
        return new static(array_flip($this->items));
    }

    /**
     * 返回数组中所有的键名
     *
     * @access public
     * @return static
     */
    public function keys(): static
    {
        return new static(array_keys($this->items));
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
        return new static(array_unique($this->items, SORT_NUMERIC));
    }

    /**
     * 删除数组的最后一个元素（出栈）
     *
     * @access public
     * @return null|int
     */
    public function pop(): ?int
    {
        return array_pop($this->items);
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
     * 以相反的顺序返回数组。
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
     * @return int|null
     */
    public function shift(): ?int
    {
        return array_shift($this->items);
    }

    /**
     * 在数组结尾追加一个整数值
     * @access public
     * @param int $value 整数值
     * @return static
     */
    public function push(int $value): static
    {
        $this->items[] = $value;
        return $this;
    }

    /**
     * 对每个元素执行回调
     *
     * @access public
     * @param callable $callback 回调函数，接收(int $value, int $key): int
     * @return static
     */
    public function each(callable $callback): static
    {
        foreach ($this->items as $key => $item) {
            $result = $callback($item, $key);
            if (false === $result) {
                break;
            }
            if (is_int($result)) {
                $this->items[$key] = $result;
            }
        }
        return $this;
    }

    /**
     * 用回调函数映射数组中的元素
     * @access public
     * @param callable $callback 回调函数，接收(int $value): int
     * @return static
     */
    public function map(callable $callback): static
    {
        return new static(array_map($callback, $this->items));
    }

    /**
     * 过滤数组中的元素
     * @access public
     * @param callable|null $callback 回调函数，接收(int $value): bool
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
     * 升序排序
     * @access public
     * @return static
     */
    public function sortAsc(): static
    {
        $items = $this->items;
        sort($items, SORT_NUMERIC);
        return new static($items);
    }

    /**
     * 降序排序
     * @access public
     * @return static
     */
    public function sortDesc(): static
    {
        $items = $this->items;
        rsort($items, SORT_NUMERIC);
        return new static($items);
    }

    /**
     * 自定义排序
     * @access public
     * @param callable|null $callback 比较函数
     * @return static
     */
    public function sort(?callable $callback = null): static
    {
        $items = $this->items;
        if ($callback) {
            usort($items, $callback);
        } else {
            sort($items, SORT_NUMERIC);
        }
        return new static($items);
    }

    /**
     * 获取第一个元素
     * @access public
     * @return int|null
     */
    public function first(): ?int
    {
        return empty($this->items) ? null : array_first($this->items);
    }

    /**
     * 获取最后一个元素
     * @access public
     * @return int|null
     */
    public function last(): ?int
    {
        return empty($this->items) ? null : array_last($this->items);
    }

    /**
     * 获取最小值
     * @access public
     * @return int|null
     */
    public function min(): ?int
    {
        return empty($this->items) ? null : min($this->items);
    }

    /**
     * 获取最大值
     * @access public
     * @return int|null
     */
    public function max(): ?int
    {
        return empty($this->items) ? null : max($this->items);
    }

    /**
     * 求和
     * @access public
     * @return int
     */
    public function sum(): int
    {
        return empty($this->items) ? 0 : array_sum($this->items);
    }

    /**
     * 获取差集（在当前数组中但不在参数数组中的元素）
     * @access public
     * @param array $arr 用于比较的整数数组
     * @return static
     */
    public function diff(array $arr): static
    {
        return new static(array_diff($this->items, $this->convertToIntegerArray($arr)));
    }

    /**
     * 获取交集（同时存在于当前数组和参数数组中的元素）
     * @access public
     * @param array $arr 用于比较的整数数组
     * @return static
     */
    public function intersect(array $arr): static
    {
        return new static(array_intersect($this->items, $this->convertToIntegerArray($arr)));
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

    // ArrayAccess
    #[\ReturnTypeWillChange]
    public function offsetExists($offset) : bool
    {
        return array_key_exists($offset, $this->items);
    }

    #[\ReturnTypeWillChange]
    public function offsetGet($offset): int
    {
        return $this->items[$offset];
    }

    #[\ReturnTypeWillChange]
    public function offsetSet($offset, $value)
    {
        if (!is_int($value)) {
            throw new \InvalidArgumentException('ArrayInteger only accepts integer values');
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
    public function jsonSerialize(): array
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
     * 转换为整数数组
     *
     * @access protected
     * @param mixed $items 数据
     * @return int[]
     * @throws \InvalidArgumentException
     */
    protected function convertToIntegerArray($items): array
    {
        if ($items instanceof self) {
            return $items->all();
        }

        $items = (array) $items;
        
        // 验证所有元素都是整数
        foreach ($items as $key => $value) {
            if (!is_int($value)) {
                throw new \InvalidArgumentException(
                    "ArrayInteger only accepts integer values. Invalid value at key '{$key}': " . gettype($value)
                );
            }
        }
        
        return $items;
    }
}