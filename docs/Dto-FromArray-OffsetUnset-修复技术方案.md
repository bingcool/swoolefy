# Swoolefy DTO ArrayAccess / fromArray 修复技术方案

> 适用分支：`swoolefy-6.2-x`
>
> 修复对象：最近一轮 DTO `ArrayAccess / JsonSerializable / IteratorAggregate / fromArray()` 优化后的实际代码
>
> 本次原则：**不回滚 DTO 整体优化，不新增复杂机制，只修复已经确认的确定性 Bug 与核心 API 语义不一致问题。**

---

## 1. 背景

最近 DTO 优化后，`ArrayDto` / SDK DTO 统一具备：

- `ArrayAccess`
- `JsonSerializable`
- `IteratorAggregate`
- `fromArray()`
- 嵌套 DTO hydration
- `#[ArrayList]` 列表 hydration
- getter / setter 虚拟字段映射

当前整体方向没有问题，但重新审查 `InteractsWithDtoArrayAccess` 后，发现以下问题：

### P0：`offsetUnset()` 存在确定性的无限递归

当前代码：

```php
$property = $this->reflectionPropertyForDeclaredField($name);
if ($property === null || $property->isReadOnly()) {
    unset($this->{$name});
    return;
}
```

同时：

```php
public function __unset(string $name): void
{
    $this->offsetUnset($name);
}
```

当字段不存在，或者进入 readonly 分支时：

```text
offsetUnset()
    ↓
    unset($this->{$name})
    ↓
__unset()
    ↓
offsetUnset()
    ↓
    ...
```

最终形成无限递归。

这是本次必须首先修复的 P0。

---

### P1：`offsetUnset()` 没有实现真正的 PHP `unset` 语义

当前实现对于 typed property 实际上是：

```text
nullable typed property
    -> setValue(null)

non-nullable typed property
    -> 什么都不做
```

即：

```php
unset($dto['name']);
```

并不等价于：

```php
unset($dto->name);
```

正确语义应该是：

```text
initialized(value)
      ↓ unset
uninitialized
```

而不是：

```text
initialized(value)
      ↓
initialized(null)
```

---

### P1：`fromArray()` 无法发现继承链中的 private property

当前实现重新创建了一套 Reflection 查找：

```php
$ref = new \ReflectionClass($obj);

if (!$ref->hasProperty($name)) {
    continue;
}

$property = $ref->getProperty($name);
```

对于父类声明的 private property：

```php
class ParentDto extends ArrayDto
{
    private string $name = '';
}

class ChildDto extends ParentDto
{
}
```

`ReflectionClass(ChildDto::class)` 不会把 `ParentDto` private `$name` 当作当前类的 property 直接发现。

而 trait 内已经存在：

```php
reflectionPropertyForDeclaredField()
```

并且该方法就是统一的 DTO 属性解析入口，因此 `fromArray()` 应该直接复用它。

---

### P1：`offsetExists()` 与 `isset()` 的语义不完整

当前 declared property 的判断：

```php
return $property->isInitialized($this);
```

所以：

```php
private ?string $name = null;
```

即使值是 `null`，只要 property 已初始化：

```php
isset($dto['name']) === true
```

这与 PHP `isset()` 的自然语义不一致。

此外，virtual getter 当前只判断 getter 是否存在，不判断 getter 最终返回值：

```php
if ($this->dtoOffsetViaGetter($name) !== null) {
    return true;
}
```

因此 `getName()` 存在且返回 `null` 时，`isset($dto['name'])` 仍然返回 `true`。

---

### P1：property / getter / setter 优先级不统一

当前：

```text
offsetGet
    getter 优先
    property 后查

offsetSet
    setter 优先
    property 后查
```

这可能导致：

```php
$dto['name']
```

与：

```php
$dto->name
```

不一致。

例如 DTO 同时存在 property 与 `getName()`：

```php
protected string $name = 'A';

public function getName(): string
{
    return 'B';
}
```

当前：

```php
$dto['name'] === 'B';
$dto->name   === 'A';
```

因此需要统一字段解析优先级。

---

## 2. 修复目标

本次最终目标：

```text
① 消除 offsetUnset 无限递归

② offsetUnset 真正执行 property unset

③ fromArray() 支持完整继承链 property

④ offsetExists() 与 unset / null 语义一致

⑤ property / getter / setter 的读取、写入优先级统一

⑥ SDK DTO / SDK 生成模板同步

⑦ 补足对应单元测试
```

同时保持：

- 不回滚 `ArrayAccess`
- 不回滚 `JsonSerializable`
- 不回滚 `IteratorAggregate`
- 不新增 DTO shadow state
- 不新增“unset 字段集合”
- 不新增复杂字段注册机制
- 不扩展 `hydratePropertyValue()` 类型系统
- 不把 `fromArray()` 全面改造成 setter 驱动模型
- 不重构 getter / setter 虚拟字段机制

---

# 3. 核心设计原则

## 3.1 属性解析只能有一个入口

所有需要寻找 declared property 的代码统一使用：

```php
reflectionPropertyForDeclaredField($name)
```

包括：

```text
offsetGet
offsetSet
offsetExists
offsetUnset
fromArray
```

禁止 `fromArray()`、`offsetUnset()` 等再各自实现 Reflection 遍历。

这样可以避免：

```text
offsetGet 能找到
fromArray 找不到
offsetUnset 又是另一套规则
```

---

## 3.2 `unset` 与 `null` 必须保持区别

必须区分：

```text
uninitialized
```

和：

```text
initialized + null
```

例如：

```php
private ?string $name;
```

存在：

```text
状态 A：未初始化
状态 B：已初始化，值为 null
```

因此：

```php
unset($dto['name']);
```

应该进入状态 A，而不是状态 B。

---

## 3.3 未知字段不能再次触发对象级 `unset`

因为当前 trait 已经实现：

```php
public function __unset(string $name): void
{
    $this->offsetUnset($name);
}
```

所以在 `offsetUnset()` 内：

```php
unset($this->{$name});
```

是危险操作，会再次进入 `__unset()`。

对于 unknown field，本次采用：

```php
return;
```

保持 DTO 当前整体上的宽松行为。

---

# 4. P0：修复 `offsetUnset()` 无限递归

## 4.1 当前错误实现

```php
public function offsetUnset(mixed $offset): void
{
    // ...

    $property = $this->reflectionPropertyForDeclaredField($name);
    if ($property === null || $property->isReadOnly()) {
        unset($this->{$name});
        return;
    }

    // ...
}
```

配合：

```php
public function __unset(string $name): void
{
    $this->offsetUnset($name);
}
```

形成：

```text
unset($dto['unknown'])
        ↓
offsetUnset('unknown')
        ↓
property === null
        ↓
unset($this->unknown)
        ↓
__unset('unknown')
        ↓
offsetUnset('unknown')
        ↓
...
```

这是确定性 Bug。

---

## 4.2 unknown field 处理

修改为：

```php
if ($property === null) {
    return;
}
```

禁止：

```php
unset($this->{$name});
```

因此：

```php
unset($dto['unknown']);
```

最终行为：

```text
不抛异常
不创建字段
不递归
直接忽略
```

这与当前 `fromArray()` 对未知字段的宽松策略也是一致的。

---

## 4.3 readonly field 处理

readonly 不应该进入普通 unset。

统一显式抛出：

```php
if ($property->isReadOnly()) {
    throw new LogicException("只读字段不可 unset: {$name}");
}
```

从而保证：

```text
offsetSet(readonly)
    -> LogicException

offsetUnset(readonly)
    -> LogicException
```

不要使用：

```php
unset($this->{$name});
```

让 PHP 底层随机产生 `Error`。

---

# 5. P1：`offsetUnset()` 实现真正的 unset

## 5.1 为什么不能 `setValue(null)`

当前代码：

```php
$property->setValue($this, null);
```

只是：

```text
initialized(null)
```

不是：

```text
uninitialized
```

所以不应该继续使用 `setValue(null)` 模拟 `unset`。

---

## 5.2 推荐实现

`ReflectionProperty` 用于定位属性，真正的 unset 使用 PHP 原生 `unset`。

由于 property 可能是父类 private property，需要进入 property 实际声明类的 scope。

推荐增加内部 helper：

```php
private function unsetDeclaredProperty(ReflectionProperty $property): void
{
    $name = $property->getName();
    $declaringClass = $property->getDeclaringClass();

    $unsetter = \Closure::bind(
        function (object $object) use ($name): void {
            unset($object->{$name});
        },
        null,
        $declaringClass,
    );

    $unsetter($this);
}
```

然后：

```php
public function offsetUnset(mixed $offset): void
{
    if (!is_string($offset) && !is_int($offset)) {
        return;
    }

    $name = (string) $offset;
    if ($name === '') {
        return;
    }

    $property = $this->reflectionPropertyForDeclaredField($name);

    if ($property === null) {
        return;
    }

    if ($property->isReadOnly()) {
        throw new LogicException("只读字段不可 unset: {$name}");
    }

    $this->unsetDeclaredProperty($property);
}
```

### 这里的关键点

```text
ReflectionProperty
        ↓
getDeclaringClass()
        ↓
Closure::bind(... declaring class scope)
        ↓
unset($object->{$propertyName})
```

最终仍然由 PHP 原生 `unset` 完成属性生命周期变化。

---

# 6. P1：修复 `fromArray()` 继承链属性解析

## 6.1 当前实现

```php
public static function fromArray(array $data): static
{
    $obj = new static();
    $ref = new \ReflectionClass($obj);

    foreach ($data as $key => $value) {
        // ...

        if ($name === '' || !$ref->hasProperty($name)) {
            continue;
        }

        $property = $ref->getProperty($name);
        // ...
    }

    return $obj;
}
```

问题在于 `fromArray()` 没有使用 DTO 统一属性解析方法。

---

## 6.2 修复方式

修改为：

```php
public static function fromArray(array $data): static
{
    $obj = new static();

    foreach ($data as $key => $value) {
        if (!is_string($key) && !is_int($key)) {
            continue;
        }

        $name = (string) $key;
        if ($name === '') {
            continue;
        }

        $property = $obj->reflectionPropertyForDeclaredField($name);
        if ($property === null) {
            continue;
        }

        if ($property->isStatic() || $property->isReadOnly()) {
            continue;
        }

        $property->setAccessible(true);
        $property->setValue(
            $obj,
            static::hydratePropertyValue($property, $value),
        );
    }

    return $obj;
}
```

---

## 6.3 修复后的覆盖范围

支持：

```text
ChildDto
  ↓
ParentDto
  ↓
GrandParentDto
```

以及：

| 属性 | `fromArray()` |
|---|---|
| 当前类 public | 支持 |
| 当前类 protected | 支持 |
| 当前类 private | 支持 |
| 父类 public | 支持 |
| 父类 protected | 支持 |
| 父类 private | **修复后支持** |
| 多层继承 | **修复后支持** |
| static | 跳过 |
| readonly | 跳过 |
| unknown | 跳过 |

---

# 7. P1：统一 `offsetExists()` 语义

## 7.1 declared property

当前：

```php
return $property->isInitialized($this);
```

建议改成：

```php
$property->setAccessible(true);

if (!$property->isInitialized($this)) {
    return false;
}

return $property->getValue($this) !== null;
```

因此：

```text
未初始化        -> false
已初始化 + null -> false
已初始化 + value -> true
```

这与 `isset()` 的直觉语义一致。

---

## 7.2 getter virtual field

当前：

```php
$getter = $this->dtoOffsetViaGetter($name);
if ($getter !== null) {
    return true;
}
```

这里只代表：

```text
存在 getter
```

并不能证明：

```text
getter 最终返回非 null
```

建议调整为：

```php
$getter = $this->dtoOffsetViaGetter($name);

if ($getter !== null) {
    return $getter() !== null;
}
```

这样：

```php
public function getName(): ?string
{
    return null;
}
```

则：

```php
isset($dto['name']) === false;
```

### 注意

这是一次语义修复，但会使 `offsetExists()` 调用 getter。

当前 getter 设计本身应被视为 accessor，因此要求 DTO getter 尽量保持无副作用。

本次不对 getter 虚拟字段机制做更大的重构。

---

# 8. P1：统一 property / getter / setter 优先级

## 8.1 目标

对于一个真正声明的 DTO property：

```text
真实 property
    > virtual getter/setter
```

只有不存在 declared property 时，才考虑 virtual field。

这样才能尽量满足：

```text
$dto->field
≈
$dto['field']
```

---

## 8.2 `offsetGet()`

当前：

```php
$viaGetter = $this->dtoOffsetViaGetter($name);
if ($viaGetter !== null) {
    return $viaGetter();
}

$property = $this->reflectionPropertyForDeclaredField($name);
```

建议改为：

```php
$property = $this->reflectionPropertyForDeclaredField($name);

if ($property !== null) {
    $property->setAccessible(true);

    if ($property->isInitialized($this)) {
        return $property->getValue($this);
    }

    return null;
}

$viaGetter = $this->dtoOffsetViaGetter($name);
if ($viaGetter !== null) {
    return $viaGetter();
}
```

然后再保留原有 dynamic fallback：

```php
$vars = get_object_vars($this);

return $vars[$name] ?? null;
```

---

## 8.3 `offsetSet()`

建议 declared property 优先：

```php
$property = $this->reflectionPropertyForDeclaredField($name);

if ($property !== null) {
    if ($property->isReadOnly()) {
        throw new LogicException("只读字段不可写: {$name}");
    }

    $property->setAccessible(true);
    $property->setValue($this, $value);

    return;
}

if ($this->dtoTrySetter($name, $value)) {
    return;
}

throw new \InvalidArgumentException("未知字段: {$name}");
```

### 重要边界

当前仓库存在 getter / setter 虚拟字段，因此改变优先级可能影响极少量“property 与 setter 同名”的 DTO。

正式提交前必须搜索：

```bash
grep -R "function get[A-Z]" src PHPUintTest -n
```

以及：

```bash
grep -R "function set[A-Z]" src PHPUintTest -n
```

重点检查同一个 DTO 是否同时存在：

```text
property: name
getName()
setName()
```

如果不存在实际冲突，这个修改可以直接落地。

如果存在 setter 承担参数校验 / 业务归一化，则应该保留 setter 优先级，避免 ArrayAccess 绕过 setter。

因此最终代码实现时，**property/getter/setter 优先级必须以当前仓库实际冲突情况为依据，而不是机械修改。**

---

# 9. 本次不建议修改 getter / setter 虚拟字段的总体设计

当前：

```php
getXxx()
setXxx()
```

会自动映射为：

```php
$dto['xxx']
```

这个机制存在 API 边界偏宽的问题。

例如 `BaseRequest` 本身存在：

```text
getData()
getMethod()
getRequestUri()
...
```

所以：

```php
$request['method']
```

可能实际上是在调用：

```php
$request->getMethod()
```

这不完全是传统 DTO 的“字段访问”。

但这是一个独立的 API 设计问题，本次不建议为了修 `offsetUnset()` / `fromArray()` 而一起重构。

本次只保证：

```text
真实 declared property
        ↓
行为稳定

virtual getter/setter
        ↓
现有机制继续兼容
```

---

# 10. `BaseResponse::fromArray(['data' => ...])` 暂不处理

当前 `BaseResponse` 的 `data` 更接近 virtual field：

```php
setData()
getData()
```

因此：

```php
$response['data'] = $data;
```

可以走 setter。

但：

```php
BaseResponse::fromArray([
    'data' => $data,
]);
```

当前 `fromArray()` 只处理 declared property，所以 `data` 可能被忽略。

本次不建议马上把 `fromArray()` 改成“遇到 unknown property 就调用 setter”，因为这会扩大 `fromArray()` 的行为边界。

本次保持：

```text
fromArray()
    = declared property hydration

ArrayAccess
    = declared property + virtual getter/setter
```

后续如需完全统一，再单独设计。

---

# 11. `fromArray()` constructor 边界

当前：

```php
$obj = new static();
```

因此要求 DTO 可以无参构造。

如果某个 DTO 存在 required constructor 参数：

```php
public function __construct(string $id)
```

那么：

```php
SomeDto::fromArray([...]);
```

会直接失败。

这不是本次修复目标，但属于 `fromArray()` API 的隐含前提。

本次不改 API，只建议继续检查当前 DTO 是否存在 required constructor。

---

# 12. `hydratePropertyValue()` 本次保持不变

当前支持：

```text
null
具体 DTO 类型
#[ArrayList(itemClass: ...)]
```

当前不处理：

```text
ReflectionUnionType
复杂泛型
更多自动类型转换
```

原因：这些与本次已确认的 `fromArray()` property resolution 无直接关系。

保持变更边界最小。

---

# 13. SDK DTO 同步

当前 SDK DTO / Generator 有对应的独立支持代码，因此必须检查：

```text
主 DTO trait
        ↓
SDK DTO trait / stub
        ↓
SDK Support Writer
        ↓
重新生成 SDK
```

需要保证：

```text
源码 DTO 修复
        ↓
SDK generator 生成后
        ↓
不会重新出现旧实现
```

至少同步检查：

```text
offsetUnset
offsetExists
offsetGet
offsetSet
fromArray
```

以及对应测试。

---

# 14. 单元测试方案

建议扩展当前：

```text
PHPUintTest/Unit/Core/Dto/ArrayDtoArrayAccessTest.php
```

必要时拆分：

```text
PHPUintTest/Unit/Core/Dto/ArrayDtoFromArrayTest.php
PHPUintTest/Unit/Core/Dto/ArrayDtoOffsetUnsetTest.php
```

不强制新增文件，按当前测试目录结构决定。

---

## 14.1 P0：未知字段 unset 不得递归

```php
$dto = new TestDto();

unset($dto['unknown']);
```

断言：

```text
不抛异常
测试不会栈溢出
```

这是本次必须存在的回归测试。

---

## 14.2 `fromArray()`：父类 private property

```php
class ParentDto extends ArrayDto
{
    private string $parentName = '';
}

class ChildDto extends ParentDto
{
    private string $childName = '';
}
```

：

```php
$dto = ChildDto::fromArray([
    'parentName' => 'parent',
    'childName' => 'child',
]);
```

断言两个字段都正确 hydration。

---

## 14.3 `fromArray()`：多层继承

构造：

```text
GrandParentDto
       ↓
ParentDto
       ↓
ChildDto
```

每一层增加 private / protected / public 字段。

验证完整继承链均可 hydration。

---

## 14.4 `offsetUnset()`：non-nullable typed property

```php
private string $name = 'foo';
```

：

```php
unset($dto['name']);
```

验证：

```text
property 已经不再 initialized
isset($dto['name']) === false
```

---

## 14.5 `offsetUnset()`：nullable typed property

```php
private ?string $name = 'foo';
```

执行：

```php
unset($dto['name']);
```

不能仅仅变成：

```text
initialized + null
```

必须进入：

```text
uninitialized
```

---

## 14.6 `offsetUnset()`：unset 后重新 set

```php
unset($dto['name']);
$dto['name'] = 'new';
```

验证：

```text
unset
  ↓
uninitialized
  ↓
set
  ↓
initialized('new')
```

这是验证真正 `unset` 的重要测试。

---

## 14.7 inherited private property unset

```php
class ParentDto extends ArrayDto
{
    private string $name = 'foo';
}

class ChildDto extends ParentDto
{
}
```

执行：

```php
unset($dto['name']);
```

必须能够真正解除父类 private property 的 initialization。

然后：

```php
$dto['name'] = 'bar';
```

必须能够再次赋值。

---

## 14.8 readonly

```php
private readonly string $id;
```

验证：

```php
unset($dto['id']);
```

抛出：

```php
LogicException
```

不能出现当前无限递归，也不能依赖 PHP 底层随机 `Error`。

---

## 14.9 `offsetExists()` null

```php
private ?string $name = null;
```

验证：

```php
isset($dto['name']) === false
```

以及：

```php
$dto['name'] === null
```

保持读取和存在性语义一致。

---

## 14.10 getter 返回 null

```php
public function getName(): ?string
{
    return null;
}
```

验证：

```php
isset($dto['name']) === false
```

这是 `offsetExists()` getter 分支的专项回归测试。

---

## 14.11 property / getter 冲突

如果仓库存在 DTO：

```php
protected string $name = 'A';

public function getName(): string
{
    return 'B';
}
```

必须明确本次采用哪套规则，并增加测试防止以后再次改变。

推荐目标：

```text
declared property 优先
virtual getter 作为 fallback
```

但如果现有业务依赖 setter 做数据校验，则具体写入路径需要保留 setter 优先级，必须按实际 call site 决定。

---

# 15. 全仓兼容性检查

修改前搜索：

```bash
grep -R "unset(\$.*\[" src PHPUintTest -n
```

以及：

```bash
grep -R "isset(\$.*\[" src PHPUintTest -n
```

重点检查：

```text
DTO
BaseRequest
BaseResponse
Workflow DTO
Cron DTO
SDK DTO
```

重点确认是否存在依赖：

```text
unset -> null
```

的业务代码。

---

# 16. 全仓 getter / setter 冲突检查

重点找同时具有：

```text
property name
getName()
setName()
```

的 DTO。

例如：

```bash
grep -R "function get[A-Z]" src PHPUintTest -n
```

：

```bash
grep -R "function set[A-Z]" src PHPUintTest -n
```

再人工确认对应 DTO 是否存在同名 declared property。

原因：

```text
property 优先
```

虽然更符合 DTO 字段模型，但可能绕过已有 setter 的校验 / 归一化逻辑。

因此这一项必须做代码级确认后再落最终 diff。

---

# 17. 实施顺序

建议按以下顺序提交：

```text
Step 1
P0
修复 offsetUnset 无限递归
        ↓
unknown -> return
readonly -> LogicException

Step 2
P1
增加真正 declared property unset
        ↓
Closure::bind + native unset

Step 3
P1
fromArray()
        ↓
统一使用 reflectionPropertyForDeclaredField()

Step 4
P1
offsetExists()
        ↓
initialized + non-null
        ↓
getter 返回值参与判断

Step 5
P1
检查 property / getter / setter 冲突
        ↓
确定最终优先级
        ↓
必要时调整 offsetGet / offsetSet

Step 6
SDK Generator / SDK DTO 同步

Step 7
增加回归测试

Step 8
DTO 全量测试 + lint + Mago
```

---

# 18. 验收标准

## 18.1 `offsetUnset()`

必须满足：

```text
unknown field
    -> 直接返回
    -> 不递归

readonly field
    -> LogicException

mutable declared field
    -> 真正 unset
    -> property 变为 uninitialized
```

---

## 18.2 `isset()`

必须满足：

```text
uninitialized       -> false
initialized + null  -> false
initialized + value -> true
getter 返回 null    -> false
getter 返回 value   -> true
```

---

## 18.3 `fromArray()`

必须支持：

```text
当前类 property
父类 property
父类 private property
多层继承 property
```

未知字段保持跳过。

---

## 18.4 unset 后重新赋值

```php
unset($dto['name']);
$dto['name'] = 'new';
```

必须正常工作。

---

## 18.5 无回归

现有：

```text
ArrayAccess
JsonSerializable
IteratorAggregate
nested DTO hydration
ArrayList hydration
BaseResponse data getter
```

相关测试必须继续通过。

---

# 19. 验证命令

## PHP Lint

```bash
find src PHPUintTest -name '*.php' -print0 | xargs -0 -n1 php -l
```

## PHPUnit / 项目测试

按当前仓库既有测试 runner 执行 DTO 全量测试，例如：

```bash
vendor/bin/phpunit PHPUintTest/Unit/Core/Dto
```

## Mago

按项目当前配置执行：

```bash
mago lint
```

本次重点关注真实 error，不因 warnings 阻断判断。

---

# 20. 最终建议的代码结构

最终 `InteractsWithDtoArrayAccess` 应形成以下结构：

```text
offsetExists
    ↓
normalize offset
    ↓
reflectionPropertyForDeclaredField
    ├─ declared property
    │      ↓
    │   initialized && value !== null
    │
    └─ no property
           ↓
       virtual getter


offsetGet
    ↓
property
    ↓
virtual getter
    ↓
dynamic fallback


offsetSet
    ↓
property / setter
    ↓
unknown -> InvalidArgumentException


offsetUnset
    ↓
property resolution
    ├─ unknown -> return
    ├─ readonly -> LogicException
    └─ mutable -> native unset


fromArray
    ↓
reflectionPropertyForDeclaredField
    ↓
hydratePropertyValue
    ↓
setValue
```

核心是：

```text
                一个 property resolver
                         │
        ┌────────────────┼─────────────────┐
        ↓                ↓                 ↓
   ArrayAccess       fromArray          unset
        │                │                 │
        └────────────────┴─────────────────┘
                         ↓
                  一致的 DTO 字段语义
```

---

# 21. 最终决策

本轮不回滚 DTO 优化，修复范围收敛如下：

```text
P0
────────────────────────────────
① offsetUnset unknown/readonly
   禁止 unset($this->{$name})
   消除 __unset -> offsetUnset 无限递归

P1
────────────────────────────────
② offsetUnset
   declared property 真正 native unset

③ fromArray
   统一使用 reflectionPropertyForDeclaredField()
   修复父类 private property hydration

④ offsetExists
   修复 initialized/null 语义
   修复 getter 返回 null 的 exists 语义

⑤ property / getter / setter
   代码级确认冲突后统一优先级

⑥ SDK DTO / Generator 同步

⑦ 完整回归测试
```

明确不做：

```text
- 不新增 unset 状态缓存
- 不新增 DTO 字段注册系统
- 不重构 getter/setter virtual field
- 不把 fromArray 全面改成 setter 驱动
- 不扩展 UnionType hydration
- 不扩展 copyProperty API
```

最终希望达到：

```text
DTO declared property
        ↓
统一 property resolver
        ↓
ArrayAccess / fromArray / unset
        ↓
行为一致

unset
        ↓
native unset
        ↓
uninitialized
        ↓
isset === false

unknown field
        ↓
no-op
        ↓
不会进入 __unset 无限递归
```
