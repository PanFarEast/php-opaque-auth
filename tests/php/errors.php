<?php
declare(strict_types=1);

// Exercise validation failures before native calls, with trace arguments enabled.
ini_set('zend.exception_ignore_args', '0');
$params = [
    'password' => 'PASSWORD_TRACE_SENTINEL',
    'clientRegistrationState' => 'STATE_TRACE_SENTINEL',
    'registrationResponse' => 'RESPONSE_TRACE_SENTINEL',
    'serverSetup' => 'SETUP_TRACE_SENTINEL',
    'userIdentifier' => 'test-user',
    'startLoginRequest' => 'REQUEST_TRACE_SENTINEL',
    'identifiers' => ['client' => "\xff", 'server' => 'IDENTIFIER_TRACE_SENTINEL'],
];
foreach ([
    fn() => Opaque\Client::finishRegistration($params),
    fn() => Opaque\Server::startLogin($params),
] as $call) {
    $error = null;
    try {
        $call();
    } catch (Throwable $caught) {
        $error = $caught;
    }
    if ($error === null || $error->getPrevious() !== null) {
        throw new RuntimeException('Invalid options must fail without retaining unsafe exceptions');
    }
    if (str_contains((string) $error, '_TRACE_SENTINEL') || str_contains(var_export($error->getTrace(), true), '_TRACE_SENTINEL')) {
        throw new RuntimeException('Option-validation errors must redact sensitive arguments');
    }
}

// The engine's own argument parser must redact inputs too, before the Rust
// method body runs. Exercise every array-parameter method at that boundary.
foreach ([
    [Opaque\Client::class, 'startRegistration'],
    [Opaque\Client::class, 'finishRegistration'],
    [Opaque\Client::class, 'startLogin'],
    [Opaque\Client::class, 'finishLogin'],
    [Opaque\Server::class, 'createRegistrationResponse'],
    [Opaque\Server::class, 'startLogin'],
    [Opaque\Server::class, 'finishLogin'],
] as $method) {
    $error = null;
    try {
        $method('ARGUMENT_TRACE_SENTINEL');
    } catch (Throwable $caught) {
        $error = $caught;
    }
    if (!$error instanceof TypeError || $error->getPrevious() !== null
        || str_contains((string) $error, '_TRACE_SENTINEL')
        || str_contains(var_export($error->getTrace(), true), '_TRACE_SENTINEL')) {
        throw new RuntimeException('Engine argument errors must redact native API inputs');
    }
}

$throwingOption = new class implements JsonSerializable {
    public function jsonSerialize(): mixed {
        throw new RuntimeException('CALLBACK_TRACE_SENTINEL');
    }
};
$recursiveOption = [];
$recursiveOption['self'] = &$recursiveOption;
foreach ([$throwingOption, $recursiveOption] as $option) {
    $error = null;
    try {
        Opaque\Client::finishRegistration(array_replace($params, ['identifiers' => [], 'keyStretching' => $option]));
    } catch (Throwable $caught) {
        $error = $caught;
    }
    if (!$error instanceof InvalidArgumentException || $error->getPrevious() !== null
        || str_contains((string) $error, '_TRACE_SENTINEL')
        || str_contains(var_export($error->getTrace(), true), '_TRACE_SENTINEL')) {
        throw new RuntimeException('Option conversion must discard unsafe callback exceptions');
    }
}
