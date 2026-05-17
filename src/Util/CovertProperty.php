<?php

declare(strict_types=1);

namespace Swoolefy\Util;

use Swoolefy\Annotation\ArrayList;
use ReflectionClass;
use ReflectionNamedType;
use ReflectionProperty;
use ReflectionType;
use ReflectionUnionType;
use Swoolefy\Core\Dto\ArrayDto;
use Swoolefy\DataStruct\ArrayInteger;
use Swoolefy\DataStruct\ArrayInterface;
use Swoolefy\DataStruct\ArrayString;
use Swoolefy\DataStruct\JsonObject;

final class CovertProperty
{
    public static function toCovertDeepProperty(mixed $data, String $tagetClass): mixed
    {
        if (!class_exists($tagetClass)) {
            throw new \InvalidArgumentException('Target class does not exist: ' . $tagetClass);
        }

        $data = self::normalizeSourceData($data);

        // 目标为 ArrayInteger / ArrayString /JsonObject 时，直接用数组构造集合对象
        if (is_array($data) && (is_a($tagetClass, ArrayInteger::class, true)
                || is_a($tagetClass, ArrayString::class, true)
                || is_a($tagetClass, JsonObject::class, true)
                || is_a($tagetClass, \Swoolefy\Core\Collection::class, true)
            )) {
            return new $tagetClass($data);
        }

        $object = self::newObject($tagetClass);
        if (!is_array($data)) {
            if (method_exists($object, 'setData')) {
                $object->setData($data);
            }

            return $object;
        }

        $filled = self::fillObject($object, $data);
        if (!$filled && method_exists($object, 'setData')) {
            $object->setData($data);
        }

        return $object;
    }

    private static function fillObject(object $object, array $data): bool
    {
        $filled = false;
        foreach ($data as $key => $value) {
            if (!is_string($key) && !is_int($key)) {
                continue;
            }

            $property = self::reflectionPropertyForDeclaredField($object, (string) $key);
            if ($property === null || $property->isReadOnly()) {
                continue;
            }

            $property->setAccessible(true);
            $convertedValue = self::valueForProperty($property, $value);
            $property->setValue($object, $convertedValue);
            $filled = true;
        }

        return $filled;
    }

    private static function valueForProperty(ReflectionProperty $property, mixed $value): mixed
    {
        $value = self::normalizeSourceData($value);
        
        // 检查是否有 ArrayList 注解
        $itemClass = self::arrayListItemClass($property);
        if ($itemClass !== null && is_array($value)) {
            // 对数组中的每个元素进行递归转换
            $convertedItems = [];
            foreach ($value as $key => $item) {
                $convertedItems[$key] = self::toCovertDeepProperty($item, $itemClass);
            }
            return $convertedItems;
        }

        $class = self::propertyObjectClass($property);
        if ($class !== null && $value !== null) {
            return self::toCovertDeepProperty($value, $class);
        }

        return $value;
    }

    /**
     * 按属性/setter 声明的类型转换请求入参。
     *
     * 典型场景：HTTP JSON 中 userIds 为 [1,2,3]，setter 签名为 setUserIds(?ArrayInteger $userIds)，
     * 需自动 new ArrayInteger($value)，避免 array 直接传入导致类型错误。
     *
     * @param mixed $value 原始请求值（array、JSON 字符串或已构造的对象）
     * @param ReflectionType|null $type setter 第一个参数或属性的反射类型
     */
    public static function coerceValueForDeclaredType(mixed $value, ?ReflectionType $type): mixed
    {
        if ($type === null) {
            return $value;
        }

        $class = self::reflectionTypeClassName($type);
        if ($class === null) {
            return $value;
        }

        if ($value === null) {
            return self::reflectionTypeAllowsNull($type) ? null : $value;
        }

        if (is_object($value) && is_a($value, $class, true)) {
            return $value;
        }

        if (is_string($value) && (str_starts_with($value, '{') || str_starts_with($value, '['))) {
            $decoded = json_decode($value, true);
            if (json_last_error() === JSON_ERROR_NONE) {
                $value = $decoded;
            }
        }

        if (!is_array($value)) {
            return $value;
        }

        // 标量数组集合：构造函数接收 array
        if (is_a($class, ArrayInteger::class, true) || is_a($class, ArrayString::class, true)) {
            return new $class($value);
        }

        // DTO（含 CityDto）也实现 ArrayInterface，但需走属性填充而非 new Dto($array)
        if (is_subclass_of($class, ArrayDto::class, true)) {
            return self::toCovertDeepProperty($value, $class);
        }

        if (is_a($class, JsonObject::class, true) || is_a($class, ArrayInterface::class, true)) {
            return new $class($value);
        }

        return self::toCovertDeepProperty($value, $class);
    }

    /**
     * 判断反射类型是否允许 null（含 ?Type 与 Type|null 联合类型）
     */
    private static function reflectionTypeAllowsNull(ReflectionType $type): bool
    {
        if ($type instanceof ReflectionNamedType) {
            return $type->allowsNull();
        }

        if ($type instanceof ReflectionUnionType) {
            foreach ($type->getTypes() as $member) {
                if ($member instanceof ReflectionNamedType && $member->getName() === 'null') {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * 从反射类型解析非内置类名（跳过 int/string 等；联合类型取第一个对象类型）
     */
    private static function reflectionTypeClassName(ReflectionType $type): ?string
    {
        if ($type instanceof ReflectionNamedType) {
            if ($type->isBuiltin()) {
                return null;
            }
            $class = $type->getName();

            return class_exists($class) ? $class : null;
        }

        if ($type instanceof ReflectionUnionType) {
            foreach ($type->getTypes() as $member) {
                $class = self::reflectionTypeClassName($member);
                if ($class !== null) {
                    return $class;
                }
            }
        }

        return null;
    }

    private static function arrayListItemClass(ReflectionProperty $property): ?string
    {
        foreach ($property->getAttributes(ArrayList::class) as $attribute) {
            $arrayList = $attribute->newInstance();
            $itemClass = $arrayList->getItemClass();
            if ($itemClass === '') {
                continue;
            }
            if (!class_exists($itemClass)) {
                throw new \InvalidArgumentException('ArrayList item class does not exist: ' . $itemClass);
            }

            return $itemClass;
        }

        return null;
    }

    private static function propertyObjectClass(ReflectionProperty $property): ?string
    {
        $type = $property->getType();
        if (!$type instanceof ReflectionNamedType || $type->isBuiltin()) {
            return null;
        }

        $class = $type->getName();
        if (!class_exists($class)) {
            return null;
        }

        return $class;
    }

    private static function normalizeSourceData(mixed $data): mixed
    {
        if (is_array($data)) {
            foreach ($data as $key => $value) {
                $data[$key] = self::normalizeSourceData($value);
            }

            return $data;
        }

        if (is_object($data) && method_exists($data, 'toDeepArray')) {
            return self::normalizeSourceData($data->toDeepArray());
        }

        if (is_object($data) && method_exists($data, 'toArray')) {
            return self::normalizeSourceData($data->toArray());
        }

        return $data;
    }

    private static function newObject(string $class): object
    {
        return (new ReflectionClass($class))->newInstanceWithoutConstructor();
    }

    private static function reflectionPropertyForDeclaredField(object $object, string $name): ?ReflectionProperty
    {
        if ($name === '') {
            return null;
        }

        for (
            $class = new ReflectionClass($object);
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
