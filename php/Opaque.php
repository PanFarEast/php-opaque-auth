<?php
declare(strict_types=1);

namespace Opaque;

/** @internal Shared validation and secret-safe native-call boundary. */
trait ProtocolArguments
{
    private static function native(string $function, #[\SensitiveParameter] array $arguments): mixed
    {
        try {
            return $function(...$arguments);
        } catch (\Throwable $error) {
            // Do not retain the native exception as `previous`: its trace has
            // raw protocol arguments on PHP configurations that show args.
            throw new \RuntimeException($error->getMessage());
        }
    }

    private static function required(#[\SensitiveParameter] array $params, string $key): string
    {
        if (!isset($params[$key]) || !is_string($params[$key])) {
            throw new \InvalidArgumentException("Missing or invalid parameter: {$key}");
        }
        return $params[$key];
    }

    private static function encodeOptions(#[\SensitiveParameter] array $options): string
    {
        try {
            return json_encode($options, JSON_THROW_ON_ERROR);
        } catch (\Throwable) {
            // JSON's own stack frame contains its unredacted input argument.
            throw new \InvalidArgumentException('Invalid options');
        }
    }
}

/** Convenience facade matching @serenity-kit/opaque's static API. */
final class Client
{
    use ProtocolArguments;

    private static function options(#[\SensitiveParameter] array $params): string
    {
        $options = [];
        if (array_key_exists('identifiers', $params)) {
            if (!is_array($params['identifiers'])) throw new \InvalidArgumentException('Invalid identifiers');
            $options['identifiers'] = (object) $params['identifiers'];
        }
        if (array_key_exists('keyStretching', $params)) $options['keyStretching'] = $params['keyStretching'];
        return self::encodeOptions($options);
    }

    public static function startRegistration(#[\SensitiveParameter] array $params): array
    {
        [$state, $request] = self::native('opaque_start_client_registration', [self::required($params, 'password')]);
        return ['clientRegistrationState' => $state, 'registrationRequest' => $request];
    }

    public static function finishRegistration(#[\SensitiveParameter] array $params): array
    {
        [$record, $export, $publicKey] = self::native('opaque_finish_client_registration', [
            self::required($params, 'clientRegistrationState'),
            self::required($params, 'registrationResponse'),
            self::required($params, 'password'),
            self::options($params),
        ]);
        // Kept separate from protocol values so options never enter error text.
        return ['registrationRecord' => $record, 'exportKey' => $export, 'serverStaticPublicKey' => $publicKey];
    }

    public static function startLogin(#[\SensitiveParameter] array $params): array
    {
        [$state, $request] = self::native('opaque_start_client_login', [self::required($params, 'password')]);
        return ['clientLoginState' => $state, 'startLoginRequest' => $request];
    }

    public static function finishLogin(#[\SensitiveParameter] array $params): ?array
    {
        $result = self::native('opaque_finish_client_login', [
            self::required($params, 'clientLoginState'),
            self::required($params, 'loginResponse'),
            self::required($params, 'password'),
            self::options($params),
        ]);
        if ($result === null) return null;
        return ['finishLoginRequest' => $result[0], 'sessionKey' => $result[1], 'exportKey' => $result[2], 'serverStaticPublicKey' => $result[3]];
    }
}

final class Server
{
    use ProtocolArguments;

    private static function options(#[\SensitiveParameter] array $params): string
    {
        $identifiers = array_key_exists('identifiers', $params) ? $params['identifiers'] : [];
        if (!is_array($identifiers)) throw new \InvalidArgumentException('Invalid identifiers');
        return self::encodeOptions(['identifiers' => (object) $identifiers]);
    }

    public static function createSetup(): string { return \opaque_create_setup(); }
    public static function getPublicKey(#[\SensitiveParameter] string $serverSetup): string
    {
        return self::native('opaque_get_public_key', [$serverSetup]);
    }

    public static function createRegistrationResponse(#[\SensitiveParameter] array $params): array
    {
        $response = self::native('opaque_create_registration_response', [
            self::required($params, 'serverSetup'), self::required($params, 'userIdentifier'),
            self::required($params, 'registrationRequest'),
        ]);
        return ['registrationResponse' => $response];
    }

    public static function startLogin(#[\SensitiveParameter] array $params): array
    {
        [$state, $response] = self::native('opaque_start_server_login', [
            self::required($params, 'serverSetup'), $params['registrationRecord'] ?? null,
            self::required($params, 'startLoginRequest'), self::required($params, 'userIdentifier'),
            self::options($params),
        ]);
        return ['serverLoginState' => $state, 'loginResponse' => $response];
    }

    public static function finishLogin(#[\SensitiveParameter] array $params): array
    {
        return ['sessionKey' => self::native('opaque_finish_server_login', [
            self::required($params, 'serverLoginState'), self::required($params, 'finishLoginRequest'),
            self::options($params),
        ])];
    }
}
