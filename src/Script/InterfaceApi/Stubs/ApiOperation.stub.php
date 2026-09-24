<?php

declare(strict_types=1);

namespace __INTERFACE_API_SUPPORT_NAMESPACE__;

use Attribute;

#[Attribute(Attribute::TARGET_METHOD)]
final class ApiOperation
{
    public function __construct(
        protected string $description = '',
        protected string $summary = '',
    ) {
    }

    public function getDescription(): string
    {
        return $this->description;
    }

    public function getSummary(): string
    {
        return $this->summary;
    }
}
