<?php
namespace Test\Module\Order\Request;

use Swoolefy\Core\Dto\AbstractDto;

class LogContentDto extends  AbstractDto
{
    /**
     * @var string
     */
    public $name;

    /**
     * @var string
     */
    public $value;
}
