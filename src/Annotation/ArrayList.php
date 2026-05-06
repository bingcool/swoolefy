<?php

declare(strict_types=1);

namespace Swoolefy\Annotation;

use Attribute;

/**
 * Documents a response property for API specs (e.g. OpenAPI).
 * When {@see self::$itemClass} is set, the property is documented as `array<ItemDto>` (list of nested schema).
 * Not used by ValidationRule or RequestValidate.
 */
#[Attribute(Attribute::TARGET_PROPERTY)]
final class ArrayList
{
    public function __construct(
        protected string $itemClass = ''
    ) {
    }

    /**
     * Element class for list properties (`array<LogItemDto>` → pass `LogItemDto::class`).
     */
    public function getItemClass(): string
    {
        return $this->itemClass;
    }

}
