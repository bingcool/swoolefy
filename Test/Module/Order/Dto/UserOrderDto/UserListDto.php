<?php
namespace Test\Module\Order\Dto\UserOrderDto;

use Swoolefy\Core\Dto\AbstractDto;

class UserListDto extends AbstractDto
{
    public $name;

    public $order_ids;
}