<?php

declare(strict_types=1);

namespace __SDK_SUPPORT_NAMESPACE__;

use ArrayIterator;
use LogicException;
use ReflectionNamedType;
use ReflectionProperty;
use Traversable;

/**
 * SDK：$dto->field 与 $dto['field'] 等价；json_encode / foreach 走 toDeepArray。
 */
trait SdkInteractsWithDtoArrayAccess
{
    public function offsetExists(mixed $offset): bool
    {
        if (!is_string($offset) && !is_int($offset)) {
            return false;
        }
        $name = (string) $offset;
        if ($name === '') {
            return false;
        }

        if ($this->dtoOffsetViaGetter($name) !== null) {
            return true;
        }

        $property = $this->reflectionPropertyForDeclaredField($name);
        if ($property !== null) {
            $property->setAccessible(true);

            return $property->isInitialized($this);
        }

        return array_key_exists($name, get_object_vars($this));
    }

    public function offsetGet(mixed $offset): mixed
    {
        if (!is_string($offset) && !is_int($offset)) {
            return null;
        }
        $name = (string) $offset;
        if ($name === '') {
            return null;
        }

        $viaGetter = $this->dtoOffsetViaGetter($name);
        if ($viaGetter !== null) {
            return $viaGetter();
        }

        $property = $this->reflectionPropertyForDeclaredField($name);
        if ($property !== null) {
            $property->setAccessible(true);
            if ($property->isInitialized($this)) {
                return $property->getValue($this);
            }

            return null;
        }

        $vars = get_object_vars($this);

        return $vars[$name] ?? null;
    }

    public function offsetSet(mixed $offset, mixed $value): void
    {
        if ($offset === null) {
            throw new LogicException('DTO 不支持 $dto[] = ... 追加写法');
        }
        if (!is_string($offset) && !is_int($offset)) {
            throw new \InvalidArgumentException('offset 必须是 string 或 int');
        }
        $name = (string) $offset;
        if ($name === '') {
            throw new \InvalidArgumentException('offset 不能为空');
        }

        if ($this->dtoTrySetter($name, $value)) {
            return;
        }

        $property = $this->reflectionPropertyForDeclaredField($name);
        if ($property === null) {
            throw new \InvalidArgumentException("未知字段: {$name}");
        }
        if ($property->isReadOnly()) {
            throw new LogicException("只读字段不可写: {$name}");
        }

        $property->setAccessible(true);
        $property->setValue($this, $value);
    }

    public function offsetUnset(mixed $offset): void
    {
        if (!is_string($offset) && !is_int($offset)) {
            return;
        }
        $name = (string) $offset;
        if ($name === '') {
            return;
        }

        $property = $this->reflectionPropertyForDeclaredField($name);
        if ($property === null || $property->isReadOnly()) {
            unset($this->{$name});

            return;
        }

        $property->setAccessible(true);
        if (!$property->hasType()) {
            $property->setValue($this, null);

            return;
        }

        $type = $property->getType();
        if ($type instanceof ReflectionNamedType && $type->allowsNull()) {
            $property->setValue($this, null);
        }
    }

    public function __get(string $name): mixed
    {
        return $this->offsetGet($name);
    }

    public function __set(string $name, mixed $value): void
    {
        $this->offsetSet($name, $value);
    }

    public function __isset(string $name): bool
    {
        return $this->offsetExists($name);
    }

    public function __unset(string $name): void
    {
        $this->offsetUnset($name);
    }

    public function jsonSerialize(): mixed
    {
        return $this->toDeepArray();
    }

    public function getIterator(): Traversable
    {
        return new ArrayIterator($this->toDeepArray());
    }

    public static function fromArray(array $data): static
    {
        $obj = new static();
        $ref = new \ReflectionClass($obj);

        foreach ($data as $key => $value) {
            if (!is_string($key) && !is_int($key)) {
                continue;
            }
            $name = (string) $key;
            if ($name === '' || !$ref->hasProperty($name)) {
                continue;
            }
            $property = $ref->getProperty($name);
            if ($property->isStatic() || $property->isReadOnly()) {
                continue;
            }
            $property->setAccessible(true);
            $property->setValue($obj, static::hydratePropertyValue($property, $value));
        }

        return $obj;
    }

    protected static function hydratePropertyValue(ReflectionProperty $prop, mixed $value): mixed
    {
        if ($value === null) {
            return null;
        }

        $type = $prop->getType();
        if ($type instanceof ReflectionNamedType && !$type->isBuiltin() && is_array($value)) {
            $class = $type->getName();
            if (is_a($class, SdkArrayDto::class, true) && method_exists($class, 'fromArray')) {
                return $class::fromArray($value);
            }
        }

        foreach ($prop->getAttributes(ArrayList::class) as $attr) {
            if (!is_array($value)) {
                break;
            }
            $itemClass = $attr->newInstance()->getItemClass();
            if ($itemClass === '' || !class_exists($itemClass)) {
                return $value;
            }

            return array_map(
                static function (mixed $item) use ($itemClass): mixed {
                    if (!is_array($item)) {
                        return $item;
                    }
                    if (is_a($itemClass, SdkArrayDto::class, true) && method_exists($itemClass, 'fromArray')) {
                        return $itemClass::fromArray($item);
                    }

                    return $item;
                },
                $value,
            );
        }

        return $value;
    }

    private function dtoOffsetViaGetter(string $name): ?callable
    {
        if ($name === 'data' && method_exists($this, 'getData')) {
            return fn (): mixed => $this->getData();
        }

        $getter = 'get' . str_replace(' ', '', ucwords(str_replace('_', ' ', $name)));
        if ($getter !== 'getData' && method_exists($this, $getter)) {
            return fn (): mixed => $this->{$getter}();
        }

        return null;
    }

    private function dtoTrySetter(string $name, mixed $value): bool
    {
        if ($name === 'data' && method_exists($this, 'setData')) {
            $this->setData($value);

            return true;
        }

        $setter = 'set' . str_replace(' ', '', ucwords(str_replace('_', ' ', $name)));
        if (method_exists($this, $setter)) {
            $this->{$setter}($value);

            return true;
        }

        return false;
    }
}
