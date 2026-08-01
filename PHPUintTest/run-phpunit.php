<?php

declare(strict_types=1);

/**
 * 9.4：统一经 vendor/bin/phpunit 执行，跑前打印收集数量以防 suite 漏目录。
 */
$projectRoot = dirname(__DIR__);
$phpunit = $projectRoot . '/vendor/bin/phpunit';

if (!is_file($phpunit)) {
    fwrite(STDERR, "缺少 vendor/bin/phpunit，请先 composer install\n");
    exit(1);
}

$args = array_slice($argv, 1);
$baseArgs = array_merge(['--configuration', $projectRoot . '/phpunit.xml.dist'], $args);

$listArgs = array_merge($baseArgs, ['--list-tests']);
$listCmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($phpunit) . ' '
    . implode(' ', array_map('escapeshellarg', $listArgs));
exec($listCmd . ' 2>/dev/null', $lines);
$tests = array_values(array_filter(
    $lines,
    static fn (string $line): bool => str_contains($line, '::')
));
echo 'PHPUnit 收集: ' . count($tests) . " 个测试\n";

$runCmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($phpunit) . ' '
    . implode(' ', array_map('escapeshellarg', $baseArgs));
passthru($runCmd, $exitCode);
exit($exitCode);
