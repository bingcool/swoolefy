<?php

declare(strict_types=1);

namespace __INTERFACE_API_SUPPORT_NAMESPACE__;

class BaseResponse extends ArrayDto
{
    protected int $code = 0;

    protected string $msg = 'success';

    public function getCode(): int
    {
        return $this->code;
    }

    public function getMsg(): string
    {
        return $this->msg;
    }

    public function getData(): mixed
    {
        return $this->data ?? null;
    }
}
