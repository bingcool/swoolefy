<?php

declare(strict_types=1);

namespace __SDK_SUPPORT_NAMESPACE__;

/**
 * SDK copy of core DTO base (no framework deps).
 */
class SdkAbstractDto extends SdkArrayDto
{

    public function __set(string $name, $value): void
    {
        $this->$name = $value;
    }

    public function __get(string $name)
    {
        return $this->$name ?? null;
    }
}
