<?php

declare(strict_types=1);

namespace Swoolefy\Annotation;

use Attribute;

/**
 * Request body: coerce matching JSON/form string fields to int before validation (safe integer digit strings).
 */
#[Attribute(Attribute::TARGET_PROPERTY)]
final class StringToInt
{
}
