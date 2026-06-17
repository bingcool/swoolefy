<?php

declare(strict_types=1);

namespace __SDK_SUPPORT_NAMESPACE__;

class SdkBasePageRequest extends SdkBaseRequest
{
    /**
     * @var int
     * #[ValidationRule(
     *   rule: 'required|int',
     *   message: [
     *       'required' => 'page is required',
     *       'int' => 'page must be int'
     *   ]
     * )]
     */
    #[ApiProperty(
        description: 'page页码'
    )]
    protected int $page = 1;

    /**
     * @var int
     * #[ValidationRule(
     *   rule: 'required|int',
     *   message: [
     *       'required' => 'pageSize is required',
     *       'int' => 'pageSize must be int'
     *   ]
     * )]
     */
    #[ApiProperty(
        description: 'pageSize每页数量'
    )]
    protected int $pageSize = 10;

    public function setPage(int $page): static
    {
        $this->page = $page;
        return $this;
    }

    public function getPage(): int
    {
        return $this->page;
    }

    public function setPageSize(int $pageSize): static
    {
        $this->pageSize = $pageSize;
        return $this;
    }

    public function getPageSize(): int
    {
        return $this->pageSize;
    }
}
