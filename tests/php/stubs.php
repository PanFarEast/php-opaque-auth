<?php
declare(strict_types=1);

$stubMode = ($argv[1] ?? null) === '--stub';
if ($stubMode) {
    require __DIR__ . '/../../stubs/opaque_auth.stub.php';
}

function stubSignature(ReflectionFunctionAbstract $function): array
{
    return [
        'return' => (string) $function->getReturnType(),
        'parameters' => array_map(static fn(ReflectionParameter $parameter): array => [
            'name' => $parameter->getName(),
            'type' => (string) $parameter->getType(),
            'optional' => $parameter->isOptional(),
            'default' => $parameter->isDefaultValueAvailable() ? $parameter->getDefaultValue() : null,
            'sensitive' => count($parameter->getAttributes(SensitiveParameter::class)),
        ], $function->getParameters()),
    ];
}

function stubApi(): array
{
    $api = [];
    foreach (['Opaque\\Client', 'Opaque\\Server'] as $name) {
        $class = new ReflectionClass($name);
        $api[$name] = ['final' => $class->isFinal(), 'methods' => []];
        foreach ($class->getMethods() as $method) {
            $api[$name]['methods'][$method->getName()] = stubSignature($method) + [
                'static' => $method->isStatic(),
                'public' => $method->isPublic(),
            ];
        }
        ksort($api[$name]['methods']);
    }
    // Discover all functions independently on each side, so additions and
    // removals are caught rather than hidden by a hard-coded list.
    foreach (get_defined_functions()['internal'] as $name) {
        if (str_starts_with($name, 'opaque_')) $api[$name] = stubSignature(new ReflectionFunction($name));
    }
    foreach (get_defined_functions()['user'] as $name) {
        if (str_starts_with($name, 'opaque_')) $api[$name] = stubSignature(new ReflectionFunction($name));
    }
    ksort($api);
    return $api;
}

if ($stubMode) {
    echo json_encode(stubApi(), JSON_THROW_ON_ERROR);
    exit;
}

// Stub declarations must never be loaded into a process with the extension.
// Reflect them in a separate, configuration-free PHP process instead.
$command = escapeshellarg(PHP_BINARY) . ' -n ' . escapeshellarg(__FILE__) . ' --stub';
exec($command, $output, $status);
if ($status !== 0 || json_decode(implode("\n", $output), true, flags: JSON_THROW_ON_ERROR) !== stubApi()) {
    throw new RuntimeException('Development stubs must match the native PHP signatures');
}
