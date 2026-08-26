<?php

declare(strict_types=1);

namespace Test\Module\Cron\Response\CronTaskManager;

use Test\Module\Cron\Dto\CronTaskManager\CronAgentNodeGroupRowDto;
use InvalidArgumentException;
use Swoolefy\Annotation\ApiProperty;
use Swoolefy\Annotation\ArrayList;
use Swoolefy\Http\BaseResponse;

class CronNodeGroupListResponse extends BaseResponse
{
    /**
     * @var array<int, CronAgentNodeGroupRowDto>
     */
    #[ApiProperty(description: '节点分组列表')]
    #[ArrayList(
        itemClass: CronAgentNodeGroupRowDto::class
    )]
    protected array $list = [];

    /**
     * @param array<int, array<string, mixed>> $list
     */
    public function __construct(array $list)
    {
        foreach ($list as $row) {
            if (is_array($row)) {
                $this->addListItem(CronAgentNodeGroupRowDto::fromEntityRow($row));
            }
        }
    }

    /**
     * @return array<int, CronAgentNodeGroupRowDto>
     */
    public function getList(): array
    {
        return $this->list;
    }

    /**
     * @param array<int, CronAgentNodeGroupRowDto> $list
     */
    public function setList(array $list): static
    {
        if ($list !== [] && !($list[0] instanceof CronAgentNodeGroupRowDto)) {
            throw new InvalidArgumentException('list items must be instances of CronAgentNodeGroupRowDto');
        }
        $this->list = $list;

        return $this;
    }

    public function addListItem(CronAgentNodeGroupRowDto $item): static
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
