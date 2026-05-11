<?php
namespace Test\Common\Dto;

use Swoolefy\Annotation\ApiProperty;
use Swoolefy\Core\Dto\ArrayDto;
class CategoryItemDto extends ArrayDto
{
    #[ApiProperty(
        description: '分类id',
    )]
    protected int $cateId;

    #[ApiProperty(
        description: '分类名称',
    )]
    protected string $cateName;
}