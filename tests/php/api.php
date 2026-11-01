<?php
declare(strict_types=1);

require_once __DIR__ . '/../../php/Opaque.php';

function check(bool $condition, string $message): void {
    if (!$condition) { throw new RuntimeException($message); }
}

check(extension_loaded('ext-opaque-auth'), 'renamed extension module is registered');

$setup = Opaque\Server::createSetup();
check(is_string($setup) && $setup !== '', 'server setup is returned');
check(is_string(Opaque\Server::getPublicKey($setup)), 'server public key is returned');
$client = Opaque\Client::startRegistration(['password' => 'correct horse']);
check(isset($client['clientRegistrationState'], $client['registrationRequest']), 'registration start fields');
$response = Opaque\Server::createRegistrationResponse([
    'serverSetup' => $setup,
    'userIdentifier' => 'alice',
    'registrationRequest' => $client['registrationRequest'],
]);
$record = Opaque\Client::finishRegistration([
    'clientRegistrationState' => $client['clientRegistrationState'],
    'registrationResponse' => $response['registrationResponse'],
    'password' => 'correct horse',
    'identifiers' => ['client' => 'alice', 'server' => 'example.test'],
    'keyStretching' => 'memory-constrained',
]);
check(isset($record['registrationRecord'], $record['exportKey'], $record['serverStaticPublicKey']), 'registration finish fields');

$clientLogin = Opaque\Client::startLogin(['password' => 'correct horse']);
$serverLogin = Opaque\Server::startLogin([
    'serverSetup' => $setup,
    'registrationRecord' => $record['registrationRecord'],
    'startLoginRequest' => $clientLogin['startLoginRequest'],
    'userIdentifier' => 'alice',
    'identifiers' => ['client' => 'alice', 'server' => 'example.test'],
]);
$finish = Opaque\Client::finishLogin([
    'clientLoginState' => $clientLogin['clientLoginState'],
    'loginResponse' => $serverLogin['loginResponse'],
    'password' => 'correct horse',
    'identifiers' => ['client' => 'alice', 'server' => 'example.test'],
    'keyStretching' => ['argon2id-custom' => ['memory' => 65536, 'iterations' => 3, 'parallelism' => 4]],
]);
check(is_array($finish) && isset($finish['finishLoginRequest'], $finish['sessionKey'], $finish['exportKey'], $finish['serverStaticPublicKey']), 'client login finish fields');
$serverFinish = Opaque\Server::finishLogin([
    'serverLoginState' => $serverLogin['serverLoginState'],
    'finishLoginRequest' => $finish['finishLoginRequest'],
    'identifiers' => ['client' => 'alice', 'server' => 'example.test'],
]);
check($serverFinish['sessionKey'] === $finish['sessionKey'], 'both sides derive the same session key');
$wrongPassword = Opaque\Client::finishLogin([
    'clientLoginState' => $clientLogin['clientLoginState'],
    'loginResponse' => $serverLogin['loginResponse'],
    'password' => 'wrong password',
    'identifiers' => ['client' => 'alice', 'server' => 'example.test'],
]);
check($wrongPassword === null, 'wrong password returns a safe failure value');

try {
    Opaque\Client::startRegistration([]);
    throw new RuntimeException('missing password must fail');
} catch (InvalidArgumentException $expected) {}

$error = null;
try {
    Opaque\Client::finishRegistration([
        'clientRegistrationState' => 'x', 'registrationResponse' => 'x', 'password' => 'x',
        'keyStretching' => 'unsupported',
    ]);
    throw new RuntimeException('sentinel: unsupported KSF must fail');
} catch (Throwable $caught) {
    $error = $caught;
}
check($error !== null && !str_contains($error->getMessage(), 'sentinel:') && str_contains($error->getMessage(), 'keyStretching'), 'unsupported KSF is rejected safely');

$error = null;
try {
    Opaque\Server::getPublicKey('private malformed input');
    throw new RuntimeException('sentinel: malformed setup must fail');
} catch (Throwable $caught) {
    $error = $caught;
}
check($error !== null && !str_contains($error->getMessage(), 'sentinel:') && !str_contains($error->getMessage(), 'private malformed input'), 'malformed input is rejected without echoing secrets');

$previousIgnoreArgs = ini_get('zend.exception_ignore_args');
ini_set('zend.exception_ignore_args', '0');
$traceError = null;
try {
    Opaque\Server::getPublicKey('TRACE_MARKER_NOT_SECRET');
} catch (Throwable $caught) {
    $traceError = $caught;
} finally {
    if ($previousIgnoreArgs !== false) ini_set('zend.exception_ignore_args', $previousIgnoreArgs);
}
check($traceError !== null, 'invalid setup produces an exception');
check($traceError->getPrevious() === null, 'facade does not retain the native exception trace');
check(!str_contains(var_export($traceError->getTrace(), true), 'TRACE_MARKER_NOT_SECRET'), 'trace arguments redact protocol inputs when exception args are enabled');

$passwordTraceError = null;
ini_set('zend.exception_ignore_args', '0');
try {
    Opaque\Client::finishRegistration([
        'clientRegistrationState' => '!',
        'registrationResponse' => '!',
        'password' => 'TRACE_PASSWORD_MARKER_NOT_SECRET',
    ]);
} catch (Throwable $caught) {
    $passwordTraceError = $caught;
} finally {
    if ($previousIgnoreArgs !== false) ini_set('zend.exception_ignore_args', $previousIgnoreArgs);
}
check($passwordTraceError !== null && $passwordTraceError->getPrevious() === null, 'native registration error is safely wrapped');
check(!str_contains(var_export($passwordTraceError->getTrace(), true), 'TRACE_PASSWORD_MARKER_NOT_SECRET'), 'password is redacted from exception traces');
