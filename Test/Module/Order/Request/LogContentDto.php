<?php
namespace Test\Module\Order\Request;

use Swoolefy\Annotation\Validation\ValidationRule;
use Swoolefy\Core\Dto\AbstractDto;

class LogContentDto extends  AbstractDto
{
    /**
     * @var string
     */
    #[ValidationRule(rule: "required|string", message: "日志名称不能为空")]
    public $name;

    /**
     * @var string
     */
    #[ValidationRule(rule: "required|string", message: "日志内容不能为空")]
    public $value;
}
