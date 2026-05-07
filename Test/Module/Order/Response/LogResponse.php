<?php
namespace Test\Module\Order\Response;

use Swoolefy\Annotation\ArrayList;

class LogResponse extends \Swoolefy\Http\BaseResponse
{
    /**
     * @var array<LogContentRespDto>
     */
    #[ArrayList(
        itemClass: LogContentRespDto::class
    )]
    protected array $data = [];

    public function addLogContent(LogContentRespDto $dto)
    {
        $this->data[] = $dto;
    }
}
