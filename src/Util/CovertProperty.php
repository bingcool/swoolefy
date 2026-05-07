<?php

declare(strict_types=1);

namespace Swoolefy\Util;

use Swoolefy\Annotation\ArrayList;
use ReflectionClass;
use ReflectionNamedType;
use ReflectionProperty;

final class CovertProperty
{
    public static function toCovertDeepProperty(mixed $data, String $tagetClass): mixed
    {
        if (!class_exists($tagetClass)) {
            throw new \InvalidArgumentException('Target class does not exist: ' . $tagetClass);
        }

        $data = self::normalizeSourceData($data);
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
            $property->setValue($object, self::valueForProperty($property, $value));
            $filled = true;
        }

        return $filled;
    }

    private static function valueForProperty(ReflectionProperty $property, mixed $value): mixed
    {
        $value = self::normalizeSourceData($value);
        $itemClass = self::arrayListItemClass($property);
        if ($itemClass !== null && is_array($value)) {
            foreach ($value as $key => $item) {
                $value[$key] = self::toCovertDeepProperty($item, $itemClass);
            }

            return $value;
        }

        $class = self::propertyObjectClass($property);
        if ($class !== null && $value !== null) {
            return self::toCovertDeepProperty($value, $class);
        }

        return $value;
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
