<?php

declare(strict_types=1);

namespace __SDK_SUPPORT_NAMESPACE__;

class SdkBaseResponse extends SdkArrayDto
{
    protected int $code = 0;

    protected string $msg = 'success';
    
    protected string $trace_id = '';
        
    public function setCode(int $code): static
    {
        $this->code = $code;

        return $this;
    }

    public function getCode(): int
    {
        return $this->code;
    }

    public function setMsg(string $msg): static
    {
        $this->message = $msg;

        return $this;
    }

    public function getMsg(): string
    {
        return $this->message;
    }
    
    public function getTraceId(): string
    {
        return $this->trace_id;
    }

    public function setData(mixed $data): static
    {
        $this->data = $data;

        return $this;
    }

    public function getData(): mixed
    {
        return $this->data;
    }
}
