<?php

declare(strict_types=1);

namespace __SDK_SUPPORT_NAMESPACE__;

/**
 * SDK copy: typed array collections (ArrayInteger, ArrayString, …).
 */
interface SdkArrayInterface
{
    public function toArray(): array;

    public function toDeepArray(): array;
}
