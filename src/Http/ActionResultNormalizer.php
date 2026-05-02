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
use ReflectionMethod;
use RuntimeException;
use SplObjectStorage;
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
                        return static::normalizeData($value->toArray(), $objects);
                    }
                }

                return static::normalizeData(get_object_vars($value), $objects);
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
}
