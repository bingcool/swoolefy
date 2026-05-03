<?php
namespace Test\Module\Order\Request;

use Swoolefy\Annotation\Validation\ValidationRule;
use Swoolefy\Core\Dto\AbstractDto;

class CategoryDto extends AbstractDto {

    #[ValidationRule(
        rule: 'required',
        message: 'cateId is required'
    )]
    private int $cateId;

    #[ValidationRule(
        rule: 'required',
        message: 'cateName is required'
    )]
    private string $cateName;

    #[ValidationRule(
        rule: 'required',
        message: 'subCategories is required',
        itemClass: SubCategoryDto::class,
    )]
    private array $subCategories = [];

    public function setCateId(int $cateId)
    {
        $this->cateId = $cateId;
    }

    public function getCateId()
    {
        return $this->cateId;
    }

    public function setCateName(string $cateName)
    {
        $this->cateName = $cateName;
    }

    public function getCateName()
    {
        return $this->cateName;
    }

    public function setSubCategories(array $subCategories)
    {
        $this->subCategories = $subCategories;
    }

    public function getSubCategories()
    {
        return $this->subCategories;
    }
}
