#![cfg_attr(windows, feature(abi_vectorcall))]

//! Native protocol primitives for the PHP OPAQUE API.
//!
//! This crate intentionally leaves persistence, session lifecycle, and account
//! management to the embedding PHP application.

use argon2::{Algorithm, Argon2, Params, Version};
use base64::{Engine, engine::general_purpose::URL_SAFE_NO_PAD};
use ext_php_rs::prelude::*;
use opaque_ke::{
    ClientLogin, ClientLoginFinishParameters, ClientRegistration,
    ClientRegistrationFinishParameters, CredentialFinalization, CredentialRequest,
    CredentialResponse, Identifiers, RegistrationRequest, RegistrationResponse, ServerLogin,
    ServerLoginParameters, ServerRegistration, ServerSetup, ciphersuite::CipherSuite,
    errors::InternalError, ksf::Ksf,
};
use rand::rngs::OsRng;
use sha2::Sha512;

struct Serenity;
impl CipherSuite for Serenity {
    type OprfCs = opaque_ke::Ristretto255;
    type KeyExchange = opaque_ke::TripleDh<opaque_ke::Ristretto255, Sha512>;
    type Ksf = SerenityKsf;
}

/// Serenity Kit's default memory-constrained Argon2id configuration.
struct SerenityKsf(Argon2<'static>);
impl Default for SerenityKsf {
    fn default() -> Self {
        Self::configured(None).expect("fixed Argon2 parameters")
    }
}
impl SerenityKsf {
    fn configured(config: Option<&serde_json::Value>) -> OpaqueResult<Self> {
        let mut memory = 1 << 16;
        let mut iterations = 3;
        let mut parallelism = 4;
        if let Some(config) = config {
            match config {
                serde_json::Value::String(value) if value == "memory-constrained" => {}
                serde_json::Value::String(value)
                    if value == "rfc-recommended" || value == "rfc-draft-recommended" =>
                {
                    memory = 2_097_151;
                    iterations = 1;
                }
                serde_json::Value::Object(object) => {
                    let custom = object
                        .get("argon2id-custom")
                        .and_then(serde_json::Value::as_object)
                        .ok_or_else(|| "Invalid keyStretching option".to_owned())?;
                    memory = custom
                        .get("memory")
                        .and_then(serde_json::Value::as_u64)
                        .and_then(|v| u32::try_from(v).ok())
                        .ok_or_else(|| "Invalid keyStretching option".to_owned())?;
                    iterations = custom
                        .get("iterations")
                        .and_then(serde_json::Value::as_u64)
                        .and_then(|v| u32::try_from(v).ok())
                        .ok_or_else(|| "Invalid keyStretching option".to_owned())?;
                    parallelism = custom
                        .get("parallelism")
                        .and_then(serde_json::Value::as_u64)
                        .and_then(|v| u32::try_from(v).ok())
                        .ok_or_else(|| "Invalid keyStretching option".to_owned())?;
                }
                _ => return Err("Invalid keyStretching option".to_owned()),
            }
        }
        let params = Params::new(memory, iterations, parallelism, None)
            .map_err(|_| "Invalid keyStretching parameters".to_owned())?;
        Ok(Self(Argon2::new(
            Algorithm::Argon2id,
            Version::V0x13,
            params,
        )))
    }
}
impl Ksf for SerenityKsf {
    fn hash<L: generic_array::ArrayLength<u8>>(
        &self,
        input: generic_array::GenericArray<u8, L>,
    ) -> Result<generic_array::GenericArray<u8, L>, InternalError> {
        let mut output = generic_array::GenericArray::default();
        self.0
            .hash_password_into(&input, &[0; argon2::RECOMMENDED_SALT_LEN], &mut output)
            .map_err(|_| InternalError::KsfError)?;
        Ok(output)
    }
}

type OpaqueResult<T> = Result<T, String>;
fn decode(value: &str, field: &str) -> OpaqueResult<Vec<u8>> {
    URL_SAFE_NO_PAD
        .decode(value)
        .map_err(|_| format!("Invalid URL-safe base64 in {field}"))
}
fn encode(value: impl AsRef<[u8]>) -> String {
    URL_SAFE_NO_PAD.encode(value)
}
fn options(json: Option<&str>) -> OpaqueResult<serde_json::Value> {
    json.map(serde_json::from_str)
        .transpose()
        .map_err(|_| "Invalid options".to_owned())
        .map(|v| v.unwrap_or_else(|| serde_json::json!({})))
}
fn identifiers(value: &serde_json::Value) -> OpaqueResult<Identifiers<'_>> {
    let Some(value) = value.get("identifiers") else {
        return Ok(Identifiers {
            client: None,
            server: None,
        });
    };
    let object = value
        .as_object()
        .ok_or_else(|| "Invalid identifiers".to_owned())?;
    let client = object
        .get("client")
        .map(|v| {
            v.as_str()
                .map(str::as_bytes)
                .ok_or_else(|| "Invalid identifiers".to_owned())
        })
        .transpose()?;
    let server = object
        .get("server")
        .map(|v| {
            v.as_str()
                .map(str::as_bytes)
                .ok_or_else(|| "Invalid identifiers".to_owned())
        })
        .transpose()?;
    Ok(Identifiers { client, server })
}
fn ksf_config(value: &serde_json::Value) -> OpaqueResult<SerenityKsf> {
    SerenityKsf::configured(value.get("keyStretching"))
}

#[php_function]
pub fn opaque_create_setup() -> String {
    let setup = ServerSetup::<Serenity>::new(&mut OsRng);
    encode(setup.serialize())
}

#[php_function]
pub fn opaque_get_public_key(server_setup: String) -> OpaqueResult<String> {
    let bytes = decode(&server_setup, "serverSetup")?;
    let setup = ServerSetup::<Serenity>::deserialize(&bytes)
        .map_err(|_| "Invalid server setup".to_owned())?;
    Ok(encode(setup.keypair().public().serialize()))
}

#[php_function]
pub fn opaque_start_client_registration(password: String) -> OpaqueResult<Vec<String>> {
    let result = ClientRegistration::<Serenity>::start(&mut OsRng, password.as_bytes())
        .map_err(|_| "Unable to start registration".to_owned())?;
    Ok(vec![
        encode(result.state.serialize()),
        encode(result.message.serialize()),
    ])
}

#[php_function]
pub fn opaque_create_registration_response(
    server_setup: String,
    user_identifier: String,
    registration_request: String,
) -> OpaqueResult<String> {
    let setup_bytes = decode(&server_setup, "serverSetup")?;
    let setup = ServerSetup::<Serenity>::deserialize(&setup_bytes)
        .map_err(|_| "Invalid server setup".to_owned())?;
    let request_bytes = decode(&registration_request, "registrationRequest")?;
    let request = RegistrationRequest::<Serenity>::deserialize(&request_bytes)
        .map_err(|_| "Invalid registration request".to_owned())?;
    let result = ServerRegistration::<Serenity>::start(&setup, request, user_identifier.as_bytes())
        .map_err(|_| "Unable to create registration response".to_owned())?;
    Ok(encode(result.message.serialize()))
}

#[php_function]
pub fn opaque_finish_client_registration(
    client_state: String,
    registration_response: String,
    password: String,
    options_json: Option<String>,
) -> OpaqueResult<Vec<String>> {
    let options = options(options_json.as_deref())?;
    let ksf = ksf_config(&options)?;
    let identifiers = identifiers(&options)?;
    let state_bytes = decode(&client_state, "clientRegistrationState")?;
    let response_bytes = decode(&registration_response, "registrationResponse")?;
    let state = ClientRegistration::<Serenity>::deserialize(&state_bytes)
        .map_err(|_| "Invalid client registration state".to_owned())?;
    let response = RegistrationResponse::<Serenity>::deserialize(&response_bytes)
        .map_err(|_| "Invalid registration response".to_owned())?;
    let result = state
        .finish(
            &mut OsRng,
            password.as_bytes(),
            response,
            ClientRegistrationFinishParameters::new(identifiers, Some(&ksf)),
        )
        .map_err(|_| "Unable to finish registration".to_owned())?;
    Ok(vec![
        encode(result.message.serialize()),
        encode(result.export_key),
        encode(result.server_s_pk.serialize()),
    ])
}

#[php_function]
pub fn opaque_start_client_login(password: String) -> OpaqueResult<Vec<String>> {
    let result = ClientLogin::<Serenity>::start(&mut OsRng, password.as_bytes())
        .map_err(|_| "Unable to start login".to_owned())?;
    Ok(vec![
        encode(result.state.serialize()),
        encode(result.message.serialize()),
    ])
}

#[php_function]
pub fn opaque_start_server_login(
    server_setup: String,
    registration_record: Option<String>,
    start_login_request: String,
    user_identifier: String,
    options_json: Option<String>,
) -> OpaqueResult<Vec<String>> {
    let options = options(options_json.as_deref())?;
    let setup = ServerSetup::<Serenity>::deserialize(&decode(&server_setup, "serverSetup")?)
        .map_err(|_| "Invalid server setup".to_owned())?;
    let registration = registration_record
        .map(|record| {
            let bytes = decode(&record, "registrationRecord")?;
            ServerRegistration::<Serenity>::deserialize(&bytes)
                .map_err(|_| "Invalid registration record".to_owned())
        })
        .transpose()?;
    let request = CredentialRequest::<Serenity>::deserialize(&decode(
        &start_login_request,
        "startLoginRequest",
    )?)
    .map_err(|_| "Invalid login request".to_owned())?;
    let result = ServerLogin::<Serenity>::start(
        &mut OsRng,
        &setup,
        registration,
        request,
        user_identifier.as_bytes(),
        ServerLoginParameters {
            context: None,
            identifiers: identifiers(&options)?,
        },
    )
    .map_err(|_| "Unable to start server login".to_owned())?;
    Ok(vec![
        encode(result.state.serialize()),
        encode(result.message.serialize()),
    ])
}

#[php_function]
pub fn opaque_finish_client_login(
    client_state: String,
    login_response: String,
    password: String,
    options_json: Option<String>,
) -> OpaqueResult<Option<Vec<String>>> {
    let options = options(options_json.as_deref())?;
    let ksf = ksf_config(&options)?;
    let identifiers = identifiers(&options)?;
    let state = ClientLogin::<Serenity>::deserialize(&decode(&client_state, "clientLoginState")?)
        .map_err(|_| "Invalid client login state".to_owned())?;
    let response =
        CredentialResponse::<Serenity>::deserialize(&decode(&login_response, "loginResponse")?)
            .map_err(|_| "Invalid login response".to_owned())?;
    let result = state.finish(
        &mut OsRng,
        password.as_bytes(),
        response,
        ClientLoginFinishParameters::new(None, identifiers, Some(&ksf)),
    );
    let Ok(result) = result else {
        return Ok(None);
    };
    Ok(Some(vec![
        encode(result.message.serialize()),
        encode(result.session_key),
        encode(result.export_key),
        encode(result.server_s_pk.serialize()),
    ]))
}

#[php_function]
pub fn opaque_finish_server_login(
    server_state: String,
    finish_login_request: String,
    options_json: Option<String>,
) -> OpaqueResult<String> {
    let options = options(options_json.as_deref())?;
    let state = ServerLogin::<Serenity>::deserialize(&decode(&server_state, "serverLoginState")?)
        .map_err(|_| "Invalid server login state".to_owned())?;
    let finish = CredentialFinalization::<Serenity>::deserialize(&decode(
        &finish_login_request,
        "finishLoginRequest",
    )?)
    .map_err(|_| "Invalid login finalization".to_owned())?;
    let result = state
        .finish(
            finish,
            ServerLoginParameters {
                context: None,
                identifiers: identifiers(&options)?,
            },
        )
        .map_err(|_| "Unable to finish server login".to_owned())?;
    Ok(encode(result.session_key))
}

#[php_module]
pub fn get_module(module: ModuleBuilder) -> ModuleBuilder {
    module
        .function(wrap_function!(opaque_create_setup))
        .function(wrap_function!(opaque_get_public_key))
        .function(wrap_function!(opaque_start_client_registration))
        .function(wrap_function!(opaque_create_registration_response))
        .function(wrap_function!(opaque_finish_client_registration))
        .function(wrap_function!(opaque_start_client_login))
        .function(wrap_function!(opaque_start_server_login))
        .function(wrap_function!(opaque_finish_client_login))
        .function(wrap_function!(opaque_finish_server_login))
}
