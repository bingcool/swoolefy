<?php
namespace Test\Module\Order\Response;

use Swoolefy\Annotation\IntToString;
use Swoolefy\Annotation\Validation\ValidationRule;
use Swoolefy\Annotation\ApiProperty;
use Swoolefy\Core\Dto\AbstractDto;

class SmallCategoryRespDto extends AbstractDto
{
    protected $smallCategoryId;
    protected $smallCategoryName;

    public function getSmallCategoryId()
    {
        return $this->smallCategoryId;
    }

    public function setSmallCategoryId($smallCategoryId)
    {
        $this->smallCategoryId = $smallCategoryId;
    }
    public function getSmallCategoryName()
    {
        return $this->smallCategoryName;
    }

    public function setSmallCategoryName($smallCategoryName)
    {
        $this->smallCategoryName = $smallCategoryName;
    }
}
