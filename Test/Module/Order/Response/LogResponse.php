<?php
namespace Test\Module\Order\Response;

use Swoolefy\Annotation\ResponseProperty;

class LogResponse extends \Swoolefy\Http\BaseResponse
{
    /**
     * @var array<LogItemDto>
     */
    #[ResponseProperty(
        itemClass: LogItemDto::class
    )]
    protected $data = [];

    public function addLogItemDto(LogItemDto $dto)
    {
        $this->data[] = $dto;
    }
}
