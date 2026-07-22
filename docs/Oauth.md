# OAuth：第三方登录生产级技术方案

## 1. 定位

| 项 | 说明 |
|----|------|
| **问题** | 业务侧直接 new `Yurun\OAuthLogin\*\OAuth2` 分散、难配置、难测；Swoole 协程下易误用进程级单例共享可变态 |
| **目标** | 在 **bingcool/library** 落地 `Oauth`：配置化多 Provider + 统一门面 + Fake；在 **swoolefy** 通过 component 协程单例 `get('oauth')` |
| **非目标** | 不实现账号绑定/用户表；不做登录中间件；不对接微博/GitHub 等未列平台 |

**分层：**

```text
业务 Controller / Service
  → Application::getApp()->get('oauth')   // OauthManager（协程/请求级 DI）
       → provider('qq') / provider('weixin_oa')  // 必须显式指定
            ↓
       OauthFactory（按 driver 组装）
            ↓
       Yurun\OAuthLogin\{QQ|Weixin|Alipay|FeiShu|DingTalk|WeWork}\OAuth2
            或 Testing\FakeOauthClient（无外网）
```

| 优先级 | 能力 |
|--------|------|
| **P0 必须** | 配置多 Provider；统一 `getAuthUrl` / `getAccessToken` / `getUserInfo`；微信扫码/公众号/小程序分流；Fake；异常树 |
| **P1 推荐** | `raw()` 逃生舱；企业微信 Web 扫码 `getWebAuthUrl`；小程序 `getSessionKey` / `decryptData` |

---

## 2. 归属与依赖

| 层级 | 职责 |
|------|------|
| **library** | `OauthManager` / `OauthFactory` / `OauthClient` / Config / Exception / Fake |
| **swoolefy 应用** | `Config/oauth.php` + `Config/component/oauth.php` |
| **框架核心** | 仅 stub + CreateCmd 复制；不写平台协议 |

| 包 | 约束 |
|----|------|
| `yurunsoft/yurun-oauth-login` | **library require**（`^3.1`）；底层 HTTP 走 `yurunsoft/yurun-http`（传递依赖，已支持 Swoole 协程） |
| 其它平台 SDK | **不引入** |

---

## 3. 支持的 Provider（driver）

仅对接下列驱动；配置里可起任意 **provider 键名**，由 `driver` 决定实现。

| driver | Yurun 类 | 典型场景 | 授权入口 |
|--------|----------|----------|----------|
| `qq` | `Yurun\OAuthLogin\QQ\OAuth2` | QQ 网站登录 | `getAuthUrl` |
| `weixin_qr` | `Yurun\OAuthLogin\Weixin\OAuth2` | 微信 **开放平台网页扫码** | `getAuthUrl`（`snsapi_login`） |
| `weixin_oa` | 同上 | 微信 **公众号内网页授权** | 门面 `getAuthUrl` → SDK `getWeixinAuthUrl`（`snsapi_userinfo`） |
| `weixin_mini` | 同上 | 微信 **小程序** | 无跳转；`getSessionKey($jsCode)` / `decryptData` |
| `alipay` | `Yurun\OAuthLogin\Alipay\OAuth2` | 支付宝网页授权 | `getAuthUrl`；需应用私钥签名 |
| `feishu` | `Yurun\OAuthLogin\FeiShu\OAuth2` | 飞书 | `getAuthUrl` |
| `dingtalk` | `Yurun\OAuthLogin\DingTalk\OAuth2` | 钉钉 | `getAuthUrl` |
| `wework` | `Yurun\OAuthLogin\WeWork\OAuth2` | 企业微信 | `getAuthUrl`（应用内）；`getWebAuthUrl`（扫码） |
| `fake` | 自研 | 单测 / 本地无密钥 | 内存假响应，不发 HTTP |

> 微信三类共用同一 SDK 类，用 **不同 driver** 固定入口语义，避免业务误调 `getAuthUrl` vs `getWeixinAuthUrl`。

---

## 4. 配置模型

文件：`APP_PATH/Config/oauth.php`（create 自 stub 复制）。

```php
<?php

declare(strict_types=1);

return [
    // 无 default_provider：业务必须显式 provider('qq') / provider('weixin_oa') 等

    'oauth_providers' => [

        'qq' => [
            'driver' => 'qq',
            'app_id' => env('OAUTH_QQ_APP_ID', ''),
            'app_secret' => env('OAUTH_QQ_APP_SECRET', ''),
            'callback_url' => env('OAUTH_QQ_CALLBACK_URL', ''),
            // 可选
            'scope' => 'get_user_info',
            'login_agent_url' => null,
            // openid | unionid | unionid_first（映射 Yurun OpenidMode）
            'openid_mode' => 'openid',
            'display' => null, // PC / mobile
            'is_use_union_id' => false,
        ],

        'weixin_qr' => [
            'driver' => 'weixin_qr',
            'app_id' => env('OAUTH_WEIXIN_QR_APP_ID', ''),
            'app_secret' => env('OAUTH_WEIXIN_QR_APP_SECRET', ''),
            'callback_url' => env('OAUTH_WEIXIN_QR_CALLBACK_URL', ''),
            'scope' => 'snsapi_login',
            'openid_mode' => 'openid',
            'lang' => 'zh_CN',
        ],

        'weixin_oa' => [
            'driver' => 'weixin_oa',
            'app_id' => env('OAUTH_WEIXIN_OA_APP_ID', ''),
            'app_secret' => env('OAUTH_WEIXIN_OA_APP_SECRET', ''),
            'callback_url' => env('OAUTH_WEIXIN_OA_CALLBACK_URL', ''),
            'scope' => 'snsapi_userinfo',
            'openid_mode' => 'openid',
            'lang' => 'zh_CN',
        ],

        'weixin_mini' => [
            'driver' => 'weixin_mini',
            'app_id' => env('OAUTH_WEIXIN_MINI_APP_ID', ''),
            'app_secret' => env('OAUTH_WEIXIN_MINI_APP_SECRET', ''),
            // 小程序无 callback_url；openid_mode / lang 仍可用
            'openid_mode' => 'openid',
        ],

        'alipay' => [
            'driver' => 'alipay',
            'app_id' => env('OAUTH_ALIPAY_APP_ID', ''),
            // Alipay 签名用私钥；app_secret 可留空
            'app_secret' => '',
            'callback_url' => env('OAUTH_ALIPAY_CALLBACK_URL', ''),
            'scope' => 'auth_user',
            'sign_type' => 'RSA2',
            // 二选一：文件路径优先于字符串内容
            'app_private_key_file' => env('OAUTH_ALIPAY_PRIVATE_KEY_FILE', ''),
            'app_private_key' => env('OAUTH_ALIPAY_PRIVATE_KEY', ''),
            'app_auth_token' => env('OAUTH_ALIPAY_APP_AUTH_TOKEN', null),
        ],

        'feishu' => [
            'driver' => 'feishu',
            'app_id' => env('OAUTH_FEISHU_APP_ID', ''),
            'app_secret' => env('OAUTH_FEISHU_APP_SECRET', ''),
            'callback_url' => env('OAUTH_FEISHU_CALLBACK_URL', ''),
            'scope' => null,
        ],

        'dingtalk' => [
            'driver' => 'dingtalk',
            'app_id' => env('OAUTH_DINGTALK_APP_ID', ''),
            'app_secret' => env('OAUTH_DINGTALK_APP_SECRET', ''),
            'callback_url' => env('OAUTH_DINGTALK_CALLBACK_URL', ''),
            'scope' => 'openid',
        ],

        'wework' => [
            'driver' => 'wework',
            'app_id' => env('OAUTH_WEWORK_CORP_ID', ''),
            'app_secret' => env('OAUTH_WEWORK_SECRET', ''),
            'callback_url' => env('OAUTH_WEWORK_CALLBACK_URL', ''),
            'agent_id' => env('OAUTH_WEWORK_AGENT_ID', ''),
            'scope' => 'snsapi_base',
        ],

        'fake' => [
            'driver' => 'fake',
        ],
    ],
];
```

**约定：**

- 生产密钥只走环境变量；仓库内 stub 保持空串。
- `callback_url` 须与开放平台登记一致；多域名可用 `login_agent_url`（Yurun 登录代理页）。
- `OauthConfig` 支持顶层直接数组，或外层包一层 `oauth` 键。

---

## 5. Component（协程单例）

`Config/component/oauth.php`：

```php
<?php

declare(strict_types=1);

use Swoolefy\Library\Oauth\OauthManager;

return [
    'oauth' => static function (): OauthManager {
        $configFile = APP_PATH . '/Config/oauth.php';
        $config = is_file($configFile) ? include $configFile : [];

        return new OauthManager(is_array($config) ? $config : []);
    },
];
```

| 规则 | 说明 |
|------|------|
| **DI 生命周期** | swoolefy `Application::getApp()->get()` 按协程/请求解析组件；`oauth` 即该作用域内的单例 Manager |
| **禁止** | 进程级 `static OauthManager`；跨请求复用已完成 `getAccessToken` 的 Client |
| **缓存策略** | Manager **可**按 provider 名缓存 `OauthClient`（同 FileStorage）；Client 内 Yurun 实例带 `accessToken`/`openid`/`state` 可变态，**仅适合当前请求流程** |
| **推荐** | 一次登录流程内 `provider('qq')` 拿同一个 Client；新登录重新 `get()` 或 `flushClients()` |

---

## 6. 核心 API

### 6.1 OauthManager

```php
$mgr = Application::getApp()->get('oauth');

$client = $mgr->provider('weixin_oa'); // 必须显式指定渠道，无默认

$mgr->config();       // OauthConfig
$mgr->flushClients(); // 清空缓存（单测 / 罕见）
```

未传名 / 空名 / 未配置的 provider → `InvalidProviderException`。

### 6.2 OauthClient（门面）

统一方法（所有非 mini 的跳转式登录）：

| 方法 | 说明 |
|------|------|
| `driver(): string` | 配置中的 driver |
| `getAuthUrl(?$callback, ?$state, $scope): string` | 授权跳转 URL；`weixin_oa` 自动走公众号授权 URL |
| `getAccessToken($storeState, ?$code, ?$state): string` | 校验 state 后换 token |
| `getUserInfo(?$accessToken): array` | 用户资料（平台原始结构） |
| `refreshToken($refreshToken): bool` | 能刷新的平台转发；否则 false / 异常按 SDK |
| `validateAccessToken(?$token): bool` | 校验 token |
| `getState()` / `getOpenId()` / `getAccessTokenValue()` / `getResult()` | 读 SDK 侧态 |
| `raw(): object` | 返回底层 Yurun `OAuth2` 或 Fake，供平台特有能力 |

扩展方法：

| 方法 | 适用 driver | 说明 |
|------|-------------|------|
| `getWebAuthUrl(...)` | `wework` | 企业微信扫码登录页 |
| `getSessionKey(string $jsCode): string` | `weixin_mini` | `jscode2session` |
| `decryptData($encrypted, $iv, $sessionKey): array` | `weixin_mini` | 解密用户敏感数据 |

其它 driver 调用扩展方法 → `UnsupportedOauthCapabilityException`。

`weixin_mini` 调用 `getAuthUrl` / `getAccessToken` → 同样抛 `UnsupportedOauthCapabilityException`（小程序无浏览器跳转码流）。

### 6.3 标准化用户（可选，P1）

本期 **不强制** 归一化 User DTO；业务直接消费 `getUserInfo()` 原始数组。  
后续若需要，可加 `OauthUser`（openid / unionid / nickname / avatar / raw）而不改 Manager API。

---

## 7. 典型业务流程

### 7.1 QQ / 微信扫码 / 支付宝 / 飞书 / 钉钉 / 企微（应用内）

```php
/** @var \Swoolefy\Library\Oauth\OauthManager $oauth */
$oauth = Application::getApp()->get('oauth');
$client = $oauth->provider('qq');

// ① 发起
$url = $client->getAuthUrl();
$state = $client->getState();
// 将 $state 写入 Session / Cache（按用户）
return $this->redirect($url);

// ② 回调
$storeState = /* 从 Session 取出 */;
$token = $client->getAccessToken($storeState, $this->get('code'), $this->get('state'));
$user = $client->getUserInfo();
$openid = $client->getOpenId();
// 绑定本地用户…
```

### 7.2 微信公众号

```php
$client = $oauth->provider('weixin_oa');
$url = $client->getAuthUrl(); // 内部 → getWeixinAuthUrl
```

### 7.3 微信小程序

```php
$client = $oauth->provider('weixin_mini');
$sessionKey = $client->getSessionKey($jsCode);
$openid = $client->getOpenId();
// 可选解密
$profile = $client->decryptData($encryptedData, $iv, $sessionKey);
```

### 7.4 企业微信扫码

```php
$client = $oauth->provider('wework');
$url = $client->getWebAuthUrl(); // 非 getAuthUrl
```

---

## 8. 异常树

```text
OauthException (基类)
  ├─ InvalidProviderException      // 未配置 / 未知 driver
  ├─ OauthConfigException          // 缺 app_id / 支付宝缺私钥等
  ├─ UnsupportedOauthCapabilityException
  ├─ OauthStateException           // state 校验失败（包装 InvalidArgumentException）
  └─ OauthApiException             // 包装 Yurun\OAuthLogin\ApiException
```

业务建议 `catch (OauthException)`；需要错误码时读 `OauthApiException::getCode()`。

---

## 9. 协程与可变态（重要）

Yurun `Base` 实例字段：`accessToken`、`openid`、`state`、`result`、`http`。

| 做法 | 评价 |
|------|------|
| 每请求 DI `get('oauth')` → 新 Manager | ✅ 推荐 |
| Manager 内缓存 Client，仅本请求使用 | ✅ 可接受 |
| Worker 进程 `static` 缓存 Client | ❌ 协程串态 / CSRF state 污染 |
| 并发两个登录共用同一 Client | ❌ |

`yurun-http` 在 Swoole 下走协程客户端；门面不额外开连接池。

---

## 10. 与 Auth 模块关系

| 模块 | 职责 |
|------|------|
| **Oauth** | 换取第三方身份（openid / userinfo） |
| **Auth**（`auth.guard`） | 本系统登录态 JWT / Session |

典型串联：Oauth 回调成功 → 查/建本地用户 → `auth.guard` 签发本站票据。  
Oauth **不**依赖 Auth；Auth **不**内置 Oauth。

---

## 11. 测试策略（无 API Key）

| 类型 | 内容 | 是否需密钥 |
|------|------|------------|
| 单元 | `OauthConfig` 解析；未知 driver；空 provider 名 | 否 |
| 单元 | Factory 对各 driver 能构造出 Client（不发网） | 否（假 app_id） |
| 单元 | `fake`：`getAuthUrl` → `getAccessToken` → `getUserInfo` 闭环 | 否 |
| 单元 | `weixin_mini` 调 `getAuthUrl` 抛 Unsupported | 否 |
| 单元 | `weixin_oa` 门面映射（可用 Fake 或反射断言 driver） | 否 |
| 集成 | 真平台联调 | **需要**，本期不跑 |

测试落在 `swoolefy/PhpUintTest/Unit/Library/Oauth/`，与 FileStorage 一致。  
**按需求：用例写好后先不执行。**

---

## 12. 目录结构

```text
library/src/Oauth/
  Oauth.php                 # 可选静态入口（测试）
  OauthManager.php
  OauthFactory.php
  OauthClient.php           # 包装真实 SDK
  Contracts/
    OauthClientInterface.php
  Config/
    OauthConfig.php
  Support/
    OpenidModeMapper.php    # 字符串 → Yurun OpenidMode
  Exception/
    OauthException.php
    InvalidProviderException.php
    OauthConfigException.php
    UnsupportedOauthCapabilityException.php
    OauthStateException.php
    OauthApiException.php
  Testing/
    FakeOauthClient.php
  README.md

swoolefy/
  docs/Oauth.md
  src/Stubs/oauth.conf.stub.php
  src/Stubs/oauth.component.stub.php
  PhpUintTest/Unit/Library/Oauth/*.php
```

CreateCmd：新建应用时复制 conf + component（同 file_storage / auth）。

---

## 13. 实现清单与验收

| # | 项 | 验收 |
|---|----|------|
| 1 | library `require yurunsoft/yurun-oauth-login` | composer 声明 |
| 2 | Manager / Factory / Client / Fake / 异常 | 可 new、可 provider |
| 3 | 8 个真实 driver + fake | Factory match 完整 |
| 4 | swoolefy stub + CreateCmd | create 后出现配置 |
| 5 | 文档 `docs/Oauth.md` | 本文件 |
| 6 | PHPUnit 用例 | 写好不跑 |
| 7 | README 使用说明 | library 内短文档 |

---

## 14. 业务伪代码（Controller）

```php
<?php

declare(strict_types=1);

namespace App\Controller;

use Swoolefy\Core\Application;
use Swoolefy\Core\Controller\BController;
use Swoolefy\Library\Oauth\Exception\OauthException;
use Swoolefy\Library\Oauth\OauthManager;

class OauthController extends BController
{
    public function redirect()
    {
        /** @var OauthManager $oauth */
        $oauth = Application::getApp()->get('oauth');
        $client = $oauth->provider((string) $this->get('provider'));
        $url = $client->getAuthUrl();
        // TODO: 持久化 $client->getState()
        $this->redirect($url);
    }

    public function callback()
    {
        /** @var OauthManager $oauth */
        $oauth = Application::getApp()->get('oauth');
        $client = $oauth->provider((string) $this->get('provider'));
        try {
            $storeState = /* from session */;
            $client->getAccessToken($storeState, $this->get('code'), $this->get('state'));
            $user = $client->getUserInfo();
            // TODO: 绑定用户 + Auth 签发
            return $this->returnJson(0, 'ok', $user);
        } catch (OauthException $e) {
            return $this->returnJson(1, $e->getMessage());
        }
    }
}
```

---

## 15. 非目标与后续

| 不做 | 可后续 |
|------|--------|
| 微信 APP 移动应用登录 | `OauthUser` 归一化 |
| QQ 小程序 | Health Check 探测配置完整性 |
| 自动注册路由 | 统一 StateStore 接口（Redis/Session） |

---

**文档版本：** 与 library `Oauth` 首版实现同步；变更 API 时请同步改 stub 与本页。
