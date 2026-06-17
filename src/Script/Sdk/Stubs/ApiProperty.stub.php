<?php

declare(strict_types=1);

namespace __SDK_SUPPORT_NAMESPACE__;

use Attribute;

/**
 * SDK copy: documents a property (or method) for client-side hints; no framework dependency.
 */
#[Attribute(Attribute::TARGET_PROPERTY | Attribute::TARGET_METHOD)]
final class ApiProperty
{
    public function __construct(
        protected string $description = ''
    ) {
    }

    public function getDescription(): string
    {
        return $this->description;
    }
}
