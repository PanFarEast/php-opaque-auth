<?php
declare(strict_types=1);

function malformedCheck(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

// Build genuine values once so each malformed value is the only changed input.
$setup = Opaque\Server::createSetup();
$registration = Opaque\Client::startRegistration(['password' => 'test password']);
$registrationResponse = Opaque\Server::createRegistrationResponse([
    'serverSetup' => $setup,
    'userIdentifier' => 'alice',
    'registrationRequest' => $registration['registrationRequest'],
]);
$registered = Opaque\Client::finishRegistration([
    'clientRegistrationState' => $registration['clientRegistrationState'],
    'registrationResponse' => $registrationResponse['registrationResponse'],
    'password' => 'test password',
    'keyStretching' => ['argon2id-custom' => ['memory' => 32, 'iterations' => 1, 'parallelism' => 1]],
]);
$clientLogin = Opaque\Client::startLogin(['password' => 'test password']);
$serverLogin = Opaque\Server::startLogin([
    'serverSetup' => $setup,
    'registrationRecord' => $registered['registrationRecord'],
    'startLoginRequest' => $clientLogin['startLoginRequest'],
    'userIdentifier' => 'alice',
]);
$clientFinish = Opaque\Client::finishLogin([
    'clientLoginState' => $clientLogin['clientLoginState'],
    'loginResponse' => $serverLogin['loginResponse'],
    'password' => 'test password',
    'keyStretching' => ['argon2id-custom' => ['memory' => 32, 'iterations' => 1, 'parallelism' => 1]],
]);
malformedCheck(is_array($clientFinish), 'valid baseline must authenticate');

$valid = [
    'serverSetup' => $setup,
    'registrationRequest' => $registration['registrationRequest'],
    'registrationResponse' => $registrationResponse['registrationResponse'],
    'clientRegistrationState' => $registration['clientRegistrationState'],
    'registrationRecord' => $registered['registrationRecord'],
    'startLoginRequest' => $clientLogin['startLoginRequest'],
    'clientLoginState' => $clientLogin['clientLoginState'],
    'loginResponse' => $serverLogin['loginResponse'],
    'serverLoginState' => $serverLogin['serverLoginState'],
    'finishLoginRequest' => $clientFinish['finishLoginRequest'],
];

$calls = [
    'serverSetup' => fn($v) => Opaque\Server::getPublicKey($v),
    'registrationRequest' => fn($v) => Opaque\Server::createRegistrationResponse(['serverSetup' => $valid['serverSetup'], 'userIdentifier' => 'alice', 'registrationRequest' => $v]),
    'registrationResponse' => fn($v) => Opaque\Client::finishRegistration(['clientRegistrationState' => $valid['clientRegistrationState'], 'registrationResponse' => $v, 'password' => 'test password']),
    'clientRegistrationState' => fn($v) => Opaque\Client::finishRegistration(['clientRegistrationState' => $v, 'registrationResponse' => $valid['registrationResponse'], 'password' => 'test password']),
    'registrationRecord' => fn($v) => Opaque\Server::startLogin(['serverSetup' => $valid['serverSetup'], 'registrationRecord' => $v, 'startLoginRequest' => $valid['startLoginRequest'], 'userIdentifier' => 'alice']),
    'startLoginRequest' => fn($v) => Opaque\Server::startLogin(['serverSetup' => $valid['serverSetup'], 'registrationRecord' => $valid['registrationRecord'], 'startLoginRequest' => $v, 'userIdentifier' => 'alice']),
    'clientLoginState' => fn($v) => Opaque\Client::finishLogin(['clientLoginState' => $v, 'loginResponse' => $valid['loginResponse'], 'password' => 'test password']),
    'loginResponse' => fn($v) => Opaque\Client::finishLogin(['clientLoginState' => $valid['clientLoginState'], 'loginResponse' => $v, 'password' => 'test password']),
    'serverLoginState' => fn($v) => Opaque\Server::finishLogin(['serverLoginState' => $v, 'finishLoginRequest' => $valid['finishLoginRequest']]),
    'finishLoginRequest' => fn($v) => Opaque\Server::finishLogin(['serverLoginState' => $valid['serverLoginState'], 'finishLoginRequest' => $v]),
];

foreach ($calls as $name => $call) {
    $badValues = ["!malformed-secret-{$name}!", 'YQ==', '+/8', 'AAAA'];
    // Noncanonical encodings of genuine values isolate decoding from validation.
    $padding = (4 - strlen($valid[$name]) % 4) % 4;
    if ($padding !== 0) {
        $badValues[] = $valid[$name] . str_repeat('=', $padding);
    }
    $standardAlphabet = strtr($valid[$name], '-_', '+/');
    if ($standardAlphabet !== $valid[$name]) {
        $badValues[] = $standardAlphabet;
    }
    foreach ($badValues as $bad) {
        $error = null;
        $output = '';
        ob_start();
        try {
            $call($bad);
        } catch (Throwable $caught) {
            $error = $caught;
        } finally {
            $output = (string) ob_get_clean();
        }
        malformedCheck($error !== null, "{$name} malformed input must fail without crashing");
        malformedCheck(!str_contains($error->getMessage(), $bad), "{$name} error must not echo input");
        malformedCheck(!str_contains($error->getMessage(), 'malformed-secret'), "{$name} error must not disclose sentinel");
        malformedCheck(!str_contains($output, $bad) && !str_contains($output, 'malformed-secret'), "{$name} output must not disclose input");
        foreach ([...array_values($valid), 'test password'] as $secret) {
            malformedCheck(!str_contains($error->getMessage(), $secret) && !str_contains($output, $secret), "{$name} failure must not disclose valid protocol secrets");
        }
    }
}
