<?php

declare(strict_types=1);

namespace __INTERFACE_API_SUPPORT_NAMESPACE__;

class AbstractPageDataDto extends AbstractListDataDto
{
    protected int $page = 1;

    protected int $pageSize = 10;

    public function getPage(): int
    {
        return $this->page;
    }

    public function setPage(int $page): static
    {
        $this->page = $page;

        return $this;
    }

    public function getPageSize(): int
    {
        return $this->pageSize;
    }

    public function setPageSize(int $pageSize): static
    {
        $this->pageSize = $pageSize;

        return $this;
    }

    public function setTotal(int $total): static
    {
        $this->total = $total;

        return $this;
    }
}
