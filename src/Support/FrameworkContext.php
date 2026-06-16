<?php

declare(strict_types=1);

namespace Swoolefy\Support;

use Swoolefy\Support\HeaderPropagation\HeaderContext;
use Swoolefy\Support\HeaderPropagation\HeaderPropagator;

/**
 * 框架请求上下文门面。
 *
 * 当前主要读取 HeaderContext 中的上游透传 Header；后续如需扩展租户、用户、
 * trace 等上下文，可继续在这里提供语义化方法，避免业务层散落具体 Header 名。
 */
final class FrameworkContext
{
    public static function get(string $name, ?string $default = null): ?string
    {
        return HeaderContext::get($name, $default);
    }

    public static function getUserId(?string $default = null): ?string
    {
        return self::get(HeaderPropagator::HEADER_USER_ID, $default);
    }

    public static function getTenantId(?string $default = null): ?string
    {
        return self::get(HeaderPropagator::HEADER_TENANT_ID, $default);
    }

    public static function getTraceId(?string $default = null): ?string
    {
        return self::get(HeaderPropagator::HEADER_TRACE_ID, $default);
    }

    public static function getUserAgent(?string $default = null): ?string
    {
        return self::get(HeaderPropagator::HEADER_USER_AGENT, $default);
    }
}
