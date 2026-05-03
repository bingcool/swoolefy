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

class AbstractDto extends \stdClass
{
    /**
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

    /**
     * __set
     * @param  string $name
     * @param  mixed $value
     */
    public function __set(string $name, $value)
    {
        $this->$name = $value;
    }

    /**
     * __get
     * @param  string $name
     * @return mixed
     */
    public function __get(string $name)
    {
        return $this->$name ?? null;
    }
}