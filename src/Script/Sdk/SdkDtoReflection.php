<?php

declare(strict_types=1);

namespace Swoolefy\Script\Sdk;

use ReflectionClass;
use ReflectionMethod;
use ReflectionNamedType;
use ReflectionProperty;
use ReflectionUnionType;
use Swoolefy\Annotation\ArrayList;
use Swoolefy\Annotation\Validation\ValidationRule;

/**
 * Reads ValidationRule / ArrayList / @var array<X> on app DTOs for SDK generation.
 */
final class SdkDtoReflection
{
    /**
     * @return list<string> FQCN under app namespace referenced via itemClass, ArrayList::class, or @var array<...>
     */
    public static function collectLinkedTestClassesFromAttributes(string $classFqcn, string $appNamespacePrefix): array
    {
        if (!str_starts_with($classFqcn, $appNamespacePrefix) || !class_exists($classFqcn)) {
            return [];
        }

        try {
            $rc = new ReflectionClass($classFqcn);
        } catch (\ReflectionException) {
            return [];
        }

        $found = [];
        foreach ($rc->getProperties() as $prop) {
            if ($prop->getDeclaringClass()->getName() !== $rc->getName()) {
                continue;
            }
            if ($prop->isStatic()) {
                continue;
            }
            foreach (self::itemAndSingleClassesFromProperty($prop) as $fqcn) {
                if (str_starts_with($fqcn, $appNamespacePrefix)) {
                    $found[$fqcn] = true;
                }
            }
            foreach (self::docblockArrayItemFqcnCandidates($prop) as $fqcn) {
                if (str_starts_with($fqcn, $appNamespacePrefix)) {
                    $found[$fqcn] = true;
                }
            }
        }

        return array_keys($found);
    }

    /**
     * @return list<string>
     */
    private static function itemAndSingleClassesFromProperty(ReflectionProperty $prop): array
    {
        $out = [];
        foreach ($prop->getAttributes(ValidationRule::class) as $a) {
            try {
                $rule = $a->newInstance();
            } catch (\Throwable) {
                continue;
            }
            $ic = $rule->getItemClass();
            if ($ic !== '' && class_exists($ic)) {
                $out[] = $ic;
            }
        }
        foreach ($prop->getAttributes(ArrayList::class) as $a) {
            try {
                $rp = $a->newInstance();
            } catch (\Throwable) {
                continue;
            }
            if ($rp->getItemClass() !== '') {
                $ic = $rp->getItemClass();
                if (class_exists($ic)) {
                    $out[] = $ic;
                }
            }
        }

        return $out;
    }

    /**
     * @return list<string>
     */
    private static function docblockArrayItemFqcnCandidates(ReflectionProperty $prop): array
    {
        $doc = $prop->getDocComment();
        if ($doc === false || $doc === '') {
            return [];
        }
        if (!preg_match_all('/@var\s+array<([^>]+)>/', $doc, $m)) {
            return [];
        }
        $out = [];
        foreach ($m[1] as $inner) {
            foreach (preg_split('/\s*,\s*/', trim($inner)) as $part) {
                $part = trim($part);
                if ($part === '' || self::isBuiltinNamedType($part)) {
                    continue;
                }
                $fq = self::resolveTypeToFqcn($prop, $part);
                if ($fq !== null) {
                    $out[] = $fq;
                }
            }
        }

        return array_values(array_unique($out));
    }

    private static function isBuiltinNamedType(string $part): bool
    {
        $l = strtolower($part);

        return in_array($l, ['int', 'string', 'float', 'bool', 'mixed', 'array', 'object', 'callable', 'iterable', 'false', 'true', 'null'], true);
    }

    private static function resolveTypeToFqcn(ReflectionProperty $prop, string $shortOrRelative): ?string
    {
        $shortOrRelative = trim($shortOrRelative);
        if ($shortOrRelative === '') {
            return null;
        }
        if (str_starts_with($shortOrRelative, '\\')) {
            $c = substr($shortOrRelative, 1);

            return class_exists($c) ? $c : null;
        }
        $dc = $prop->getDeclaringClass();
        $ns = $dc->getNamespaceName();
        if ($ns !== '') {
            $candidate = $ns . '\\' . $shortOrRelative;
            if (class_exists($candidate)) {
                return $candidate;
            }
        }
        if (class_exists($shortOrRelative)) {
            return $shortOrRelative;
        }

        return null;
    }

    /**
     * @return list<array{property:string, itemFqcn:string, methodName:string}>
     */
    public static function listCollectionAdderSpecs(string $classFqcn, string $appNamespacePrefix): array
    {
        if (!str_starts_with($classFqcn, $appNamespacePrefix) || !class_exists($classFqcn)) {
            return [];
        }

        try {
            $rc = new ReflectionClass($classFqcn);
        } catch (\ReflectionException) {
            return [];
        }

        $reserved = [];
        foreach ($rc->getMethods() as $m) {
            if ($m->getDeclaringClass()->getName() === $rc->getName()) {
                $reserved[strtolower($m->getName())] = true;
            }
        }

        $specs = [];

        foreach ($rc->getProperties() as $prop) {
            if ($prop->getDeclaringClass()->getName() !== $rc->getName()) {
                continue;
            }
            if ($prop->isStatic()) {
                continue;
            }
            if ($prop->isReadOnly()) {
                continue;
            }
            if (!self::propertySupportsArrayAppend($prop)) {
                continue;
            }

            $itemFqcn = self::resolveCollectionItemFqcn($prop);
            if ($itemFqcn === null || !str_starts_with($itemFqcn, $appNamespacePrefix)) {
                continue;
            }
            if (!self::isLikelyDtoItemClass($itemFqcn)) {
                continue;
            }
            if (self::classHasManualAddForItemType($rc, $itemFqcn)) {
                continue;
            }

            $short = self::classBasename($itemFqcn);
            $baseMethod = 'add' . $short;
            $methodName = $baseMethod;
            if (isset($reserved[strtolower($methodName)])) {
                $methodName = 'add' . $short . 'To' . self::propertyToPascal($prop->getName());
            }
            if (isset($reserved[strtolower($methodName)])) {
                continue;
            }
            $reserved[strtolower($methodName)] = true;

            $specs[] = [
                'property' => $prop->getName(),
                'itemFqcn' => $itemFqcn,
                'methodName' => $methodName,
            ];
        }

        return $specs;
    }

    /**
     * True when the declaring class already has an instance "add*" method whose first parameter is $itemFqcn.
     */
    private static function classHasManualAddForItemType(ReflectionClass $rc, string $itemFqcn): bool
    {
        foreach ($rc->getMethods(ReflectionMethod::IS_PUBLIC | ReflectionMethod::IS_PROTECTED) as $m) {
            if ($m->getDeclaringClass()->getName() !== $rc->getName()) {
                continue;
            }
            if ($m->isStatic()) {
                continue;
            }
            if (!str_starts_with(strtolower($m->getName()), 'add')) {
                continue;
            }
            $params = $m->getParameters();
            if (!isset($params[0])) {
                continue;
            }
            $t = $params[0]->getType();
            foreach (self::reflectionNamedTypes($t) as $name) {
                if ($name === $itemFqcn) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * @return list<string>
     */
    private static function reflectionNamedTypes(?\ReflectionType $type): array
    {
        if ($type === null) {
            return [];
        }
        if ($type instanceof ReflectionNamedType) {
            return $type->isBuiltin() ? [] : [$type->getName()];
        }
        if ($type instanceof ReflectionUnionType) {
            $names = [];
            foreach ($type->getTypes() as $t) {
                if ($t instanceof ReflectionNamedType && !$t->isBuiltin()) {
                    $names[] = $t->getName();
                }
            }

            return $names;
        }

        return [];
    }

    private static function propertySupportsArrayAppend(ReflectionProperty $prop): bool
    {
        $t = $prop->getType();
        if ($t === null) {
            if ($prop->hasDefaultValue() && is_array($prop->getDefaultValue())) {
                return true;
            }

            return false;
        }
        if ($t instanceof ReflectionNamedType) {
            if ($t->getName() === 'array') {
                return true;
            }
            if ($t->getName() === 'mixed') {
                return true;
            }
        }

        return false;
    }

    private static function resolveCollectionItemFqcn(ReflectionProperty $prop): ?string
    {
        foreach ($prop->getAttributes(ValidationRule::class) as $a) {
            try {
                $rule = $a->newInstance();
            } catch (\Throwable) {
                continue;
            }
            $ic = $rule->getItemClass();
            if ($ic !== '') {
                return $ic;
            }
        }
        foreach ($prop->getAttributes(ArrayList::class) as $a) {
            try {
                $rp = $a->newInstance();
            } catch (\Throwable) {
                continue;
            }
            if ($rp->getItemClass() !== '') {
                return $rp->getItemClass();
            }
        }

        foreach (self::docblockArrayItemFqcnCandidates($prop) as $fq) {
            if (self::isLikelyDtoItemClass($fq)) {
                return $fq;
            }
        }

        return null;
    }

    private static function isLikelyDtoItemClass(string $fqcn): bool
    {
        if (!class_exists($fqcn)) {
            return false;
        }

        return is_a($fqcn, \Swoolefy\Core\Dto\AbstractDto::class, true)
            || is_a($fqcn, \Swoolefy\Http\BaseResponse::class, true)
            || is_a($fqcn, \Swoolefy\Http\BaseRequest::class, true);
    }

    private static function classBasename(string $fqcn): string
    {
        $p = strrpos($fqcn, '\\');

        return $p === false ? $fqcn : substr($fqcn, $p + 1);
    }

    private static function propertyToPascal(string $name): string
    {
        return str_replace(' ', '', ucwords(str_replace('_', ' ', $name)));
    }
}
