<?php
namespace Test\Module\Order\Dto\UserOrderDto;

use Swoolefy\Core\Dto\AbstractDto;

class UserListDto extends AbstractDto
{
    /**
     * @var string
     */
    protected string $name;

    /**
     * @var array<int>
     */
    protected array $orderIds = [];
}