<?php

declare(strict_types=1);

namespace __SDK_SUPPORT_NAMESPACE__;

use ReflectionProperty;

/**
 * SDK copy of core DTO helpers (no framework deps).
 */
class SdkArrayDto extends \stdClass
{
    public function toArray(): array
    {
        $out = [];
        foreach (
            (new \ReflectionClass($this))->getProperties(
                ReflectionProperty::IS_PUBLIC | ReflectionProperty::IS_PROTECTED | ReflectionProperty::IS_PRIVATE
            ) as $property
        ) {
            if ($property->isStatic()) {
                continue;
            }
            $property->setAccessible(true);
            if (!$property->isInitialized($this)) {
                continue;
            }
            $out[$property->getName()] = $property->getValue($this);
        }
        foreach (get_object_vars($this) as $name => $value) {
            if (!array_key_exists($name, $out)) {
                $out[$name] = $value;
            }
        }

        return $out;
    }

    public function toDeepArray(): array
    {
        return $this->valueToDeepArray($this->toArray());
    }

    private function valueToDeepArray(mixed $value): mixed
    {
        if (is_array($value)) {
            foreach ($value as $key => $item) {
                $value[$key] = $this->valueToDeepArray($item);
            }

            return $value;
        }

        // SdkArrayInteger / SdkArrayString：序列化前转为纯数组
        if ($value instanceof SdkArrayInterface) {
            return $this->valueToDeepArray($value->toDeepArray());
        }

        if ($value instanceof self) {
            return $value->toDeepArray();
        }

        if (is_object($value) && method_exists($value, 'toArray')) {
            return $this->valueToDeepArray($value->toArray());
        }

        return $value;
    }

    public function copyProperty(array|self $data): void
    {
        $data = $data instanceof self ? $data->toArray() : $data;
        foreach ($data as $key => $value) {
            if (!is_string($key) && !is_int($key)) {
                continue;
            }
            $name = (string) $key;
            if ($name === '') {
                continue;
            }
            $property = $this->reflectionPropertyForDeclaredField($name);
            if ($property === null || $property->isReadOnly()) {
                continue;
            }
            $property->setAccessible(true);
            $property->setValue($this, $value);
        }
    }

    public function copyDeepProperty(array|self $data): void
    {
        $data = $data instanceof self ? $data->toArray() : $data;
        foreach ($data as $key => $value) {
            if (!is_string($key) && !is_int($key)) {
                continue;
            }
            $name = (string) $key;
            if ($name === '') {
                continue;
            }
            $property = $this->reflectionPropertyForDeclaredField($name);
            if ($property === null || $property->isReadOnly()) {
                continue;
            }
            $property->setAccessible(true);
            $property->setValue($this, $this->valueForDeepProperty($property, $value));
        }
    }

    private function valueForDeepProperty(ReflectionProperty $property, mixed $value): mixed
    {
        if ($value instanceof SdkArrayInterface) {
            return $value;
        }

        // copyDeepProperty：JSON 数组 -> SdkArrayInteger / SdkArrayString
        if (is_array($value)) {
            $arrayStructClass = $this->arrayStructClassFromProperty($property);
            if ($arrayStructClass !== null) {
                return new $arrayStructClass($value);
            }
        }

        if ($value instanceof self) {
            $value = $value->toArray();
        }

        if (!is_array($value)) {
            return $value;
        }

        if ($property->isInitialized($this)) {
            $currentValue = $property->getValue($this);
            if ($currentValue instanceof self) {
                $currentValue->copyDeepProperty($value);

                return $currentValue;
            }
        }

        $dto = $this->newDtoFromPropertyType($property);
        if ($dto === null) {
            return $value;
        }

        $dto->copyDeepProperty($value);

        return $dto;
    }

    /** 解析属性类型是否为 SdkArrayInteger / SdkArrayString 等集合类 */
    private function arrayStructClassFromProperty(ReflectionProperty $property): ?string
    {
        $type = $property->getType();
        if (!$type instanceof \ReflectionNamedType || $type->isBuiltin()) {
            return null;
        }

        $className = $type->getName();
        if (!is_a($className, SdkArrayInterface::class, true)) {
            return null;
        }

        return $className;
    }

    private function newDtoFromPropertyType(ReflectionProperty $property): ?self
    {
        $type = $property->getType();
        if (!$type instanceof \ReflectionNamedType || $type->isBuiltin()) {
            return null;
        }

        $className = $type->getName();
        if (!is_a($className, self::class, true)) {
            return null;
        }

        $class = new \ReflectionClass($className);
        if (!$class->isInstantiable()) {
            return null;
        }

        $constructor = $class->getConstructor();
        if ($constructor !== null && $constructor->getNumberOfRequiredParameters() > 0) {
            return null;
        }

        return $class->newInstance();
    }

    private function reflectionPropertyForDeclaredField(string $name): ?ReflectionProperty
    {
        for (
            $class = new \ReflectionClass($this);
            $class !== null && $class->getName() !== 'stdClass';
            $class = $class->getParentClass()
        ) {
            if (!$class->hasProperty($name)) {
                continue;
            }
            $property = $class->getProperty($name);
            if ($property->isStatic()) {
                return null;
            }

            return $property;
        }

        return null;
    }
}
