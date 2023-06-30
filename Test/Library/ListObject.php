<?php
namespace Test\Library;

use Common\Library\Db\Query;
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
    protected $multiOrderBy = [];

    /**
     * @var bool
     */
    protected $showAll = false;

    /**
     * @var bool
     */
    protected $isEnablePage = false;

    /**
     * @var ListItemFormatter
     */
    protected $formatter;

    /**
     * @var Query
     */
    protected $query;

    /**
     * @var bool
     */
    protected $hadBuildParams = false;

    public function __construct()
    {
        $this->query = $this->buildQuery();
        $this->formatter = $this->buildFormatter();
    }

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

        $this->multiOrderBy[$orderByField] = "{$orderSort}";
    }

    /**
     * @return string
     */
    public function buildOrderBy()
    {
        if (!empty($this->multiOrderBy)) {
            $this->query->order($this->multiOrderBy);
        }
    }

    /**
     * @return string
     */
    protected function buildLimit()
    {
        if (!empty($this->pageSize) && $this->isEnablePage) {
            $this->query->limit($this->offset, $this->pageSize);
        }
    }

    /**
     * @return mixed
     */
    public function getFormatter(): ?ListItemFormatter
    {
        return $this->formatter;
    }

    public function getQuery(): ?Query
    {
        return $this->query;
    }

    abstract protected function buildFormatter(): ?ListItemFormatter;

    abstract protected function buildQuery(): ?Query;

    abstract protected function buildParams();

    abstract public function total(): int;

    abstract public function find();


}
