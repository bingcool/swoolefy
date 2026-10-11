<?php

declare(strict_types=1);

namespace Swoolefy\Support\Amqp;

/**
 * 读取应用 Config\AmqpConfig 的声明和消费参数。
 */
final class AmqpSettings
{
    /**
     * @return array<string, mixed>
     */
    public static function exchange(): array
    {
        return self::call('exchangeProperty');
    }

    /**
     * @return array<string, mixed>
     */
    public static function queue(): array
    {
        return self::call('queueProperty');
    }

    /**
     * @return array<string, mixed>
     */
    public static function consume(): array
    {
        return self::call('consumeProperty');
    }

    /**
     * @return array<string, mixed>
     */
    public static function publish(): array
    {
        return self::call('publishProperty');
    }

    /**
     * 死信交换机类型必须和 Broker 上已有的同名交换机一致。
     */
    public static function deadLetterExchangeType(): string
    {
        $class = self::configClass();
        $constant = $class . '::COMMON_DLX_EXCHANGE_TYPE';
        if (defined($constant)) {
            $type = constant($constant);
            if (is_string($type) && $type !== '') {
                return strtolower($type);
            }
        }

        return 'topic';
    }

    /**
     * @return array<string, mixed>
     */
    private static function call(string $method): array
    {
        $class = self::configClass();
        if (!method_exists($class, $method)) {
            throw new AmqpException($class . '::' . $method . ' is missing');
        }
        $value = $class::$method();
        if (!is_array($value)) {
            throw new AmqpException($class . '::' . $method . ' must return an array');
        }

        return $value;
    }

    private static function configClass(): string
    {
        if (!defined('APP_NAME') || !is_string(APP_NAME) || APP_NAME === '') {
            throw new AmqpException('APP_NAME is required to load AmqpConfig');
        }
        $class = APP_NAME . '\\Config\\AmqpConfig';
        if (!class_exists($class)) {
            throw new AmqpException($class . ' is missing');
        }

        return $class;
    }
}
