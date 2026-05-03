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

namespace Swoolefy\Http;

use BackedEnum;
use DateTimeInterface;
use JsonSerializable;
use ReflectionClass;
use ReflectionMethod;
use ReflectionProperty;
use RuntimeException;
use SplObjectStorage;
use Swoolefy\Annotation\IntToString;
use Swoolefy\Core\Dto\AbstractDto;
use Swoolefy\Core\ResponseFormatter;
use Swoolefy\Util\ArrayHelper\Arrayable;
use UnitEnum;

class ActionResultNormalizer
{
    /**
     * Normalize controller action return values to the framework response structure.
     *
     * @param mixed $result
     * @return array
     */
    public static function normalize($result): array
    {
        if ($result instanceof BaseResponse) {
            $result = $result->getData();
        }
        return ResponseFormatter::buildResponseData(ResponseCode::CodeOk, '', static::normalizeData($result, new SplObjectStorage()));
    }

    /**
     * @param mixed $value
     * @param SplObjectStorage $objects
     * @return mixed
     */
    protected static function normalizeData($value, SplObjectStorage $objects)
    {
        if ($value === null || is_scalar($value)) {
            return $value;
        }

        if (is_array($value)) {
            foreach ($value as $key => $item) {
                $value[$key] = static::normalizeData($item, $objects);
            }

            return $value;
        }

        if ($value instanceof BackedEnum) {
            return $value->value;
        }

        if ($value instanceof UnitEnum) {
            return $value->name;
        }

        if ($value instanceof DateTimeInterface) {
            return $value->format(DateTimeInterface::ATOM);
        }

        if ($value instanceof JsonSerializable) {
            return static::normalizeObject($value, $objects, function () use ($value, $objects) {
                return static::normalizeData($value->jsonSerialize(), $objects);
            });
        }

        if ($value instanceof Arrayable) {
            return static::normalizeObject($value, $objects, function () use ($value, $objects) {
                return static::normalizeData($value->toArray([], [], true), $objects);
            });
        }

        if (is_object($value)) {
            return static::normalizeObject($value, $objects, function () use ($value, $objects) {
                if (method_exists($value, 'toArray')) {
                    $method = new ReflectionMethod($value, 'toArray');
                    if ($method->isPublic() && $method->getNumberOfRequiredParameters() === 0) {
                        $declaring = $method->getDeclaringClass()->getName();
                        if ($declaring === AbstractDto::class && $value::class !== AbstractDto::class) {
                            return static::normalizeData(static::readObjectPropertiesAsArray($value), $objects);
                        }

                        return static::normalizeData($value->toArray(), $objects);
                    }
                }

                if ($value instanceof \stdClass) {
                    return static::normalizeData((array) $value, $objects);
                }

                return static::normalizeData(static::readObjectPropertiesAsArray($value), $objects);
            });
        }

        throw new RuntimeException(sprintf('Unsupported action result type `%s`', get_debug_type($value)));
    }

    /**
     * @param object $object
     * @param SplObjectStorage $objects
     * @param callable $normalizer
     * @return mixed
     */
    protected static function normalizeObject(object $object, SplObjectStorage $objects, callable $normalizer)
    {
        if ($objects->contains($object)) {
            throw new RuntimeException(sprintf('Circular reference detected while normalizing `%s`', $object::class));
        }

        $objects->attach($object);
        try {
            return $normalizer();
        } finally {
            $objects->detach($object);
        }
    }

    /**
     * Export instance properties (including private/protected from declaring class) for JSON normalization.
     * Uninitialized typed properties are skipped.
     *
     * @return array<string, mixed>
     */
    protected static function readObjectPropertiesAsArray(object $object): array
    {
        $props = (new ReflectionClass($object))->getProperties(
            ReflectionProperty::IS_PUBLIC | ReflectionProperty::IS_PROTECTED | ReflectionProperty::IS_PRIVATE
        );

        $out = [];
        foreach ($props as $prop) {
            if ($prop->isStatic()) {
                continue;
            }

            $prop->setAccessible(true);
            if (!$prop->isInitialized($object)) {
                continue;
            }

            $exported = $prop->getValue($object);
            if ($prop->getAttributes(IntToString::class) !== []) {
                $exported = static::applyIntToStringOnAnnotatedValue($exported);
            }

            $out[$prop->getName()] = $exported;
        }

        return $out;
    }

    /**
     * @param mixed $value
     * @return mixed
     */
    protected static function applyIntToStringOnAnnotatedValue($value)
    {
        if (is_int($value)) {
            return (string) $value;
        }

        if (is_array($value)) {
            foreach ($value as $k => $item) {
                $value[$k] = static::applyIntToStringOnAnnotatedValue($item);
            }
        }

        return $value;
    }
}
