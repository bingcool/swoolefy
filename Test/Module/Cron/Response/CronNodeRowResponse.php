<?php

declare(strict_types=1);

namespace Test\Module\Cron\Response;

use Swoolefy\Annotation\ApiProperty;
use Swoolefy\Http\BaseResponse;

class CronNodeRowResponse extends BaseResponse
{
    /**
     * @var array<string, mixed>
     */
    #[ApiProperty(description: '节点字段集合')]
    protected array $attributes;

    /**
     * @param array<string, mixed> $attributes
     */
    public function __construct(array $attributes)
    {
        $this->setAttributes($attributes);
    }

    /**
     * @return array<string, mixed>
     */
    public function getAttributes(): array
    {
        return $this->attributes;
    }

    /**
     * @param array<string, mixed> $attributes
     */
    public function setAttributes(array $attributes): self
    {
        $this->attributes = $attributes;

        return $this;
    }

    public function getData(): array
    {
        return $this->getAttributes();
    }
}
