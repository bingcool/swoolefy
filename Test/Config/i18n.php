<?php

declare(strict_types=1);

/**
 * Test 应用 I18n 配置。
 *
 * @see \Swoolefy\Http\Middleware\LocaleMiddleware
 * @see docs/I18n.md
 */

return [
    'default_locale' => env('I18N_DEFAULT_LOCALE', 'zh_CN'),
    'fallback_locale' => env('I18N_FALLBACK_LOCALE', 'en'),
    'allowed_locales' => ['zh_CN', 'en'],
    'context_key' => 'lang_locale',
    'query_keys' => ['lang', 'locale'],
    'header_keys' => ['x-locale', 'x-language'],
];
