<?php
namespace Test\Module\Order\Response;

use Swoolefy\Core\Dto\AbstractDto;

class LogItemDto extends AbstractDto
{
    private $id;

    private $logName;


    /**
     * @var array<LogCategoryDto>
     */
    private array $categories = [];

    public function getId()
    {
        return $this->id;
    }

    public function getLogName()
    {
        return $this->logName;
    }

    public function setId($id)
    {
        $this->id = $id;
    }

    public function setLogName($logName)
    {
        $this->logName = $logName;
    }

    public function getCategories()
    {
        return $this->categories;
    }

    public function setCategories($categories)
    {
        $this->categories = $categories;
    }

    public function addCategory(LogCategoryDto $categoryDto)
    {
        $this->categories[] = $categoryDto;
    }
}
