<?php

declare(strict_types=1);

namespace Swoolefy\Annotation;

use Attribute;

/**
 * Documents a property for API specs (e.g. OpenAPI); not used by ValidationRule or RequestValidate.
 */
#[Attribute(Attribute::TARGET_PROPERTY)]
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
