<?php

declare(strict_types=1);

namespace __INTERFACE_API_SUPPORT_NAMESPACE__;

class AbstractListDataDto extends ArrayDto
{
    protected int $total = 0;

    /** @var list<mixed> */
    protected array $list = [];

    public function getTotal(): int
    {
        return $this->total;
    }

    public function setTotal(int $total): static
    {
        $this->total = $total;

        return $this;
    }

    /**
     * @return list<mixed>
     */
    public function getList(): array
    {
        return $this->list;
    }

    /**
     * @param list<mixed> $list
     */
    public function setList(array $list): static
    {
        $this->list = array_values($list);
        $this->total = count($this->list);

        return $this;
    }
}
