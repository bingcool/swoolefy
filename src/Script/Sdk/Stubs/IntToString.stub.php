<?php

declare(strict_types=1);

namespace __SDK_SUPPORT_NAMESPACE__;

use Attribute;

/**
 * SDK copy: marks response integer fields that should be treated as strings by clients.
 */
#[Attribute(Attribute::TARGET_PROPERTY)]
final class IntToString
{
}
