<?php
declare(strict_types=1);

require_once __DIR__ . '/../../php/Opaque.php';

function optionsCheck(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

// Use cheap parameters for all harness protocol operations. RFC-profile KSF
// execution is intentionally not exercised here because it requires 2 GiB.
$cheapKsf = ['argon2id-custom' => ['memory' => 32, 'iterations' => 1, 'parallelism' => 1]];
$setup = Opaque\Server::createSetup();
$registration = Opaque\Client::startRegistration(['password' => 'options test']);
$response = Opaque\Server::createRegistrationResponse([
    'serverSetup' => $setup,
    'userIdentifier' => 'alice',
    'registrationRequest' => $registration['registrationRequest'],
]);
$registered = Opaque\Client::finishRegistration([
    'clientRegistrationState' => $registration['clientRegistrationState'],
    'registrationResponse' => $response['registrationResponse'],
    'password' => 'options test',
    'keyStretching' => $cheapKsf,
]);
$clientLogin = Opaque\Client::startLogin(['password' => 'options test']);
$serverLogin = Opaque\Server::startLogin([
    'serverSetup' => $setup,
    'registrationRecord' => $registered['registrationRecord'],
    'startLoginRequest' => $clientLogin['startLoginRequest'],
    'userIdentifier' => 'alice',
]);
$clientFinishParams = [
    'clientLoginState' => $clientLogin['clientLoginState'],
    'loginResponse' => $serverLogin['loginResponse'],
    'password' => 'options test',
    'keyStretching' => $cheapKsf,
];
$clientFinish = Opaque\Client::finishLogin($clientFinishParams);
$serverFinishParams = [
    'serverLoginState' => $serverLogin['serverLoginState'],
    'finishLoginRequest' => $clientFinish['finishLoginRequest'],
];

$clientRegistrationParams = [
    'clientRegistrationState' => $registration['clientRegistrationState'],
    'registrationResponse' => $response['registrationResponse'],
    'password' => 'options test',
];
$invalidKsf = [
    'unsupported preset' => 'argon2id',
    'missing field' => ['argon2id-custom' => ['memory' => 32, 'iterations' => 1]],
    'zero field' => ['argon2id-custom' => ['memory' => 0, 'iterations' => 1, 'parallelism' => 1]],
    'out of range field' => ['argon2id-custom' => ['memory' => 4294967296, 'iterations' => 1, 'parallelism' => 1]],
    'nonnumeric field' => ['argon2id-custom' => ['memory' => 'thirty-two', 'iterations' => 1, 'parallelism' => 1]],
];
foreach ($invalidKsf as $label => $ksf) {
    $params = $clientRegistrationParams;
    $params['keyStretching'] = $ksf;
    $error = null;
    try {
        Opaque\Client::finishRegistration($params);
    } catch (Throwable $caught) {
        $error = $caught;
    }
    optionsCheck($error !== null, "Invalid KSF ({$label}) must be rejected");
    optionsCheck(!str_contains($error->getMessage(), 'options test'), "Invalid KSF ({$label}) error must not disclose password");
}

$invalidIdentifiers = [
    'null' => null,
    'wrong type' => 'alice',
    'nonstring client' => ['client' => 123],
    'nonstring server' => ['server' => false],
];
$identifierCalls = [
    'client registration finish' => fn($ids) => Opaque\Client::finishRegistration($clientRegistrationParams + ['identifiers' => $ids, 'keyStretching' => $cheapKsf]),
    'client login finish' => fn($ids) => Opaque\Client::finishLogin($clientFinishParams + ['identifiers' => $ids]),
    'server login start' => fn($ids) => Opaque\Server::startLogin([
        'serverSetup' => $setup,
        'registrationRecord' => $registered['registrationRecord'],
        'startLoginRequest' => $clientLogin['startLoginRequest'],
        'userIdentifier' => 'alice',
        'identifiers' => $ids,
    ]),
    'server login finish' => fn($ids) => Opaque\Server::finishLogin($serverFinishParams + ['identifiers' => $ids]),
];
foreach ($invalidIdentifiers as $label => $identifiers) {
    foreach ($identifierCalls as $operation => $call) {
        $error = null;
        try {
            $call($identifiers);
        } catch (Throwable $caught) {
            $error = $caught;
        }
        optionsCheck($error !== null, "Invalid identifiers ({$label}) must be rejected by {$operation}");
        optionsCheck(!str_contains($error->getMessage(), 'options test'), "Invalid identifiers ({$label}) error must not disclose password");
    }
}

// A partial identifiers object is supported; omitted fields remain optional.
$partial = Opaque\Client::finishRegistration($clientRegistrationParams + [
    'identifiers' => ['client' => 'alice'],
    'keyStretching' => $cheapKsf,
]);
optionsCheck(isset($partial['registrationRecord']), 'Client accepts partial identifiers');
$partialServerLogin = Opaque\Server::startLogin([
    'serverSetup' => $setup,
    'registrationRecord' => $registered['registrationRecord'],
    'startLoginRequest' => $clientLogin['startLoginRequest'],
    'userIdentifier' => 'alice',
    'identifiers' => ['server' => 'example.test'],
]);
optionsCheck(isset($partialServerLogin['loginResponse']), 'Server accepts partial identifiers');
