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

    /** @var array<string, bool> */
    private static array $contractLoaded = [];

    public static function validationRuleClasses(): array
    {
        $classes = [ValidationRule::class];
        $contract = self::INTERFACE_API_PREFIX . 'ValidationRule';
        if (self::contractLoaded($contract)) {
            $classes[] = $contract;
        }

        return $classes;
    }

    public static function stringToIntClasses(): array
    {
        $classes = [StringToInt::class];
        $contract = self::INTERFACE_API_PREFIX . 'StringToInt';
        if (self::contractLoaded($contract)) {
            $classes[] = $contract;
        }

        return $classes;
    }

    public static function intToStringClasses(): array
    {
        $classes = [IntToString::class];
        $contract = self::INTERFACE_API_PREFIX . 'IntToString';
        if (self::contractLoaded($contract)) {
            $classes[] = $contract;
        }

        return $classes;
    }

    /**
     * 契约注解类是否可用。禁用 autoload 会让冷 Worker 永远看不见 InterfaceApi\ 上的注解
     * （`use` 语句不触发加载，而 newInstance() 又只有命中名单才会执行），
     * 于是校验和 StringToInt 转换整条链路静默失效。
     * 必须确认它自己带 #[Attribute]——Attribute 不被继承，缺失时 newInstance() 直接 fatal。
     */
    private static function contractLoaded(string $contract): bool
    {
        if (isset(self::$contractLoaded[$contract])) {
            return self::$contractLoaded[$contract];
        }

        return self::$contractLoaded[$contract] = class_exists($contract, true)
            && (new \ReflectionClass($contract))->getAttributes(\Attribute::class) !== [];
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
