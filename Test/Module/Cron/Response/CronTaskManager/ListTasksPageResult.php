<?php

declare(strict_types=1);


namespace Test\Module\Cron\Response\CronTaskManager;

use Test\Module\Cron\Dto\CronTaskManager\CronTaskRowDto;
use InvalidArgumentException;
use Swoolefy\Annotation\ArrayList;
use Swoolefy\Core\Dto\ArrayDto;

class ListTasksPageResult extends ArrayDto
{
    protected int $total = 0;

    protected int $page = 1;

    protected int $pageSize = 20;

    /**
     * @var array<int, CronTaskRowDto>
     */
    #[ArrayList(
        itemClass: CronTaskRowDto::class
    )]
    protected array $list = [];

    public function setTotal(int $total): static
    {
        $this->total = $total;

        return $this;
    }

    public function getTotal(): int
    {
        return $this->total;
    }

    public function setPage(int $page): static
    {
        $this->page = max(1, $page);

        return $this;
    }

    public function getPage(): int
    {
        return $this->page;
    }

    public function setPageSize(int $pageSize): static
    {
        $this->pageSize = max(1, $pageSize);

        return $this;
    }

    public function getPageSize(): int
    {
        return $this->pageSize;
    }

    /**
     * @return array<int, CronTaskRowDto>
     */
    public function getList(): array
    {
        return $this->list;
    }

    /**
     * @param array<int, CronTaskRowDto> $list
     */
    public function setList(array $list): static
    {
        if ($list !== [] && !($list[0] instanceof CronTaskRowDto)) {
            throw new InvalidArgumentException('list items must be instances of CronTaskRowDto');
        }
        $this->list = $list;

        return $this;
    }

    public function addListItem(CronTaskRowDto $item): static
    {
        $this->list[] = $item;

        return $this;
    }
}
