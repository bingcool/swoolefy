<?php

declare(strict_types=1);

namespace PhpUintTest\Unit\Http\Support;

use Swoolefy\Annotation\ApiProperty;
use Swoolefy\Annotation\Validation\ValidationRule;
use Swoolefy\Http\BaseRequest;

/**
 * BaseRequest 读参助手单测样例。
 *
 * @see \PhpUintTest\Unit\Http\BaseRequestTest
 */
final class SampleCreateUserRequest extends BaseRequest
{
    #[ApiProperty(description: '用户名')]
    #[ValidationRule(rule: 'required|string|min:2', message: 'username 无效')]
    protected string $username = '';

    #[ApiProperty(description: '是否启用')]
    #[ValidationRule(rule: 'nullable|bool')]
    protected bool $enabled = true;

    #[ApiProperty(description: '年龄')]
    #[ValidationRule(rule: 'nullable|int|min:0')]
    protected ?int $age = null;

    public function getUsername(): string
    {
        return $this->username;
    }

    public function setUsername(string $username): static
    {
        $this->username = $username;

        return $this;
    }

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    public function setEnabled(bool $enabled): static
    {
        $this->enabled = $enabled;

        return $this;
    }

    public function getAge(): ?int
    {
        return $this->age;
    }

    public function setAge(?int $age): static
    {
        $this->age = $age;

        return $this;
    }
}
