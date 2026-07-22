<?php

declare(strict_types=1);

use Symfony\Component\Translation\Loader\PhpFileLoader;
use Symfony\Component\Translation\Translator;

/**
 * Translator 组件（create 时复制到 Config/component/translator.php）
 *
 * 依赖 LocaleMiddleware（或 Bootstrap）已写入 Context `lang_locale`。
 * 语言包目录：APP_PATH/Resource/Translations/{locale}/messages.php
 *
 * 用法：
 * ```php
 * $t = Application::getApp()->get('translator');
 * $t->trans('hello');
 * ```
 *
 * @see docs/I18n.md
 * @see Config/i18n.php
 */

$i18nFile = APP_PATH . '/Config/i18n.php';
$i18n = is_file($i18nFile) ? include $i18nFile : [];
$i18n = is_array($i18n) ? $i18n : [];
$contextKey = (string) ($i18n['context_key'] ?? 'lang_locale');
$defaultLocale = (string) ($i18n['default_locale'] ?? 'zh_CN');
$fallback = (string) ($i18n['fallback_locale'] ?? 'en');

return [
    'translator' => static function () use ($contextKey, $defaultLocale, $fallback): Translator {
        if (\Swoolefy\Core\Coroutine\Context::has($contextKey)) {
            $locale = (string) \Swoolefy\Core\Coroutine\Context::get($contextKey);
        } else {
            $locale = $defaultLocale;
        }

        $translator = new Translator($locale);
        $translator->setFallbackLocales([$fallback]);
        $translator->addLoader('php', new PhpFileLoader());

        $resource = APP_PATH . "/Resource/Translations/{$locale}/messages.php";
        if (is_file($resource)) {
            $translator->addResource('php', $resource, $locale);
        }

        $fallbackResource = APP_PATH . "/Resource/Translations/{$fallback}/messages.php";
        if ($fallback !== $locale && is_file($fallbackResource)) {
            $translator->addResource('php', $fallbackResource, $fallback);
        }

        return $translator;
    },
];
