<?php
declare(strict_types=1);

foreach (['Opaque\\Client', 'Opaque\\Server'] as $class) {
    if (!class_exists($class, false) || !(new ReflectionClass($class))->isInternal()) {
        throw new RuntimeException('The extension must provide the public classes natively');
    }
}
$setup = Opaque\Server::createSetup();
$ksf = ['argon2id-custom' => ['memory' => 32, 'iterations' => 1, 'parallelism' => 1]];
$start = Opaque\Client::startRegistration(['password' => 'native registration']);
$response = Opaque\Server::createRegistrationResponse([
    'serverSetup' => $setup,
    'userIdentifier' => 'native-user',
    'registrationRequest' => $start['registrationRequest'],
]);
$record = Opaque\Client::finishRegistration($start + $response + [
    'password' => 'native registration',
    'keyStretching' => $ksf,
]);
if (array_keys($start) !== ['clientRegistrationState', 'registrationRequest']
    || array_keys($record) !== ['registrationRecord', 'exportKey', 'serverStaticPublicKey']) {
    throw new RuntimeException('Native results must preserve facade field order');
}
if (!isset($record['registrationRecord'], $record['exportKey'], $record['serverStaticPublicKey'])
    || $record['serverStaticPublicKey'] !== Opaque\Server::getPublicKey($setup)) {
    throw new RuntimeException('Native registration must return its record and server public key');
}
$login = Opaque\Client::startLogin(['password' => 'native registration']);
$challenge = Opaque\Server::startLogin($login + $record + [
    'serverSetup' => $setup,
    'userIdentifier' => 'native-user',
]);
$finish = Opaque\Client::finishLogin($login + $challenge + [
    'password' => 'native registration',
    'keyStretching' => $ksf,
]);
if ($finish === null) throw new RuntimeException('Native client must authenticate');
$verified = Opaque\Server::finishLogin($challenge + $finish);
if ($verified['sessionKey'] !== $finish['sessionKey'] || $record['exportKey'] !== $finish['exportKey']) {
    throw new RuntimeException('Native peers must agree on keys');
}
