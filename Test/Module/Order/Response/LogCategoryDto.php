<?php
namespace Test\Module\Order\Response;

use Swoolefy\Annotation\IntToString;
use Swoolefy\Annotation\Validation\ValidationRule;
use Swoolefy\Annotation\ApiProperty;

class LogCategoryDto extends \Swoolefy\Core\Dto\AbstractDto {
    #[ApiProperty(
        message: "分类ID"
    )]
    private int $cateId;

    #[ApiProperty(
        message: "分类名称"
    )]
    private string $cateName;

    #[ApiProperty(
        message: "分类类型"
    )]
    #[IntToString]
    private int $cateType;

    public function getCateId(): int
    {
        return $this->cateId;
    }

    public function getCateName(): string
    {
        return $this->cateName;
    }

    public function setCateId(int $cateId): void
    {
        $this->cateId = $cateId;
    }

    public function setCateName(string $cateName): void
    {
        $this->cateName = $cateName;
    }

    public function getCateType(): int
    {
        return $this->cateType;
    }

    public function setCateType(int $cateType): void
    {
        $this->cateType = $cateType;
    }


}
