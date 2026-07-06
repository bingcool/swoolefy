<?php

declare(strict_types=1);

namespace __SDK_SUPPORT_NAMESPACE__;

use ArrayAccess;
use ArrayIterator;
use Countable;
use IteratorAggregate;
use JsonSerializable;
use Traversable;

/**
 * SDK copy: int[] collection (mirrors Swoolefy\DataStruct\ArrayInteger).
 */
class SdkArrayInteger implements ArrayAccess, Countable, IteratorAggregate, JsonSerializable, SdkArrayInterface
{
    /** @var int[] */
    protected array $items = [];

    public function __construct(mixed $items = [])
    {
        $this->items = $this->convertToIntegerArray($items);
    }

    public static function make(mixed $items = []): static
    {
        return new static($items);
    }

    public function isEmpty(): bool
    {
        return $this->items === [];
    }

    public function add(int $value): static
    {
        $this->items[] = $value;

        return $this;
    }

    public function toArray(): array
    {
        return $this->items;
    }

    public function toDeepArray(): array
    {
        return $this->items;
    }

    public function all(): array
    {
        return $this->items;
    }

    public function merge(mixed $items): static
    {
        return new static(array_merge($this->items, $this->convertToIntegerArray($items)));
    }

    public function distinct(): static
    {
        return new static(array_values(array_unique($this->items, SORT_NUMERIC)));
    }

    public function values(): static
    {
        return new static(array_values($this->items));
    }

    public function filter(?callable $callback = null): static
    {
        if ($callback) {
            return new static(array_values(array_filter($this->items, $callback)));
        }

        return new static(array_values(array_filter($this->items)));
    }

    public function map(callable $callback): static
    {
        return new static(array_map($callback, $this->items));
    }

    public function first(): ?int
    {
        return $this->items === [] ? null : $this->items[array_key_first($this->items)];
    }

    public function last(): ?int
    {
        return $this->items === [] ? null : $this->items[array_key_last($this->items)];
    }

    public function count(): int
    {
        return count($this->items);
    }

    #[\ReturnTypeWillChange]
    public function offsetExists($offset): bool
    {
        return array_key_exists($offset, $this->items);
    }

    #[\ReturnTypeWillChange]
    public function offsetGet($offset): int
    {
        return $this->items[$offset];
    }

    #[\ReturnTypeWillChange]
    public function offsetSet($offset, $value): void
    {
        if (!is_int($value)) {
            throw new \InvalidArgumentException('SdkArrayInteger only accepts integer values');
        }
        if ($offset === null) {
            $this->items[] = $value;
        } else {
            $this->items[$offset] = $value;
        }
    }

    #[\ReturnTypeWillChange]
    public function offsetUnset($offset): void
    {
        unset($this->items[$offset]);
    }

    #[\ReturnTypeWillChange]
    public function getIterator(): Traversable
    {
        return new ArrayIterator($this->items);
    }

    #[\ReturnTypeWillChange]
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }

    public function toJson(int $options = JSON_UNESCAPED_UNICODE): string
    {
        return json_encode($this->toArray(), $options);
    }

    public function __toString(): string
    {
        return $this->toJson();
    }

    /**
     * @return int[]
     */
    protected function convertToIntegerArray(mixed $items): array
    {
        if ($items instanceof self) {
            return $items->all();
        }

        $items = (array) $items;
        foreach ($items as $key => $value) {
            if (!is_int($value)) {
                throw new \InvalidArgumentException(
                    "SdkArrayInteger only accepts integer values. Invalid value at key '{$key}': " . gettype($value)
                );
            }
        }

        return $items;
    }
}
