<?php

declare(strict_types=1);

namespace Swoolefy\Support\HeaderPropagation;

use Swoolefy\Core\Coroutine\Context as SwooleContext;

/**
 * 请求头透传上下文。
 *
 * 基于 Swoole 协程上下文隔离，避免并发请求之间串 Header。
 */
final class HeaderContext
{
    private const CONTEXT_KEY = 'swoolefy_header_propagation_headers';

    /**
     * @param array<string, string> $headers
     */
    public static function set(array $headers): void
    {
        $context = self::context();
        if (null === $context) {
            return;
        }

        $context[self::CONTEXT_KEY] = $headers;
    }

    /**
     * @return array<string, string>
     */
    public static function all(): array
    {
        $context = self::context();
        if (null === $context) {
            return [];
        }

        $headers = $context[self::CONTEXT_KEY] ?? [];

        return is_array($headers) ? $headers : [];
    }

    public static function get(string $name, ?string $default = null): ?string
    {
        $headers = self::all();
        $key = strtolower($name);

        return $headers[$key] ?? $default;
    }

    public static function put(string $name, string $value): void
    {
        $headers = self::all();
        $headers[strtolower($name)] = $value;
        self::set($headers);
    }

    public static function clear(): void
    {
        $context = self::context();
        if (null === $context) {
            return;
        }

        unset($context[self::CONTEXT_KEY]);
    }

    private static function context(): ?\ArrayObject
    {
        try {
            $context = SwooleContext::getContext();
        } catch (\Throwable) {
            return null;
        }

        return $context instanceof \ArrayObject ? $context : null;
    }
}
