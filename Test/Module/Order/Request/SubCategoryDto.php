<?php
namespace Test\Module\Order\Request;

use Swoolefy\Annotation\Validation\ValidationRule;
use Swoolefy\Core\Dto\AbstractDto;

class SubCategoryDto extends AbstractDto
{
    #[ValidationRule(
        rule: 'required',
        message: 'subCateId is required'
    )]
    private int $subCateId;

    #[ValidationRule(
        rule: 'required',
        message: 'subCateName is required'
    )]
    private string $subCateName;

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
}
