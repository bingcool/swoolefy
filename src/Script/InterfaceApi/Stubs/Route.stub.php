<?php

declare(strict_types=1);

namespace __INTERFACE_API_SUPPORT_NAMESPACE__;

use Attribute;

#[Attribute(Attribute::TARGET_METHOD)]
final class Route
{
    public function __construct(
        public readonly string $method,
        public readonly string $path,
    ) {
    }
}
