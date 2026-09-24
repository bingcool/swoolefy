<?php

declare(strict_types=1);

namespace Swoolefy\Http;

use ReflectionProperty;
use Swoolefy\Annotation\IntToString;
use Swoolefy\Annotation\StringToInt;
use Swoolefy\Annotation\Validation\ValidationRule;

/**
 * 同时识别 Swoolefy 与 InterfaceApi\Support 上的契约注解（schedule-job InterfaceApi 迁移）。
 */
final class ContractAnnotation
{
    private const INTERFACE_API_PREFIX = 'InterfaceApi\\Support\\';

    public static function validationRuleClasses(): array
    {
        $classes = [ValidationRule::class];
        $contract = self::INTERFACE_API_PREFIX . 'ValidationRule';
        if (class_exists($contract, false)) {
            $classes[] = $contract;
        }

        return $classes;
    }

    public static function stringToIntClasses(): array
    {
        $classes = [StringToInt::class];
        $contract = self::INTERFACE_API_PREFIX . 'StringToInt';
        if (class_exists($contract, false)) {
            $classes[] = $contract;
        }

        return $classes;
    }

    public static function intToStringClasses(): array
    {
        $classes = [IntToString::class];
        $contract = self::INTERFACE_API_PREFIX . 'IntToString';
        if (class_exists($contract, false)) {
            $classes[] = $contract;
        }

        return $classes;
    }

    /**
     * @return list<\ReflectionAttribute>
     */
    public static function propertyAttributes(ReflectionProperty $property, string ...$classNames): array
    {
        $out = [];
        foreach ($classNames as $className) {
            foreach ($property->getAttributes($className) as $attribute) {
                $out[] = $attribute;
            }
        }

        return $out;
    }

    public static function propertyHasAnyAttribute(ReflectionProperty $property, string ...$classNames): bool
    {
        foreach ($classNames as $className) {
            if ($property->getAttributes($className) !== []) {
                return true;
            }
        }

        return false;
    }
}
