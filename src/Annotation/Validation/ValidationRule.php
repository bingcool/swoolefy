<?php

declare(strict_types=1);

namespace Swoolefy\Annotation\Validation;

use Attribute;

#[Attribute(Attribute::TARGET_PROPERTY)]
class ValidationRule extends AbstractValidationRule
{
    public function __construct(
        protected string $rule = '',
        protected string|array $message = [],
    ) {
    }

    public function getRule(): string
    {
        return $this->rule;
    }

    public function getMessage(): string|array
    {
        return $this->message;
    }
}
