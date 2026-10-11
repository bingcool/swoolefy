<?php

declare(strict_types=1);

namespace Swoolefy\Support\Kafka;

/**
 * 读取应用 Config\KafkaConfig 的 librdkafka 属性。
 */
final class KafkaSettings
{
    /**
     * @return array<string, mixed>
     */
    public static function producerGlobal(): array
    {
        return self::call('producerGlobalProperty');
    }

    /**
     * @return array<string, mixed>
     */
    public static function producerTopic(): array
    {
        return self::call('producerTopicProperty');
    }

    /**
     * @return array<string, mixed>
     */
    public static function consumerGlobal(): array
    {
        return self::call('consumerGlobalProperty');
    }

    /**
     * @return array<string, mixed>
     */
    public static function consumerTopic(): array
    {
        return self::call('consumerTopicProperty');
    }

    public static function apply(object $conf, array $properties): void
    {
        foreach ($properties as $name => $value) {
            if (!is_string($name) || $name === '') {
                throw new KafkaException('kafka config name is invalid');
            }
            if (is_bool($value)) {
                $value = $value ? 'true' : 'false';
            } elseif (is_int($value) || is_float($value)) {
                $value = (string) $value;
            }
            if (!is_string($value)) {
                throw new KafkaException('kafka config ' . $name . ' must be a scalar');
            }
            $conf->set($name, $value);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private static function call(string $method): array
    {
        $class = self::configClass();
        if (!method_exists($class, $method)) {
            throw new KafkaException($class . '::' . $method . ' is missing');
        }
        $value = $class::$method();
        if (!is_array($value)) {
            throw new KafkaException($class . '::' . $method . ' must return an array');
        }

        return $value;
    }

    private static function configClass(): string
    {
        if (!defined('APP_NAME') || !is_string(APP_NAME) || APP_NAME === '') {
            throw new KafkaException('APP_NAME is required to load KafkaConfig');
        }
        $class = APP_NAME . '\\Config\\KafkaConfig';
        if (!class_exists($class)) {
            throw new KafkaException($class . ' is missing');
        }

        return $class;
    }
}
