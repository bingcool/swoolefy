<?php
namespace Test\Module\Order\Response;

use Swoolefy\Annotation\ArrayList;
use Swoolefy\Annotation\Validation\ValidationRule;
use Swoolefy\Core\Dto\AbstractDto;
use Test\Module\Order\Request\CategoryDto;

class LogContentRespDto extends AbstractDto
{
    /**
     * @var string
     */
    public string $name;

    /**
     * @var string
     */
    public string $value;

    /**
     * @var array<int>|null omitted or null when validation allows nullable
     */
    #[ArrayList(
        itemClass: CategoryRespDto::class
    )]
    public ?array $categories = null;

    public function getName()
    {
        return $this->name;
    }

    public function getValue()
    {
        return $this->value;
    }

    public function setName($name)
    {
        $this->name = $name;
    }

    public function setValue($value)
    {
        $this->value = $value;
    }

    /**
     * @return CategoryRespDto[]
     */
    public function getCategories(): array
    {
        return $this->categories ?? [];
    }

    public function setCategories(?array $categories)
    {
        $this->categories = $categories;
    }

    public function addCategoryRespDto(CategoryRespDto $categoryDto)
    {
        return $this->categories[] = $categoryDto;
    }
}
