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
        protected string $itemRule = '', // 数组item
        protected string|array $itemMessage = [],
        protected string $itemClass = '', // 数组class
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

    public function getItemRule(): string
    {
        return $this->itemRule;
    }

    public function getItemMessage(): string|array
    {
        return $this->itemMessage;
    }

    public function getItemClass(): string
    {
        return $this->itemClass;
    }
}
