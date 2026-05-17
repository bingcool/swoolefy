<?php
namespace Test\Module\Order\Request;
use Doctrine\Common\Collections\ArrayCollection;
use OpenApi\Attributes\Property;
use Swoolefy\Annotation\ApiProperty;
use Swoolefy\Annotation\Validation\ValidationRule;
use Swoolefy\DataStruct\ArrayInteger;
use Swoolefy\Http\BasePageRequest;
use Swoolefy\Http\BaseRequest;
use Test\Common\Dto\CityDto;

class LogContentPageRequest extends BasePageRequest {
    #[ApiProperty(description: '日志名称')]
    #[ValidationRule(
        rule: 'required|string'
    )]
    protected string $logName;

    /**
     * @var ArrayInteger
     */
    #[ApiProperty(description: '用户id列表')]
    #[ValidationRule(
        rule: 'required|array',
        itemRule: 'int'
    )]
    protected ?ArrayInteger $userIds = null;

    /**
     * @var CityDto
     */
    #[ApiProperty(description: '城市信息')]
    protected CityDto $city;

    /**
     * @var array<int, CityDto>|null
     */
    #[ApiProperty(description: '城市列表')]
    #[ValidationRule(
        rule: 'nullable|array',
        itemClass: CityDto::class
    )]
    protected ?array $cityList = null;

    public function getLogName(): string
    {
        return $this->logName;
    }

    public function setLogName(string $logName): static
    {
        $this->logName = $logName;
        return $this;
    }

    public function getUserIds(): ?ArrayInteger
    {
        return $this->userIds;
    }

    public function setUserIds(?ArrayInteger $userIds): static
    {
        $this->userIds = $userIds;
        return $this;
    }

    public function getCity(): CityDto
    {
        return $this->city;
    }

    public function setCity(CityDto $city): static
    {
        $this->city = $city;
        return $this;
    }

    /**
     * @return array<int, CityDto>
     */
    public function getCityList(): array
    {
        return $this->cityList ?? [];
    }

    /**
     * @param array<int, CityDto>|null $cityList
     */
    public function setCityList(?array $cityList): static
    {
        $this->cityList = $cityList;
        return $this;
    }

    public function addCityListItem(CityDto $city): static
    {
        if ($this->cityList === null) {
            $this->cityList = [];
        }
        $this->cityList[] = $city;
        return $this;
    }
}
