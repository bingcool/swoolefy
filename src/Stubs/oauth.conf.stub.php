<?php

declare(strict_types=1);

/**
 * Oauth 配置模版（create 时复制为 Config/oauth.php）
 *
 * 用法：
 * - DI：Application::getApp()->get('oauth')->provider('weixin_oa')  // 必须显式指定渠道
 * - 直接：new OauthManager(include APP_PATH . '/Config/oauth.php')
 *
 * 说明：
 * - 无 default_provider：第三方登录必须业务侧显式传入 provider 键名，避免误用默认渠道
 * - oauth_providers：可配置多个；「键名」供 provider('键名') 引用，「driver」决定底层实现
 * - 键名可与 driver 不同（例如 'wx_mp' => ['driver' => 'weixin_oa']），便于多套应用并存
 * - 密钥优先读环境变量；生产勿把明文 app_secret / 私钥提交进仓库
 * - Manager 为协程/请求级 DI 单例；Client 含 accessToken/state 可变态，勿进程级 static 缓存
 * - 跳转式登录：getAuthUrl →（用户授权）→ getAccessToken($storeState, $code, $state) → getUserInfo
 * - 微信小程序：无浏览器跳转，用 getSessionKey / decryptData
 * - 企业微信扫码：用 getWebAuthUrl（不是 getAuthUrl）
 *
 * @see \Swoolefy\Library\Oauth\OauthManager
 * @see \Swoolefy\Library\Oauth\OauthFactory
 * @see \Swoolefy\Library\Oauth\OauthClient
 * @see docs/Oauth.md
 * @see Config/component/oauth.php
 */

return [

    'oauth_providers' => [

        // ---------------------------------------------------------------------
        // qq：QQ 互联网站登录
        // - 开放平台：https://connect.qq.com/
        // - 流程：getAuthUrl → 回调 code → getAccessToken → getUserInfo
        // - app_id / app_secret 对应 QQ 应用的 APP ID / APP Key
        // - callback_url 须与开放平台登记的回调地址一致（含协议与路径）
        // ---------------------------------------------------------------------
        'qq' => [
            'driver' => 'qq',
            'app_id' => env('OAUTH_QQ_APP_ID', ''),
            'app_secret' => env('OAUTH_QQ_APP_SECRET', ''),
            // 授权成功后跳回的业务回调 URL
            'callback_url' => env('OAUTH_QQ_CALLBACK_URL', ''),
            // 授权权限；常用 get_user_info；多权限以逗号分隔（以 QQ 开放平台文档为准）
            'scope' => 'get_user_info',
            // 登录代理页 URL：开放平台只允许一个回调域名时，可先跳自有代理再转真实 callback；不需要则 null
            'login_agent_url' => null,
            // openid 取值策略：openid | unionid | unionid_first（映射 Yurun OpenidMode）
            'openid_mode' => 'openid',
            // 授权页样式：null=PC；传 'mobile' 为移动端样式
            'display' => null,
            // 是否走 unionid 相关能力（需在开放平台开通，且业务侧真有跨应用打通需求）
            'is_use_union_id' => false,
        ],

        // ---------------------------------------------------------------------
        // weixin_qr：微信「开放平台」网站应用 — PC 网页扫码登录
        // - 入口：OauthClient::getAuthUrl → SDK getAuthUrl（qrconnect）
        // - 默认 scope=snsapi_login；勿与公众号 snsapi_userinfo 混用
        // - app_id / app_secret 来自微信开放平台「网站应用」，不是公众号 AppId
        // ---------------------------------------------------------------------
        'weixin_qr' => [
            'driver' => 'weixin_qr',
            'app_id' => env('OAUTH_WEIXIN_QR_APP_ID', ''),
            'app_secret' => env('OAUTH_WEIXIN_QR_APP_SECRET', ''),
            'callback_url' => env('OAUTH_WEIXIN_QR_CALLBACK_URL', ''),
            'scope' => 'snsapi_login',
            // openid | unionid | unionid_first（开放平台绑定同一开放平台账号时可取 unionid）
            'openid_mode' => 'openid',
            // getUserInfo 返回语言：zh_CN / zh_TW / en
            'lang' => 'zh_CN',
        ],

        // ---------------------------------------------------------------------
        // weixin_oa：微信「公众号」内网页授权（用户在微信内打开 H5）
        // - 入口：OauthClient::getAuthUrl → 内部自动走 getWeixinAuthUrl（oauth2/authorize）
        // - 默认 scope=snsapi_userinfo（可取头像昵称）；静默授权可用 snsapi_base
        // - app_id / app_secret 来自公众号后台；callback 域名须在公众号「网页授权域名」白名单
        // - 勿与 weixin_qr 共用同一套开放平台网站应用凭证
        // ---------------------------------------------------------------------
        'weixin_oa' => [
            'driver' => 'weixin_oa',
            'app_id' => env('OAUTH_WEIXIN_OA_APP_ID', ''),
            'app_secret' => env('OAUTH_WEIXIN_OA_APP_SECRET', ''),
            'callback_url' => env('OAUTH_WEIXIN_OA_CALLBACK_URL', ''),
            'scope' => 'snsapi_userinfo',
            'openid_mode' => 'openid',
            'lang' => 'zh_CN',
        ],

        // ---------------------------------------------------------------------
        // weixin_mini：微信小程序登录（无浏览器跳转码流）
        // - 不需要 callback_url；调用 getAuthUrl / getAccessToken 会抛 Unsupported
        // - 客户端 wx.login 拿 js_code → 服务端 getSessionKey($jsCode)
        // - 可选 decryptData($encryptedData, $iv, $sessionKey) 解密用户敏感数据
        // - app_id / app_secret 来自小程序后台
        // ---------------------------------------------------------------------
        'weixin_mini' => [
            'driver' => 'weixin_mini',
            'app_id' => env('OAUTH_WEIXIN_MINI_APP_ID', ''),
            'app_secret' => env('OAUTH_WEIXIN_MINI_APP_SECRET', ''),
            'openid_mode' => 'openid',
        ],

        // ---------------------------------------------------------------------
        // alipay：支付宝网页授权
        // - 换票 / 拉用户需 RSA 或 RSA2 签名；app_secret 可留空
        // - 私钥二选一：app_private_key_file（PEM 文件路径，优先）或 app_private_key（PEM 字符串）
        // - sign_type 须与开放平台应用「签名类型」一致，推荐 RSA2
        // - app_auth_token：第三方应用代商户调用时可选；自用应用一般 null
        // - scope 常用 auth_user；静默可用 auth_base
        // ---------------------------------------------------------------------
        'alipay' => [
            'driver' => 'alipay',
            'app_id' => env('OAUTH_ALIPAY_APP_ID', ''),
            // 支付宝 OAuth 主要靠应用私钥签名，secret 通常不用
            'app_secret' => '',
            'callback_url' => env('OAUTH_ALIPAY_CALLBACK_URL', ''),
            'scope' => 'auth_user',
            'sign_type' => 'RSA2',
            // 推荐：私钥放文件，路径用环境变量；文件优先于下方字符串
            'app_private_key_file' => env('OAUTH_ALIPAY_PRIVATE_KEY_FILE', ''),
            // 或直接放 PEM 内容（含 -----BEGIN ...-----）；生产更建议用文件 + 权限控制
            'app_private_key' => env('OAUTH_ALIPAY_PRIVATE_KEY', ''),
            'app_auth_token' => env('OAUTH_ALIPAY_APP_AUTH_TOKEN', null),
        ],

        // ---------------------------------------------------------------------
        // feishu：飞书网页登录
        // - app_id / app_secret 来自飞书开放平台应用凭证
        // - SDK 换票前会先取 app_access_token，再换用户 access_token
        // - scope 按飞书应用权限配置；无额外 scope 可保持 null
        // ---------------------------------------------------------------------
        'feishu' => [
            'driver' => 'feishu',
            'app_id' => env('OAUTH_FEISHU_APP_ID', ''),
            'app_secret' => env('OAUTH_FEISHU_APP_SECRET', ''),
            'callback_url' => env('OAUTH_FEISHU_CALLBACK_URL', ''),
            'scope' => null,
        ],

        // ---------------------------------------------------------------------
        // dingtalk：钉钉 OAuth2 登录（login.dingtalk.com）
        // - app_id / app_secret 对应钉钉应用 Client ID / Client Secret
        // - 默认 scope=openid；更多权限以钉钉开放平台文档为准
        // ---------------------------------------------------------------------
        'dingtalk' => [
            'driver' => 'dingtalk',
            'app_id' => env('OAUTH_DINGTALK_APP_ID', ''),
            'app_secret' => env('OAUTH_DINGTALK_APP_SECRET', ''),
            'callback_url' => env('OAUTH_DINGTALK_CALLBACK_URL', ''),
            'scope' => 'openid',
        ],

        // ---------------------------------------------------------------------
        // wework：企业微信
        // - app_id = 企业 CorpID；app_secret = 应用 Secret；agent_id 必填（授权 URL 需要）
        // - 应用内授权：getAuthUrl（员工在企微内打开）
        // - Web 扫码登录：getWebAuthUrl（浏览器扫码，login.work.weixin.qq.com）
        // - scope 常用 snsapi_base；需成员详情时参考企微文档调整
        // ---------------------------------------------------------------------
        'wework' => [
            'driver' => 'wework',
            'app_id' => env('OAUTH_WEWORK_CORP_ID', ''),
            'app_secret' => env('OAUTH_WEWORK_SECRET', ''),
            'callback_url' => env('OAUTH_WEWORK_CALLBACK_URL', ''),
            // 企业微信应用 AgentId（数字或字符串均可，勿留空）
            'agent_id' => env('OAUTH_WEWORK_AGENT_ID', ''),
            'scope' => 'snsapi_base',
        ],

        // ---------------------------------------------------------------------
        // fake：无外网假实现（单测 / 本地无 API Key 联调）
        // - getAuthUrl / getAccessToken / getUserInfo / getSessionKey / getWebAuthUrl 均可走通
        // - Fake 不做真实能力守卫；生产环境请删除或勿暴露给正式登录入口
        // ---------------------------------------------------------------------
        'fake' => [
            'driver' => 'fake',
        ],
    ],
];
