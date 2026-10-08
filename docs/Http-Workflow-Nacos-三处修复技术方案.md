# HTTP 校验顺序、Workflow 权限来源、Nacos 发现过期

> 对照当前仓库编写（PHP 8.4）。只修这三处已在源码核对过的行为，不顺手改中间件构造、Cron、Trusted Proxy。  
> 原则：鉴权通过之前不做参数校验；授权角色只来自当前有效的服务端授权数据，不来自 JWT 里的角色快照，也不来自请求体自报；发现缓存要能区分「刷新失败」和「服务真的没有实例」。  
> JWT 校验通过只说明身份凭证有效，不说明该用户此刻仍有某项操作权限。HTTP 与 Workflow 都按这条边界判断。

## 0. 定级

| 项 | 定级 | 现码 | 本方案 |
| --- | --- | --- | --- |
| 未认证请求仍跑 DTO / `*Validation` 校验 | P1 | `HttpRoute` 先校验，再跑 before 中间件 | 中间件放行后再校验 |
| `PermissionPlugin` 信任 `input.role`，且 `AuthUser.roles` 来自 JWT claim | P1 | 换读 `AuthUser.roles` 仍会信任签发时写进 token 的旧角色 | 插件通过 `roles()` 读取；第一次读取才向服务端角色源加载，忽略 token 内角色 |
| 发现空列表刷新 `lastFetchTime`，旧实例不过期 | P1 | 无 `stale_ttl`；成功空列表被当成「保留缓存」 | 成功空列表立即生效；失败在 `stale_ttl` 内用旧列表，超时清空 |

不做：中间件 DI、Trusted Proxy 接线、发现客户端重试/熔断器、把 `input.role` 或 JWT `roles` claim 继续当作授权来源的兼容开关、每个请求预加载角色、在 Worker 里按 TTL 缓存角色。

---

## 1. HTTP：参数校验移到 before 中间件之后

### 1.1 现路径

`src/Http/HttpRoute.php`

```text
dispatch()
  拒绝名单 action
  若存在 {Controller 换成 Validation}::{action}()
      → requestInput->validate(all(), rules)     // 早于任何中间件
  invoke()
      validateActionParamRulesBeforeMiddlewares() // 注释写明 before middleware
          仅 PARAM_KIND_DTO / BASE_REQUEST
          applyStringToIntCoercion + validateActionParamRules
      写入 RouteOption 限流键、db_debug             // 限流中间件要读这些键
      handleGroupRouteMiddles + handleBeforeRouteMiddles
          AuthenticateMiddleware 缺票/验票失败 → AuthException(401)
          CORS 预检 → CorsRespException，invoke 返回 false
      new Controller
      _beforeAction
      bindActionParams                            // DTO 构造已在中间件之后
      action / after
```

`validateActionParamRulesBeforeMiddlewares()` 只在 `invoke()` 第 236 行调用，方法是 `protected`。没有单测锁住「必须先校验」这个顺序。

缺口只在两处校验，不在 `bindActionParams()`。未带 Token 的请求会先跑校验规则（嵌套 DTO、缺参错误），然后才被 `AuthenticateMiddleware` 打成 401。

### 1.2 目标顺序

```text
dispatch()
  拒绝名单、ROUTE_ITEMS / DISPATCH_ROUTE
  不再调用 Validation 类

invoke()
  fileNotFound、isEnd
  仍先写限流键和 db_debug
  group 中间件 + before 中间件
      CorsRespException → return false，不校验
      AuthException 及其他异常 → 原样抛出，不校验
      app->isEnd() → return false，不校验
  *Validation::{action}()
  原 validateActionParamRulesBeforeMiddlewares() 的规则校验
  new Controller → _beforeAction → bindActionParams → action → after
```

限流键必须留在中间件之前。`ApiUserRateLimiterMiddleware` 读的是 `RouteOption` 写到 request 上的限额，不能跟校验一起后移。

校验抛出的异常类型、HTTP 状态码、错误体保持现状。变的只是：中间件已短路或已抛错时，这些校验不再执行。

### 1.3 改动

| 文件 | 改动 |
| --- | --- |
| `src/Http/HttpRoute.php` | `dispatch()` 删掉第 196–201 行的 Validation 类调用，改到 `invoke()` 中间件成功之后。把 `validateActionParamRulesBeforeMiddlewares()` 的调用移到同一位置，方法改名为 `validateActionParamRules()`，注释改为「仅在 before 中间件未短路时执行」 |

`protected` 方法无外部调用方，不保留旧方法名。

`*Validation` 与注解校验的相对顺序保持「先类方法，再注解」，只是整段挪到中间件之后，避免同一请求里规则先后变化。

### 1.4 测试

新增 `PHPUintTest/Unit/Http/HttpRouteMiddlewareValidationOrderTest.php`（不启 Swoole 端口）：

- 子类化 `HttpRoute`，或用可替换的请求/响应替身，记录「中间件 handle」和「validate」的调用序。
- before 中间件抛 `AuthException`：校验次数为 0。
- CORS 路径抛 `CorsRespException`：校验次数为 0，`invoke()` 返回 false。
- 中间件返回 true：校验发生在 `new` 控制器之前，合法用户的校验失败仍是原来的 422/400。
- 断言限流键在中间件 `handle()` 被调用时已经写在 request 上。

---

## 2. 权限：凭证与角色分开

统一原则：JWT 有效只表示身份凭证通过验证。`AuthUser.roles` 表示这一次请求采用的服务端授权结果。HTTP 中间件、Workflow `PermissionPlugin`、`WorkflowHitlAuth` 都只消费后者，谁也不再从 token 或请求体里取角色。

只把 `PermissionPlugin` 的 `input.role` 改成读 `AuthUser.roles` 不够。当前 `AuthUser.roles` 就是 JWT 角色快照。

### 2.1 现路径

`JwtAuthGuard::authenticate()` 验签、过期、`nbf` / `iss` / `aud` 之后，把 `roles_claim`（默认 `roles`，数组或逗号串）原样放进 `AuthUser.roles`。`generateToken()` 把 `AuthUser.roles` 写回同一个 claim。

`AuthenticateMiddleware::authenticate()` 在 Guard 返回用户后直接 `FrameworkContext::setUser($user)`，中间没有第二次角色加载。`src/Stubs/WebsocketAuthCallback.stub.php` 同样是 Guard 结果直接 `setUser`。

`PermissionPlugin` 另有一条客户端入口：`onRunStart` 读 `input[roleKey]`（默认 `role`）。`effectiveRoles` 为空则放行，否则该字符串必须落在「构造参数 ∪ `metadata.allowedRoles`」里。

`WorkflowHitlAuth` 不读 input，但类注释写明角色来自 JWT claim，判断的仍是 `AuthUser.roles`。因此 HITL 与插件会一起继承 token 里的旧角色。

`PHPUintTest/Unit/Support/Workflow/WorkflowPhase4Test` 用 input 过门禁：`role=guest` 拒绝，`role=operator` / `admin` 放行。这是现行为，修完必须改测试。

### 2.2 角色从哪里来，并且懒加载

新增 `Swoolefy\Support\Auth\RoleResolverInterface`：

```php
interface RoleResolverInterface
{
    /**
     * 返回该用户当前有效角色。空数组表示「当前没有任何角色」，不是失败。
     *
     * @return list<string>
     */
    public function currentRoles(string $userId): array;
}
```

组件名 `auth.role_resolver`。框架不内置用户表查询。应用注册自己的实现（数据库、权限服务、测试假对象）。

验票成功不调用它。只有代码真正读取角色时才调用。不需要角色的接口只验证 JWT，不访问角色源。

空数组不能同时表示「还没加载」和「当前没有角色」。Context 快照里的 `roles` 用两种值：

| 快照里的 `roles` | 含义 |
| --- | --- |
| `null` | 本请求尚未加载 |
| `list<string>` | 已经加载，空数组是合法结果 |

`AuthUser` 增加 `rolesResolved`。Guard 和中间件写入的身份都是 `rolesResolved = false`，属性里的 `$roles` 不作为授权依据。`toArray()` / `fromArray()` 把未加载写成 `null`，这样 `goApp` 拷贝的仍是数组，不会把对象带进子协程。

读取入口只有 `AuthUser::roles()`。`hasRole()` / `isAdmin()` 改为调用它，禁止授权代码直接读 `$user->roles` 属性（未加载时属性是空数组，会被误当成「没有角色」）。

```text
AuthUser::roles(): list<string>
  当前协程快照里 roles 已是数组 → 直接返回，不再调用 resolver
  没有 userId → AuthException
  容器中没有 auth.role_resolver → AuthException(500)，禁止改读 JWT
  list = resolver.currentRoles(userId)
  结果不是 list<string> → AuthException(500)
  把 list 写回本请求 Context 快照（rolesResolved = true）
  返回 list
```

`AuthUser` 是 readonly，方法里不能改自己的属性。加载结果写回 `FrameworkContext` 的 array 快照。同一次请求里第二次 `roles()` 读快照。子协程如果拷贝发生在加载之后，用的是已加载列表；如果拷贝发生在加载之前，子协程自己的第一次 `roles()` 再查一次。都不读 JWT。

不在 Worker 静态属性里缓存。请求结束快照清掉，下一次请求仍是 `null`，第一次读取再拿当前角色。角色撤销后，下一次会读角色的请求就能看到新值，不用等 token 过期。

一次请求内以第一次成功加载的结果为准，后面的 `hasRole()` 不再打角色源，避免同一次调用前后半段角色不一致。

应用若在自己的 `RoleResolver` 里做 TTL 缓存，撤销会延迟到缓存失效。框架不提供这层缓存。测试用的 resolver 无缓存。

`JwtAuthGuard` 继续只做凭证：

- `authenticate()` 仍解析 `userId`（`id_claim` / `sub`）和可选 `tenantId`。
- 不再读取 `roles_claim`。返回的用户 `rolesResolved = false`。
- `generateToken()` 不再写入 `roles` claim。旧 token 里残留的 `roles` 可以验签，读角色时忽略。

不需要 `establish()` 在验票时替换角色。中间件只负责把「未加载角色的身份」放进 Context。

### 2.3 `AuthenticateMiddleware`

验票成功且 `$user !== null` 之后，仍然 `setUser`，但不加载角色：

```text
$credential = $guard->authenticate(['token' => $token])
FrameworkContext::setUser($credential)    // 快照 roles = null
tenant_id 仍按 $credential->tenantId 写入
```

这条路径不调用 `currentRoles()`。缺 token、验签失败、过期仍是现在的 401。未注册 resolver 时，不读角色的接口保持成功；第一次 `roles()` 才 500。

`OptionalAuthenticateMiddleware` 同样不预加载。无 token 的匿名放行不调用 resolver。WebSocket 握手的 `setUser` 也只写入未加载身份，不在握手时查角色。

### 2.4 `PermissionPlugin`

`effectiveRoles` 的并集算法不变。空集合仍表示这个工作流不限制角色，并且不调用 `roles()`。

非空时：

1. `FrameworkContext::user()` 为 null → 拒绝。不把 input 里的角色或租户写进异常文案。不调用 resolver。
2. `user->roles()` 与 `effectiveRoles` 有交集 → 放行。用 `hasRole()`，这里才第一次加载。
3. 无交集（含 resolver 返回的空数组）→ 拒绝。
4. `input.role` / `input.roles` 只作为业务字段，不参与判断。异常文案中的租户只取 `AuthUser::$tenantId`。

构造参数 `$roleKey` 保留，避免现有 `new PermissionPlugin($roles)` 要改签名，但不再读取 `input[$roleKey]`。类注释删掉「典型 input 带 role」的授权说明。

`WorkflowHitlAuth` 里 `foreach ($user->roles as $role)` 改为 `foreach ($user->roles() as $role)`。注释从「JWT claim」改为「第一次 `roles()` 向 `auth.role_resolver` 读取的当前服务端角色」。不需要角色交集的分支（例如 auth 关闭、API Key 已放行）不调用 `roles()`。

不读 `x-user-id`。只透传头、没有本地 `setUser` 时，`user()` 仍为 null，受控工作流拒绝。

### 2.5 改动

| 文件 | 改动 |
| --- | --- |
| `src/Support/Auth/RoleResolverInterface.php` | 新增 |
| `src/Support/Auth/AuthUser.php` | `rolesResolved`；`roles()` 懒加载并回写 Context；`hasRole()` / `isAdmin()` 走 `roles()`；快照用 `null` 表示未加载 |
| `src/Support/FrameworkContext.php` | `setUser` 允许未加载；提供回写已加载快照的方法，供 `roles()` 使用 |
| `src/Support/Auth/JwtAuthGuard.php` | 解析与签发都不再处理 `roles_claim`；验票结果 `rolesResolved = false` |
| `src/Http/Middleware/AuthenticateMiddleware.php` | 只 `setUser`，不调用 `currentRoles()` |
| `src/Stubs/WebsocketAuthCallback.stub.php` | 同样只写入未加载身份 |
| `src/Support/Workflow/Plugin/Builtin/PermissionPlugin.php` | 需要判定时调用 `roles()` / `hasRole()` |
| `src/Support/Workflow/WorkflowHitlAuth.php` | 读角色改为 `roles()`，并改注释 |
| `src/Stubs/auth.conf.stub.php`、`docs/Auth.md` | 写明凭证与角色的边界、懒加载，以及禁止直接读 `$user->roles`；`roles_claim` 标为忽略 |
| 测试用 `auth` 组件 | 注册无缓存的 `auth.role_resolver` |

`WorkflowBootstrap` 的环境变量开关和默认 `allowedRoles` 不改。

### 2.6 测试

`WorkflowPhase4Test`：放行改为先 `setUser`。单测若直接构造已解析的 `AuthUser`（`rolesResolved = true`），不得因此去调 resolver。另有一条走懒加载：快照为未加载，假 resolver 返回 operator，input 自报 admin，断言按 resolver 放行。拒绝覆盖：无用户但 input 自报 admin（resolver 调用次数为 0）；已加载角色不在集合内。`finally` 调用 `clearUser()`。

新增 `PHPUintTest/Unit/Support/Auth/AuthIdentityRoleSourceTest.php`：

- 验票并 `setUser` 之后、任何人调用 `roles()` 之前：`currentRoles()` 次数为 0。token 里即使有 `roles=admin` 也不出现在授权结果里。
- 第一次 `roles()`：resolver 返回 `['operator']`，结果是 operator，不是 token 里的 admin。同一请求第二次 `roles()` / `hasRole()`：resolver 次数仍为 1。
- 新请求（新的 Context，同一未过期 token）：resolver 改为 `[]` 或 `['guest']`。第一次 `roles()` 必须再次调用 resolver，admin 工作流拒绝。
- 未注册 `auth.role_resolver`：只验票、不读角色的路径成功。`PermissionPlugin` 在 `effectiveRoles` 非空时调用 `roles()` 得到 500，不会变成 token 里的 admin。`effectiveRoles` 为空时仍不调用 resolver。

### 2.7 行为变化

`engine->start($compiled, ['role' => 'operator'])` 不再授权。`generateToken(new AuthUser(..., roles: ['admin']))` 发出的 token 也不能再把 admin 带进后续请求。真正做角色判断的入口要在 `auth.role_resolver` 里按 `userId` 提供当前角色；普通只验登录的接口不会调用它。

这是有意的不兼容。不提供「仍信任 input」或「仍信任 JWT roles」的开关。

---

## 3. Nacos：成功空列表立即生效，失败缓存有截止时间

### 3.1 现路径

`DiscoveryConfig` 只有 `cacheTtl`（默认 60 秒，`cache_ttl` / `NACOS_DISCOVERY_CACHE_TTL`）。没有 stale 配置。

`DiscoveryClient::fetchInstances()`：

```text
driver->getInstances() 抛异常
    → 不更新 lastFetchTime，异常冒泡
    → getInstances() 在 TTL 到期后的那一次调用直接失败，调用方拿不到旧列表

返回非空
    → 替换 instances，lastFetchTime = now

返回空，且本地 instances 已空
    → lastFetchTime = now

返回空，且本地仍有实例
    → 打 warning，保留旧实例，lastFetchTime = now
```

`NacosDiscoveryDriver::getInstances()` 对注册中心的空 hosts 返回 `[]`，不把网络失败收成空数组。因此「空数组」是一次成功响应，「抛异常」才是刷新失败。现码把成功空数组合并进了失败保留逻辑，并把 `lastFetchTime` 刷新掉，服务缩到 0 之后旧实例一直可被 `choose()` 选中。

### 3.2 两只钟

| 字段 | 更新时机 | 作用 |
| --- | --- | --- |
| `lastFetchAt` | 每次拉取结束都更新：成功非空、成功空、捕获到的异常 | 代替现在的 `lastFetchTime`，`cacheTtl` 内不重复打 Nacos |
| `lastSuccessAt` | 仅 driver 正常返回时更新，空列表也算成功 | 失败缓存是否还允许使用，只看这只钟 |

不要在「保留旧实例」的分支上刷新成功时间。

### 3.3 目标语义

`staleTtl` 秒，配置键 `nacos.discovery_service_client.stale_ttl`，环境变量 `NACOS_DISCOVERY_STALE_TTL`。未配置时默认 `max(cacheTtl * 3, 180)`。显式 `0` 表示失败后立即不再使用旧实例。

```text
刷新成功且列表非空
    instances = 新列表
    lastSuccessAt = now
    lastFetchAt = now

刷新成功且列表为空
    instances = []          // 立即生效，不再保留旧实例
    lastSuccessAt = now
    lastFetchAt = now
    日志：nacos discovery empty service=...

刷新抛异常，且 lastSuccessAt 起未超过 staleTtl，且 instances 非空
    不替换 instances
    不更新 lastSuccessAt
    更新 lastFetchAt        // cacheTtl 内不要每次请求都再打一次
    日志：nacos discovery refresh failed, serve stale service=... age=...
    getInstances() 返回旧列表，不把异常抛给 choose()

刷新抛异常，且已超过 staleTtl，或本地本来就没有实例，或 staleTtl = 0
    instances = []
    更新 lastFetchAt，不更新 lastSuccessAt
    日志：nacos discovery stale expired service=...
    getInstances() 返回空数组
    choose() 得到 null（fail closed），不把已过期实例再选出
```

`cacheTtl <= 0` 仍表示每次 `getInstances()` 都拉取；失败时同样受 `staleTtl` 约束。

`choose()` 现有「第一次选空再 `refresh()` 一次」保持不变。成功空列表和过期清空之后，第二次选择仍是 null，不会自旋。

日志走现有 `NacosLogger`，用上面三条固定英文前缀区分，不新建指标系统，不把实例 IP 列表或 Nacos 凭证打进日志。

### 3.4 改动

| 文件 | 改动 |
| --- | --- |
| `src/Support/Nacos/NacosConst.php` | 增加 `ENV_DISCOVERY_STALE_TTL` |
| `src/Support/Nacos/Discovery/DiscoveryConfig.php` | 增加 `staleTtl`，按 3.3 的默认值解析；负数按 0 |
| `src/Support/Nacos/Discovery/DiscoveryClient.php` | `fetchInstances()` 按 3.3 分支；`lastFetchTime` 改名为 `lastFetchAt`，新增 `lastSuccessAt`。异常在客户端内消化，只有「无可用缓存且本次失败」才向上表现为空列表 |
| `src/Stubs/application.stub.yaml` | `discovery_service_client` 增加 `stale_ttl` 注释与示例值 |
| `src/Support/Nacos/README.md` | 配置表加一行。说明 `cache_ttl` 是刷新间隔，`stale_ttl` 是失败后还能信旧列表多久；成功空列表不受 `stale_ttl` 保护 |

`Test/application.yaml` 可写显式 `stale_ttl`，不作为行为开关的唯一来源；单测用构造函数注入 `DiscoveryConfig`，不依赖这份 YAML。

### 3.5 测试

新增 `PHPUintTest/Unit/Support/Nacos/DiscoveryClientStaleTest.php`，driver 用假实现：

- 先给出 `[instanceA]`，再返回 `[]`：`getInstances()` 为空，`choose()` 为 null。
- 先给出 `[instanceA]`，随后 driver 抛异常，时间未过 `staleTtl`：仍返回 A，且 `cacheTtl` 窗口内第二次 `getInstances(false)` 不再调用 driver。
- 同一失败推过 `staleTtl`：实例被清空，`choose()` 为 null。时间用构造注入的时钟闭包，不 `sleep`。
- `staleTtl = 0`：第一次失败即清空。
- 本地无缓存时 driver 抛异常：返回空，不抛到测试外（与「过期后空列表」一致），日志路径可不断言具体 logger 实现，只断言返回值。

---

## 4. 实施顺序与验收

1. `RoleResolverInterface` + `AuthUser::roles()` 懒加载，`JwtAuthGuard` 停止读写角色 claim。中间件和 WebSocket 握手只 `setUser` 未加载身份。
2. `PermissionPlugin` / `WorkflowHitlAuth` 在需要判定时调用 `roles()`，并改 `WorkflowPhase4Test`。
3. HTTP 校验顺序（`HttpRoute` + 顺序单测）。角色来源不依赖校验顺序，但未认证请求仍不应先跑校验。
4. Nacos `stale_ttl` + `DiscoveryClientStaleTest`，并补 README / stub。

验收：

- 未认证请求的校验函数不被调用；认证通过后的缺参响应与现在一致。
- 只验票、不读角色的请求不会调用 `currentRoles()`。
- token 内 `roles=admin` 且 resolver 返回非 admin 时，第一次 `roles()` 按 resolver 判断。同一 token 在角色被撤销后的下一次请求，再次读取必须看到新角色并拒绝原先的 admin 操作。
- input 自报 `admin` 不能代替 `roles()`。
- 未注册 `auth.role_resolver` 时，不读角色的请求仍然成功；第一次 `roles()` 失败（500），不会悄悄采用 JWT 里的角色。
- Nacos 成功返回空列表后不再选出旧实例；刷新异常在 `stale_ttl` 内仍可选旧实例，超时后 `choose()` 为 null。
