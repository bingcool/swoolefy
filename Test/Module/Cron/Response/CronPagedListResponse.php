<?php

declare(strict_types=1);

namespace Test\Module\Cron\Response;

use Swoolefy\Annotation\ApiProperty;
use Swoolefy\Annotation\ArrayList;
use Swoolefy\Annotation\Validation\ValidationRule;
use Swoolefy\Http\BaseResponse;
use Test\Module\Cron\Dto\CronItemDto;

class CronPagedListResponse extends BaseResponse
{
    #[ApiProperty(description: '当前页码')]
    protected int $page;

    #[ApiProperty(description: '每页条数')]
    protected int $page_size;

    #[ApiProperty(description: '总记录数')]
    protected int $total;

    /**
     * @var array<int, array<string, mixed>>
     */
    #[ApiProperty(description: '数据列表')]
    #[ArrayList(
        itemClass: CronItemDto::class
    )]
    protected array $list;

    /**
     * @param array<int, array<string, mixed>> $list
     */
    public function __construct(int $page, int $page_size, int $total, array $list)
    {
        $this->setPage($page);
        $this->setPageSize($page_size);
        $this->setTotal($total);
        $this->setList($list);
    }

    public function getPage(): int
    {
        return $this->page;
    }

    public function setPage(int $page): self
    {
        $this->page = $page;

        return $this;
    }

    public function getPageSize(): int
    {
        return $this->page_size;
    }

    public function setPageSize(int $page_size): self
    {
        $this->page_size = $page_size;

        return $this;
    }

    public function getTotal(): int
    {
        return $this->total;
    }

    public function setTotal(int $total): self
    {
        $this->total = $total;

        return $this;
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

    public function getData(): array
    {
        return [
            'page' => $this->getPage(),
            'page_size' => $this->getPageSize(),
            'total' => $this->getTotal(),
            'list' => $this->getList(),
        ];
    }
}
