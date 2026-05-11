<?php
namespace Test\Common\Dto;

use Swoolefy\Annotation\ApiProperty;
use Swoolefy\Core\Dto\AbstractDto;

class CityDto extends AbstractDto
{
    #[ApiProperty(
        description: "省份"
    )]
    protected string $area = "";

    #[ApiProperty(
        description: "城市"
    )]
    protected string $city = "";

    #[ApiProperty(
        description: "address"
    )]
    protected string $address = "";
}
