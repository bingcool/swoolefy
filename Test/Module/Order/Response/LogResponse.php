<?php
namespace Test\Module\Order\Response;

use Swoolefy\Annotation\ArrayList;

class LogResponse extends \Swoolefy\Http\BaseResponse
{
    /**
     * @var array<LogItemDto>
     */
    #[ArrayList(
        itemClass: LogItemDto::class
    )]
    protected array $data = [];

    public function addLogItemDto(LogItemDto $dto)
    {
        $this->data[] = $dto;
    }
}
