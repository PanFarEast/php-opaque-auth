<?php
declare(strict_types=1);

$expected = (int) $argv[1];
$present = extension_loaded('opaque_auth');
$installed = is_file(ini_get('extension_dir') . '/opaque_auth.so');
$iniFiles = array_filter([
    php_ini_loaded_file(),
    ...preg_split('/,\s*/', trim((string) php_ini_scanned_files())),
]);
$directives = 0;
foreach ($iniFiles as $file) {
    preg_match_all('/^\s*extension\s*=\s*([^\r\n;]+)/mi', file_get_contents($file), $matches);
    foreach ($matches[1] as $value) {
        $name = basename(trim($value, " \t\"'"));
        if ($name === 'opaque_auth' || $name === 'opaque_auth.so') {
            $path = trim($value, " \t\"'");
            if (str_contains($path, '/') && realpath($path) !== realpath(ini_get('extension_dir') . '/opaque_auth.so')) {
                throw new RuntimeException('PIE must enable the installed artifact, not a build-directory module');
            }
            $directives++;
        }
    }
}
if ($present !== (bool) $expected || $installed !== (bool) $expected || $directives !== $expected) {
    throw new RuntimeException('PIE module, installed artifact, and enablement must agree without duplicate entries');
}
