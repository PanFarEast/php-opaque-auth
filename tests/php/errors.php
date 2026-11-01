<?php
declare(strict_types=1);

require_once __DIR__ . '/../../php/Opaque.php';

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
