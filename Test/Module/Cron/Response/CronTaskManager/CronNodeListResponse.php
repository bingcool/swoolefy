<?php

declare(strict_types=1);


namespace Test\Module\Cron\Response\CronTaskManager;

use Test\Module\Cron\Dto\CronTaskManager\CronAgentNodeRowDto;
use InvalidArgumentException;
use Swoolefy\Annotation\ApiProperty;
use Swoolefy\Annotation\ArrayList;
use Swoolefy\Http\BaseResponse;

class CronNodeListResponse extends BaseResponse
{
    /**
     * @var array<int, CronAgentNodeRowDto>
     */
    #[ApiProperty(description: '节点列表')]
    #[ArrayList(
        itemClass: CronAgentNodeRowDto::class
    )]
    protected array $list = [];

    /**
     * @param array<int, array<string, mixed>> $list
     */
    public function __construct(array $list)
    {
        foreach ($list as $row) {
            if (is_array($row)) {
                $this->addListItem(CronAgentNodeRowDto::fromEntityRow($row));
            }
        }
    }

    /**
     * @return array<int, CronAgentNodeRowDto>
     */
    public function getList(): array
    {
        return $this->list;
    }

    /**
     * @param array<int, CronAgentNodeRowDto> $list
     */
    public function setList(array $list): static
    {
        if ($list !== [] && !($list[0] instanceof CronAgentNodeRowDto)) {
            throw new InvalidArgumentException('list items must be instances of CronAgentNodeRowDto');
        }
        $this->list = $list;

        return $this;
    }

    public function addListItem(CronAgentNodeRowDto $item): static
    {
        $this->list[] = $item;

        return $this;
    }

    public function getTotal(): int
    {
        return count($this->getList());
    }

    public function getData(): array
    {
        $rows = [];
        foreach ($this->list as $dto) {
            $rows[] = $dto->toDeepArray();
        }

        return [
            'total' => $this->getTotal(),
            'list' => $rows,
        ];
    }
}
