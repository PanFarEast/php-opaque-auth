<?php
declare(strict_types=1);

$version = getenv('OPAQUE_RELEASE_VERSION');
$commit = getenv('OPAQUE_RELEASE_COMMIT');
if (!is_string($version) || !preg_match('/^\d+\.\d+\.\d+$/D', $version)
    || !is_string($commit) || !preg_match('/^[a-f0-9]{40}$/D', $commit)) {
    throw new RuntimeException('Declare a stable release version and full commit ID');
}

$found = [];
foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($argv[1], FilesystemIterator::SKIP_DOTS)) as $file) {
    if ($file->getFilename() !== 'installed.json') continue;
    $data = json_decode(file_get_contents($file->getPathname()), true, flags: JSON_THROW_ON_ERROR);
    foreach ($data['packages'] ?? $data as $package) {
        if (($package['name'] ?? null) === 'panfareast/opaque-auth') $found[] = $package;
    }
}
if (count($found) !== 1) throw new RuntimeException('Exactly one installed release package must be recorded');
$package = $found[0];
$source = $package['source'] ?? [];
$dist = $package['dist'] ?? [];
$repository = 'https://github.com/panfareast/php-opaque-auth';
if (ltrim($package['version'], 'v') !== $version
    || $package['type'] !== 'php-ext'
    || ($package['php-ext']['extension-name'] ?? null) !== 'opaque_auth'
    || ($source['type'] ?? null) !== 'git'
    || strtolower(preg_replace('/\.git$/', '', $source['url'] ?? '')) !== $repository
    || ($source['reference'] ?? null) !== $commit
    || ($dist['reference'] ?? null) !== $commit
    || ($dist['type'] ?? null) !== 'zip'
    || strtolower($dist['url'] ?? '') !== "https://api.github.com/repos/panfareast/php-opaque-auth/zipball/$commit") {
    throw new RuntimeException('Installed version, PIE metadata, and GitHub source/dist references must match the release');
}
if ((new ReflectionExtension('opaque_auth'))->getVersion() !== $version) {
    throw new RuntimeException('Installed native module version must match the requested release');
}
echo "Verified tagged package version and source/distribution commit references\n";
