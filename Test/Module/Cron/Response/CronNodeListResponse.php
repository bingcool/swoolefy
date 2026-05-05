<?php

declare(strict_types=1);

namespace Test\Module\Cron\Response;

use Swoolefy\Annotation\ApiProperty;
use Swoolefy\Http\BaseResponse;

class CronNodeListResponse extends BaseResponse
{
    /**
     * @var array<int, array<string, mixed>>
     */
    #[ApiProperty(description: '节点列表')]
    protected array $list;

    /**
     * @param array<int, array<string, mixed>> $list
     */
    public function __construct(array $list)
    {
        $this->setList($list);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getList(): array
    {
        return $this->list;
    }

    /**
     * @param array<int, array<string, mixed>> $list
     */
    public function setList(array $list): self
    {
        $this->list = $list;

        return $this;
    }

    public function getTotal(): int
    {
        return count($this->getList());
    }

    public function getData(): array
    {
        return [
            'total' => $this->getTotal(),
            'list' => $this->getList(),
        ];
    }
}
