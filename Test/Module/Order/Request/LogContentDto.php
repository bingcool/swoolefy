<?php
namespace Test\Module\Order\Request;

use Swoolefy\Annotation\Validation\ValidationRule;
use Swoolefy\Core\Dto\AbstractDto;
use Swoolefy\DataStruct\ArrayInteger;

class LogContentDto extends AbstractDto
{
    /**
     * @var string
     */
    #[ValidationRule(rule: "required|string", message: "日志名称不能为空")]
    protected string $name;

    /**
     * @var string
     */
    #[ValidationRule(rule: "required|string", message: "日志内容不能为空")]
    protected string $value;

    /**
     * @var array<int>|null omitted or null when validation allows nullable
     */
    #[ValidationRule(
        rule: "nullable|array",
        itemClass: CategoryDto::class
    )]
    protected ?array $categories = null;

    /**
     * 用户IDs
     * @var ArrayInteger
     */
    protected ?ArrayInteger $userIds = null;

    public function getName()
    {
        return $this->name;
    }

    public function getValue()
    {
        return $this->value;
    }

    public function setName($name): static
    {
        $this->name = $name;
        return $this;
    }

    public function setValue($value): static
    {
        $this->value = $value;
        return $this;
    }

    /**
     * @return CategoryDto[]
     */
    public function getCategories(): array
    {
        return $this->categories ?? [];
    }

    public function setCategories(?array $categories): static
    {
        $this->categories = $categories;
        return $this;
    }

    public function addCategory(CategoryDto $category): static
    {
        $this->categories[] = $category;
        return $this;
    }

    public function getUserIds(): ArrayInteger
    {
        return $this->userIds ?? new ArrayInteger();
    }

    public function setUserIds(?ArrayInteger $userIds): static
    {
        $this->userIds = $userIds;
        return $this;
    }
}
