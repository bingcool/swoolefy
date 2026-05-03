<?php
namespace Test\Module\Order\Request;

use Swoolefy\Annotation\Validation\ValidationRule;
use Swoolefy\Core\Dto\AbstractDto;

class LogContentDto extends AbstractDto
{
    /**
     * @var string
     */
    #[ValidationRule(rule: "required|string", message: "日志名称不能为空")]
    public string $name;

    /**
     * @var string
     */
    #[ValidationRule(rule: "required|string", message: "日志内容不能为空")]
    public string $value;

    /**
     * @var array<int>|null omitted or null when validation allows nullable
     */
    #[ValidationRule(
        rule: "nullable|array",
        itemClass: CategoryDto::class
    )]
    public ?array $categories = null;

    public function getName()
    {
        return $this->name;
    }

    public function getValue()
    {
        return $this->value;
    }

    public function setName($name)
    {
        $this->name = $name;
    }

    public function setValue($value)
    {
        $this->value = $value;
    }

    /**
     * @return CategoryDto[]
     */
    public function getCategories(): array
    {
        return $this->categories ?? [];
    }

    public function setCategories(?array $categories)
    {
        $this->categories = $categories;
    }
}
