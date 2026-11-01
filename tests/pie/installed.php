<?php
declare(strict_types=1);

// Exercise native-only installation first; an optional autoloader checks the
// migration path in a separate process without supplying the public classes.
if (isset($argv[1])) require $argv[1];
foreach (['Opaque\\Client', 'Opaque\\Server'] as $class) {
    if (!class_exists($class, false) || !(new ReflectionClass($class))->isInternal()) {
        throw new RuntimeException('PIE must expose native classes without a facade');
    }
}
require_once __DIR__ . '/../abi.php';
verifyTargetRuntime();
verifyModuleAbi(ini_get('extension_dir') . '/opaque_auth.so');

function verifyInstalled(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

verifyInstalled(extension_loaded('opaque_auth'), 'PIE-installed module must be enabled');
$module = new ReflectionExtension('opaque_auth');
verifyInstalled($module->getVersion() === (getenv('OPAQUE_RELEASE_VERSION') ?: '0.1.0'), 'Installed module version must match the package');
$ksf = ['argon2id-custom' => ['memory' => 32, 'iterations' => 1, 'parallelism' => 1]];
$setup = Opaque\Server::createSetup();
$registration = Opaque\Client::startRegistration(['password' => 'installation test']);
$response = Opaque\Server::createRegistrationResponse([
    'serverSetup' => $setup,
    'userIdentifier' => 'installed-user',
    'registrationRequest' => $registration['registrationRequest'],
]);
$record = Opaque\Client::finishRegistration($registration + $response + [
    'password' => 'installation test', 'keyStretching' => $ksf,
]);
$login = Opaque\Client::startLogin(['password' => 'installation test']);
$challenge = Opaque\Server::startLogin($record + $login + [
    'serverSetup' => $setup, 'userIdentifier' => 'installed-user',
]);
$finish = Opaque\Client::finishLogin($login + $challenge + [
    'password' => 'installation test', 'keyStretching' => $ksf,
]);
verifyInstalled(is_array($finish), 'Installed client must authenticate');
$verified = Opaque\Server::finishLogin($challenge + $finish);
verifyInstalled($verified['sessionKey'] === $finish['sessionKey'], 'Installed peers must derive matching keys');
echo "PIE-installed native API: registration/login passed\n";
