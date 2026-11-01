<?php
declare(strict_types=1);

function verifyTargetRuntime(): void
{
    $version = getenv('OPAQUE_TEST_PHP_VERSION');
    $variant = getenv('OPAQUE_TEST_PHP_VARIANT');
    if (!in_array($variant, ['cli', 'zts'], true) || !in_array($version, ['8.3', '8.4', '8.5'], true)) {
        throw new RuntimeException('The test environment must declare the target PHP version and variant');
    }
    if (PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION !== $version || (bool) PHP_ZTS !== ($variant === 'zts')) {
        throw new RuntimeException('The PHP runtime does not match the requested version and thread mode');
    }
}

function verifyModuleAbi(string $library): void
{
    if (!extension_loaded('opaque_auth') || !is_file($library)) {
        throw new RuntimeException('The target extension must be loaded from an existing artifact');
    }
    ob_start();
    phpinfo(INFO_GENERAL);
    $info = (string) ob_get_clean();
    if (!preg_match('/^PHP Extension Build => (.+)$/m', $info, $matches)) {
        throw new RuntimeException('Unable to determine the target PHP module ABI');
    }
    $buildId = trim($matches[1]);
    if (!str_contains(file_get_contents($library), $buildId . "\0")) {
        throw new RuntimeException('The extension artifact must match the target PHP API, thread mode, and debug mode');
    }
}
