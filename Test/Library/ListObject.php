<?php
namespace Test\Library;

use Swoolefy\Exception\SystemException;

abstract class ListObject
{
    /**
     * @var int
     */
    protected $offset;

    /**
     * @var int
     */
    protected $pageSize;

    /**
     * @var array
     */
    protected $multiBrderBy = [];

    /**
     * @var bool
     */
    protected $showAll = false;

    /**
     * @var bool
     */
    protected $isEnablePage = false;

    /**
     * @var
     */
    protected $formatter;

    /**
     * @param int $pageSize
     * @return void
     */
    public function setPageSize(int $pageSize)
    {
        $this->isEnablePage = true;
        $this->showAll = false;
        $this->pageSize = $pageSize;
    }

    /**
     * @param int $offset
     * @return void
     */
    public function setOffset(int $offset)
    {
        $this->isEnablePage = true;
        $this->showAll = false;
        $this->offset = $offset;
    }

    /**
     * @return void
     */
    public function setShowAll(bool $showAll = true)
    {
        if ($showAll === false) {
            throw new SystemException('showAll only for boolean true');
        }

        $this->showAll = true;
        $this->isEnablePage = false;
    }

    /**
     * @param $orderByField
     * @param $orderSort
     * @return void
     */
    public function setOrder(string $orderByField, string $orderSort)
    {
        if (strpos($orderSort, ';') !== false) {
            return;
        }

        switch (strtolower($orderSort)) {
            case 'asc':
                $orderSort = 'ASC';
                break;
            case 'desc';
            default:
                $orderSort = 'DESC';
                break;
        }

        $this->multiBrderBy[] = "{$orderByField} {$orderSort}";
    }

    /**
     * @return string
     */
    public function buildOrderBy()
    {
        $orderBySql = '';
        if (!empty($this->multiBrderBy)) {
            $orderBySql = 'ORDER BY '.implode(',', $this->multiBrderBy);
        }

        return $orderBySql;
    }

    /**
     * @return string
     */
    protected function buildLimit()
    {
        $limitSql = '';

        if (!empty($this->pageSize) && $this->isEnablePage) {
            if (!is_null($this->offset) && (int) $this->offset > 0 ) {
                $limitSql = " LIMIT {$this->pageSize} OFFSET {$this->offset}";
            } else {
                $limitSql = " LIMIT {$this->pageSize}";
            }
        }
        return $limitSql;
    }

    /**
     * @return mixed
     */
    public function getFormatter()
    {
        return $this->formatter;
    }

    abstract public function initFormatter();

    abstract public function buildParams(): array;

    abstract public function total(): int;

    abstract public function find();


}
