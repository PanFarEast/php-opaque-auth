<?php
declare(strict_types=1);

// IDE/static-analysis declarations only. Never require or autoload this file:
// the enabled opaque_auth extension provides the implementation.

namespace Opaque {
    /** Native OPAQUE client. Treat all protocol values and keys as secrets. */
    final class Client
    {
        /**
         * @param array{password: string} $params
         * @return array{clientRegistrationState: string, registrationRequest: string}
         * @throws \InvalidArgumentException
         * @throws \RuntimeException
         */
        public static function startRegistration(#[\SensitiveParameter] array $params): array {}

        /**
         * Registration and login must use the same identifiers and key stretching.
         *
         * @param array{
         *     clientRegistrationState: string,
         *     registrationResponse: string,
         *     password: string,
         *     identifiers?: array{client?: string, server?: string},
         *     keyStretching?: 'memory-constrained'|'rfc-recommended'|'rfc-draft-recommended'|array{'argon2id-custom': array{memory: int, iterations: int, parallelism: int}}
         * } $params
         * @return array{registrationRecord: string, exportKey: string, serverStaticPublicKey: string}
         * @throws \InvalidArgumentException
         * @throws \RuntimeException
         */
        public static function finishRegistration(#[\SensitiveParameter] array $params): array {}

        /**
         * @param array{password: string} $params
         * @return array{clientLoginState: string, startLoginRequest: string}
         * @throws \InvalidArgumentException
         * @throws \RuntimeException
         */
        public static function startLogin(#[\SensitiveParameter] array $params): array {}

        /**
         * Returns null when client authentication fails (including a wrong password).
         *
         * @param array{
         *     clientLoginState: string,
         *     loginResponse: string,
         *     password: string,
         *     identifiers?: array{client?: string, server?: string},
         *     keyStretching?: 'memory-constrained'|'rfc-recommended'|'rfc-draft-recommended'|array{'argon2id-custom': array{memory: int, iterations: int, parallelism: int}}
         * } $params
         * @return array{finishLoginRequest: string, sessionKey: string, exportKey: string, serverStaticPublicKey: string}|null
         * @throws \InvalidArgumentException
         * @throws \RuntimeException
         */
        public static function finishLogin(#[\SensitiveParameter] array $params): ?array {}
    }

    /** Native OPAQUE server. Applications own persistence and session lifecycle. */
    final class Server
    {
        /** Returns a secret, serializable server setup. */
        public static function createSetup(): string {}

        /** @throws \RuntimeException */
        public static function getPublicKey(#[\SensitiveParameter] string $serverSetup): string {}

        /**
         * @param array{serverSetup: string, userIdentifier: string, registrationRequest: string} $params
         * @return array{registrationResponse: string}
         * @throws \InvalidArgumentException
         * @throws \RuntimeException
         */
        public static function createRegistrationResponse(#[\SensitiveParameter] array $params): array {}

        /**
         * Omit registrationRecord, or use null, for an unknown account.
         *
         * @param array{
         *     serverSetup: string,
         *     userIdentifier: string,
         *     startLoginRequest: string,
         *     registrationRecord?: string|null,
         *     identifiers?: array{client?: string, server?: string}
         * } $params
         * @return array{serverLoginState: string, loginResponse: string}
         * @throws \InvalidArgumentException
         * @throws \RuntimeException
         */
        public static function startLogin(#[\SensitiveParameter] array $params): array {}

        /**
         * Verify the client before establishing an application session.
         *
         * @param array{
         *     serverLoginState: string,
         *     finishLoginRequest: string,
         *     identifiers?: array{client?: string, server?: string}
         * } $params
         * @return array{sessionKey: string}
         * @throws \InvalidArgumentException
         * @throws \RuntimeException
         */
        public static function finishLogin(#[\SensitiveParameter] array $params): array {}
    }
}

namespace {
    // Low-level primitives remain available. Prefer the associative-array class
    // API above, whose parameters carry native SensitiveParameter metadata.
    function opaque_create_setup(): string {}
    function opaque_get_public_key(string $server_setup): string {}

    /** @return array{string, string} Client state and registration request. */
    function opaque_start_client_registration(string $password): array {}

    function opaque_create_registration_response(string $server_setup, string $user_identifier, string $registration_request): string {}

    /** @return array{string, string, string} Registration record, export key, and server public key. */
    function opaque_finish_client_registration(string $client_state, string $registration_response, string $password, ?string $options_json = null): array {}

    /** @return array{string, string} Client state and login request. */
    function opaque_start_client_login(string $password): array {}

    /** @return array{string, string} Server state and login response. */
    function opaque_start_server_login(string $server_setup, ?string $registration_record, string $start_login_request, string $user_identifier, ?string $options_json = null): array {}

    /** @return array{string, string, string, string}|null Finalization, session key, export key, and server public key. */
    function opaque_finish_client_login(string $client_state, string $login_response, string $password, ?string $options_json = null): ?array {}

    function opaque_finish_server_login(string $server_state, string $finish_login_request, ?string $options_json = null): string {}
}
