<?php
declare(strict_types=1);

require __DIR__ . '/../../php/Opaque.php';
require __DIR__ . '/../../php/Opaque.php';
foreach (['Opaque\\Client', 'Opaque\\Server'] as $class) {
    $reflection = new ReflectionClass($class);
    if (!$reflection->isInternal() || !$reflection->isFinal()) {
        throw new RuntimeException('Loading the legacy facade must preserve the native API');
    }
    foreach ($reflection->getMethods() as $method) {
        if (!$method->isStatic()) {
            throw new RuntimeException('The public protocol API must stay static');
        }
        foreach ($method->getParameters() as $parameter) {
            if (count($parameter->getAttributes(SensitiveParameter::class)) !== 1) {
                throw new RuntimeException('Every public protocol argument must be sensitive');
            }
        }
    }
}
$setup = Opaque\Server::createSetup();
if (Opaque\Server::getPublicKey(serverSetup: $setup) !== opaque_get_public_key($setup)) {
    throw new RuntimeException('Named facade arguments and low-level functions remain supported');
}
