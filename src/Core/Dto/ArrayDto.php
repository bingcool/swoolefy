<?php
/**
 * +----------------------------------------------------------------------
 * | swoolefy framework bases on swoole extension development, we can use it easily!
 * +----------------------------------------------------------------------
 * | Licensed ( https://opensource.org/licenses/MIT )
 * +----------------------------------------------------------------------
 * | @see https://github.com/bingcool/swoolefy
 * +----------------------------------------------------------------------
 */

namespace Swoolefy\Core\Dto;

use ReflectionProperty;
use Swoolefy\Util\CovertProperty;

class ArrayDto extends \stdClass
{
    /**
     * 转成浅数组，当属性是对象时，对象不会被转换成数组，依然保留对象
     *
     * Public associative array (never `(array)`-cast mangled keys for private properties).
     * Nested objects are left as-is; callers may recurse (e.g. ActionResultNormalizer).
     */
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

    /**
     * 转成深度数组，当属性是对象或者数组中包含对象时，会递归转换成数组
     *
     * Recursively convert this DTO and nested array / object values to arrays.
     */
    public function toDeepArray(): array
    {
        return $this->valueToDeepArray($this->toArray());
    }

    /**
     * 递归转换任意值，数组和可转数组对象会继续向下转换，标量值原样返回
     */
    private function valueToDeepArray(mixed $value): mixed
    {
        if (is_array($value)) {
            foreach ($value as $key => $item) {
                $value[$key] = $this->valueToDeepArray($item);
            }

            return $value;
        }

        if ($value instanceof self) {
            return $value->toDeepArray();
        }

        if (is_object($value) && method_exists($value, 'toArray')) {
            return $this->valueToDeepArray($value->toArray());
        }

        return $value;
    }

    /**
     * 将数组或DTO中的值复制到当前DTO已声明的实例属性上
     * 未声明字段、静态属性和只读属性会被忽略
     *
     * Assign values from $data only onto declared instance fields of $this (public / protected / private, including inherited).
     * Unknown keys, static fields and readonly properties are ignored.
     */
    public function copyProperty(array|AbstractDto $data): void
    {
        $data = $data instanceof AbstractDto ? $data->toArray() : $data;
        foreach ($data as $key => $value) {
            if (!is_string($key) && !is_int($key)) {
                continue;
            }
            $name = (string) $key;
            if ($name === '') {
                continue;
            }

            $property = $this->reflectionPropertyForDeclaredField($name);
            if ($property === null) {
                continue;
            }

            if ($property->isReadOnly()) {
                continue;
            }

            $property->setAccessible(true);
            $property->setValue($this, $value);
        }
    }

    /**
     * 深度复制属性，当目标属性是DTO对象时，会递归复制到该DTO对象中
     * Assign values recursively when a declared property is another DTO object.
     * @param array|AbstractDto $data 需要复制的数据|对象
     */
    public function copyDeepProperty(array|AbstractDto $data): void
    {
        $dto = CovertProperty::toCovertDeepProperty($data, static::class);
        if (is_object($dto) && method_exists($dto, 'toArray')) {
            $this->copyProperty($dto->toArray());
        }
    }

    /**
     * 查找当前对象中已声明的非静态实例属性，包含父类属性但跳过stdClass
     *
     * Find the declaring ReflectionProperty for a non-static instance field on $this (walks class parents, skips stdClass).
     */
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
