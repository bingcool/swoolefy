<?php
namespace Test\Common\Dto;

use Swoolefy\Annotation\ApiProperty;
use Swoolefy\Core\Dto\AbstractDto;

class CityDto extends AbstractDto
{
    #[ApiProperty(
        description: "省份"
    )]
    protected string $province = "";

    #[ApiProperty(
        description: "城市"
    )]
    protected string $city = "";

    #[ApiProperty(
        description: "address"
    )]
    protected string $address = "";

    public function getProvince(): string
    {
        return $this->province;
    }

    public function setProvince(string $province): static
    {
        $this->province = $province;
        return $this;
    }

    public function getCity(): string
    {
        return $this->city;
    }

    public function setCity(string $city): static
    {
        $this->city = $city;
        return $this;
    }

    public function getAddress(): string
    {
        return $this->address;
    }

    public function setAddress(string $address): static
    {
        $this->address = $address;
        return $this;
    }
}
