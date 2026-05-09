<?php

declare(strict_types=1);

namespace Swoolefy\Annotation;

use Attribute;

/**
 * Controller Action Description Annotation
 */
#[Attribute(Attribute::TARGET_METHOD)]
final class ApiOperation
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
