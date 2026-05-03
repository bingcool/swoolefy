<?php

declare(strict_types=1);

namespace Swoolefy\Annotation;

use Attribute;

/**
 * Response JSON: coerce int values under this property to string (avoid JS Number precision loss beyond 2^53-1).
 */
#[Attribute(Attribute::TARGET_PROPERTY)]
final class IntToString
{
}
