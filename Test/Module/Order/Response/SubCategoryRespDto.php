<?php
namespace Test\Module\Order\Response;

use Swoolefy\Annotation\ArrayList;
use Swoolefy\Annotation\Validation\ValidationRule;
use Swoolefy\Core\Dto\AbstractDto;

class SubCategoryRespDto extends AbstractDto
{
    protected int $subCateId;

    protected string $subCateName;

    #[ArrayList(
        itemClass: SmallCategoryRespDto::class,
    )]
    protected array $smallCategories = [];

    public function getSubCateId(): int
    {
        return $this->subCateId;
    }

    public function getSubCateName(): string
    {
        return $this->subCateName;
    }

    public function setSubCateId(int $subCateId): void
    {
        $this->subCateId = $subCateId;
    }

    public function setSubCateName(string $subCateName): void
    {
        $this->subCateName = $subCateName;
    }

    public function getSmallCategories(): array
    {
        return $this->smallCategories;
    }

    public function setSmallCategories(array $smallCategories): void
    {
        $this->smallCategories = $smallCategories;
    }

    public function addSmallCategory(SmallCategoryRespDto $smallCategory)
    {
        $this->smallCategories[] = $smallCategory;
    }
}
