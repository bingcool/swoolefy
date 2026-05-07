<?php
namespace Test\Module\Order\Response;

use Swoolefy\Annotation\ArrayList;
use Swoolefy\Annotation\Validation\ValidationRule;
use Swoolefy\Core\Dto\AbstractDto;
use Test\Module\Order\Request\SubCategoryDto;

class CategoryRespDto extends AbstractDto {

    protected int $cateId;

    protected string $cateName;

    #[ArrayList(
        itemClass: SubCategoryRespDto::class,
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

    public function addSubCategoryDto(SubCategoryRespDto $subCategoryDto)
    {
        $this->subCategories[] = $subCategoryDto;
    }
}
