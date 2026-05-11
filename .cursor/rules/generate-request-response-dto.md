---
name: generate-request-response-dto
description: >
  Skill for generating PHP 8.4+ Request, Response, and DTO classes.
  Enforces protected properties, chainable setters/getters,
  #[ValidationRule] and #[ApiProperty] annotations, and automatic
  add*() methods for array-of-object properties.
globs: "**/*Request.php,**/*Response.php,**/*Dto.php"
version: 1.1.0
alwaysApply: false
---

# PHP Request / Response / DTO 生成规范（PHP 8.4+）

本文档定义 **Request 请求体**、**Response 响应体**、**DTO 数据传输对象** 三类 PHP 类的统一写法：封装、`declare(strict_types=1)`、可链式调用的访问器，以及与校验、API 文档注解的配合方式。

**适用范围**：本项目中 `{APP_NAME}\Module\...\Request`、`Response`、`Dto` 及同类业务代码。注解类使用：

- `Swoolefy\Annotation\Validation\ValidationRule`
- `Swoolefy\Annotation\ApiProperty`
- `Swoolefy\Annotation\StringToInt`（按需，用于将字符串安全转为整型后再校验）

---

## 1. 文件头与类结构

每个文件**必须**包含：

```php
<?php

declare(strict_types=1);

namespace ...;

use Swoolefy\Annotation\ApiProperty;
use Swoolefy\Annotation\Validation\ValidationRule;
```

- **Request**：一般 `extends Swoolefy\Http\BaseRequest`（需要 `RequestInput` 时由基类提供）。
- **Request**：一般 `extends Swoolefy\Http\BasePageRequest`（需要 `RequestInput` 时由基类提供, 如果是可分页则继承此类BasePageRequest）。
- **Response**：一般 `extends Swoolefy\Http\BaseResponse`，对外输出以 **`getData()` 返回的数组** 为准（可与框架 `ActionResultNormalizer` 配合）。
- **DTO**：一般 `extends Swoolefy\Core\Dto\AbstractDto`（若项目已有约定），或独立 POPO，但仍遵守本文 **属性与访问器** 规则。Dto类主要在业务代码的函数之间直接使用。

---

## 2. 属性可见性（强制）

| 规则 | 说明 |
|------|------|
| **仅允许 `protected` 属性** | 便于子类扩展；禁止 `public` 字段。 |
| **禁止**在类外直接读写属性 | 一律通过 getter / setter / `add*()`。 |

---

## 3. 命名约定

### 3.1 属性名与 HTTP / 校验键（Swoolefy）

框架对带 `#[ValidationRule]` 的属性做校验时，规则键来自 **反射得到的属性名**（`ReflectionProperty::getName()`），并与 `RequestInput::input()` 合并后的参数键对齐。

因此：
- 属性名 **必须统一 camelCase 驼峰命名**
- 属性名 **必须与 HTTP 参数键一致**
- 若客户端传 **`nodeId`**，属性应命名为 **`protected int $nodeId`**（或 `?int` 等），而不是 `$node_id`，否则校验字段会对不上。
- **访问器使用 camelCase**：`$nodeId` → `getNodeId()`、`setNodeId(?int $nodeId): static`。

### 3.2 一般映射关系

| 属性名                   | Getter                | Setter                                             |
|-----------------------|-----------------------|----------------------------------------------------|
| `$nodeId`             | `getNodeId()`         | `setNodeId(?int $nodeId): static`                  |
| `$pageSize`           | `getPageSize()`       | `setPageSize(?int $pageSize): static`              |
| `$logContentDtos`（数组） | `getLogContentDtos()` | `setLogContentDtos(array $logContentDtos): static` |

Setter **返回类型统一为 `static`**。

Getter **返回类型与属性类型一致**（含可空 `?`）。

---

## 4. Getter / Setter（强制）

对**每一个** `protected` 属性：

1. 提供 **getter**：`getXxx()`，返回类型 = 属性类型。
2. 提供 **setter**：`setXxx(...): static`，参数类型 = 属性类型；方法内赋值后 **`return $this;`**。

**示例：**

```php
#[ApiProperty(description: '节点ID')]
protected ?int $node_id = null;

public function getNodeId(): ?int
{
    return $this->nodeId;
}

public function setNodeId(?int $nodeId): static
{
    $this->nodeId = $nodeId;
    return $this;
}
```

> 说明：参数名用 `$nodeId` 与属性一致，团队统一风格。

---

## 5. `#[ApiProperty]`（强制）

- **每个属性**都必须带 `#[ApiProperty(description: '...')]`。
- **description**：根据属性语义写中文或英文；若同模块错误信息为中文，**描述优先中文**。
- 数组属性可在描述中加 **「集合」「列表」** 等词，例如：`日志内容列表`、`任务 ID 集合`。

```php
#[ApiProperty(description: '名称')]
#[ValidationRule(rule: 'required|string', message: 'name不能为空')]
protected string $name = '';
```

属性上注解顺序建议：**先 `ApiProperty`，后 `ValidationRule`**。

---

## 6. `#[ValidationRule]` 与数组属性

### 6.1 标量数组（如 `array<int>`、`array<string>`）

使用 `itemRule`、`itemMessage` 描述元素规则：

```php
/**
 * @var array<int>
 */
#[ApiProperty(description: '日志ID集合')]
#[ValidationRule(
    rule: 'required|array',
    message: '日志ID不能为空',
    itemRule: 'int',
    itemMessage: '日志ID必须是整数'
)]
protected array $logIds = [];
```

### 6.2 对象数组（如 `array<LogContentDto>`）

使用 `itemClass` 指向元素类的完全限定名：

```php
/**
 * @var array<int, LogContentDto>
 */
#[ApiProperty(description: '日志内容列表')]
#[ValidationRule(
    rule: 'required|array',
    message: '日志内容不能为空',
    itemClass: LogContentDto::class,
)]
protected array $logContentDtos = [];
```

**注意：数组的默认值自动赋值为[]**

### 6.3 默认强度（可按业务收紧/放宽）

| 场景 | 默认建议 |
|------|----------|
| **Request** 中的数组 | 无特殊说明时用 `required|array`；明确可选时用 `nullable|array` 或默认 `[]` 且规则写 `nullable|array`。 |
| **Response / DTO** | 多为输出模型，通常 **`array` 或 `nullable|array`**，一般不加 `required`（除非该类也参与入参校验）。 |

---

## 7. 数组元素为对象时：必须提供 `add*()`（强制）

当属性类型为 **元素为对象的数组** 时，或者属性使用了注解 `#[ArrayList]` 时，除 `get` / `set` 外，必须增加 **类型安全的追加方法**：

- 命名：`add` + **属性名的单数形式**（camelCase）。
- 签名：`(ItemType $item)`。

示例（属性 `$logContentDtos`，元素 `LogContentDto`）：

```php
public function addLogContentDto(LogContentDto $logContent): static
{
    $this->logContentDtos[] = $logContent;
    return $this;
}
```

若属性名本身已是单数但语义为列表（如 `$items`），仍提供 `addItem(ItemDto $item)`。

同时set*()方法需要判断添加的元素是否为对象，不为空时只需要判断第一个元素为对应camelCase对象时，才进行set操作，否则抛出异常InvalidArgumentException。若为空则无需判断

示例:
```php
public function setLogContents($logContents)
{
    if (isset($logContents[0]) && !$logContents[0] instanceof LogContentDto) {
        throw new InvalidArgumentException('logContentDtos must be an array, item must instanceof of LogContentDto');
    }
    $this->logContentDtos = $logContents;
    return $this;
}
```
---

## 8. `#[StringToInt]`（可选）

除非请求体特别指定某个字段需要转换为 int，否则请勿使用。

```php
#[StringToInt]
#[ApiProperty(description: "nodeId")]
#[ValidationRule(rule: 'required|int', message: "nodeId不能为空")]
protected int $nodeId;
```

具体行为以框架 `RequestValidate::applyStringToIntCoercion` 为准。

---

## 9. Response 类注意事项

- 属性仍全部为 **`protected`** + getter/setter + `ApiProperty`（若该 Response 也用于文档生成）。
- 若 `extends BaseResponse`，对外 JSON 通常走 **`getData()`**；可在子类中 **重写 `getData(): array`**，内部用 getter 组装数组，避免直接暴露 `protected $data` 的随意写入。
- Response 内数组字段同样遵守第 6、7 节；若仅用于出参、不参与校验，可省略 `ValidationRule`，但建议保留 `ApiProperty` 便于 OpenAPI。

---

## 10. 禁止项速查

- 禁止使用 **`public` / `private` 属性**。
- 禁止仅有属性而无对应 getter/setter（对象数组还必须有 `add*()`）。
- 禁止在 Request/Response/DTO 中省略 **`declare(strict_types=1)`**（新建文件强制）。
- 禁止 `mixed` 属性默认值写 `= null` 导致与 PHP 版本不兼容时：可改为 **无类型属性** `protected $foo = null`，并在 PHPDoc 中标注 `@var mixed`，setter/getter 仍显式声明能接受或返回的范围（团队可再收紧为具体联合类型）。

---

## 11. 完整示例（Request 片段）

```php
<?php

declare(strict_types=1);

namespace Test\Module\Example\Request;

use Swoolefy\Annotation\ApiProperty;
use Swoolefy\Annotation\StringToInt;
use Swoolefy\Annotation\Validation\ValidationRule;
use Swoolefy\Http\BaseRequest;
use Test\Module\Example\Dto\LogContentDto;

class LogSaveRequest extends BaseRequest
{
    /**
    * @var array<int> 
    */
    #[ApiProperty(description: '日志分组ID')]
    protected int $logCateGroupId = 0;
    
    /**
    * @var array<string> 
    */
    #[ApiProperty(description: '日志名称')]
    protected string $logName = '';
    
    /**
     * @var array<int>
     */
    #[ApiProperty(description: '日志ID集合')]
    #[ValidationRule(
        rule: 'required|array',
        message: '日志ID不能为空',
        itemRule: 'int',
        itemMessage: '日志ID必须是整数'
    )]
    protected array $logIds = [];

    /**
     * @var array<int, LogContentDto>
     */
    #[ApiProperty(description: '日志内容列表')]
    #[ValidationRule(
        rule: 'required|array',
        message: '日志内容不能为空',
        itemClass: LogContentDto::class,
    )]
    protected array $logContentDtos = [];

    /**
     * @return array<int>
     */
    public function getLogIds(): array
    {
        return $this->logIds;
    }

    /**
     * @param array<int> $logIds
     */
    public function setLogIds(array $logIds): static
    {
        $this->logIds = $logIds;
        return $this;
    }

    /**
     * @return array<int, LogContentDto>
     */
    public function getLogContentDtos(): array
    {
        return $this->logContentDtos;
    }

    /**
     * @param array<int, LogContentDto> $logContents
     */
    public function setLogContents(array $logContents): static
    {
        $this->logContents = $logContents;
        return $this;
    }

    public function addLogContent(LogContentDto $logContentDto)
    {
        $this->logContentDtos[] = $logContentDto;
        return $this;
    }
}
```

## 12. 完整示例（Response 片段, 分页日志列表）

```php
<?php

declare(strict_types=1);

namespace Test\Module\Example\Response;

use Swoolefy\Annotation\ApiProperty;
use Swoolefy\Http\BasePageResultResponse;
use Test\Module\Example\Dto\LogContentDto;

class LogContentPageResultResponse extends BasePageResultResponse
{
    
    protected LogContentPageResult $data;

    /**
     * @return void
     */
    public function __construct()
    {
        $this->data = new LogContentPageResult;
    }
}
```

```php
<?php

namespace Test\Module\Order\Response;

use Swoolefy\Annotation\ArrayList;
use Swoolefy\Core\Dto\ArrayDto;
use Test\Module\Order\Dto\LogContentDto;

class LogContentPageResult extends ArrayDto
{
    /**
     * @var int
     */
    protected int $total = 0;

    /**
     * @var array<LogContentDto>
     */
    #[ArrayList(
        itemClass:LogContentDto::class
    )]
    protected array $list = [];

    public function setTotal(int $total)
    {
        $this->total = $total;
        return $this;
    }

    public function getTotal(): int
    {
        return $this->total;
    }

    public function setList(array $list)
    {
        $this->list = $list;
        return $this;
    }

    public function getList(): array
    {
        return $this->list;
    }

    /**
     * @param LogContentDto $logContentDto
     * @return $this
     */
    public function addListItem(LogContentDto $logContentDto): static
    {
        $this->list[] = $logContentDto;
        return $this;
    }
}
```

```php
<?php
namespace Test\Module\Order\Dto;

use Swoolefy\Annotation\ApiProperty;
use Swoolefy\Core\Dto\ArrayDto;

class LogContentDto extends ArrayDto
{ 
    #[ApiProperty(
        description: '日志标题'
    )]
    protected string $title = "";
    
    #[ApiProperty(
        description: '日志内容'
    )]
    private string $content = "";
    
    public function setTitle(string $title): static
    {
        $this->title = $title;
        return $this;
    }
    
    public function getTitle(): string
    {
        return $this->title;
    }
    
    public function setContent(string $content): static
    {
        $this->content = $content;
        return $this;
    }
    
    public function getContent(): string
    {
        return $this->content;
    }
}

```

---

## 12. 生成/修改代码时的行为约定

1. **新建或修改**任一 `*Request.php`、`*Response.php`、`*Dto.php` 时，默认应用本文全部适用条款。
2. 用户只给出字段名时，根据 **Request / Response / DTO** 角色推断类型、校验强度与 `ApiProperty` 描述。
3. **对象数组** 必须生成 **`add*()`**。
4. Request / Response / DTO类的生成输出代码中 **不得出现 `public`/`private` 字段**，仅允许 **`protected`，必须设置setter和getter方法**。
5. 属性命名统一使用驼峰命名，禁止使用下划线。
6. 属性的类型是类时，必须设置默认值，并加上 `= null`。
7. 属性如果是字符串时，必须加上 `= ''`。
8. 属性如果是数组时，必须加上 `= []`。
9. 属性如果是array类型且元素为int|string时，必须加上phpdoc注释array<int|string>
---

## 修订记录

| 版本 | 说明 |
|------|------|
| 1.1.0 | 与 Swoolefy 注解、HTTP 键与属性名对齐说明、strict_types、Response/getData 说明、禁止项与示例补全 |
