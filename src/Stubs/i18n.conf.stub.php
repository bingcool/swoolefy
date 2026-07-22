<?php

declare(strict_types=1);

/**
 * I18n / Locale 配置（create 时复制为 Config/i18n.php）
 *
 * LocaleMiddleware 读取本文件；translator 组件读取 Context[context_key]。
 *
 * @see \Swoolefy\Http\Middleware\LocaleMiddleware
 * @see docs/I18n.md
 */

return [
    // 未协商到合法 locale 时的默认值
    'default_locale' => env('I18N_DEFAULT_LOCALE', 'zh_CN'),
    // 资源缺失时 Translator 回退（应用侧可选用）
    'fallback_locale' => env('I18N_FALLBACK_LOCALE', 'en'),
    // 允许写入 Context 的 locale 白名单
    'allowed_locales' => ['zh_CN', 'en'],
    // 协程 Context 键名（与 translator 组件一致）
    'context_key' => 'lang_locale',
    // Query / Header 额外键
    'query_keys' => ['lang', 'locale'],
    'header_keys' => ['x-locale', 'x-language'],
];
