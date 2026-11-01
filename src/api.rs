//! Native public PHP API; protocol operations remain in the crate root.

// Preserve the existing PHP named argument `serverSetup`, including in macro-
// generated wrappers.
#![allow(non_snake_case)]

use ext_php_rs::{
    boxed::ZBox,
    exception::PhpException,
    flags::ClassFlags,
    prelude::*,
    types::{ZendCallable, ZendHashTable, Zval},
    zend::{ClassEntry, ExecutorGlobals},
};

use crate::*;

type Fields = ZBox<ZendHashTable>;
type ApiResult<T> = Result<T, PhpException>;

fn invalid(message: impl Into<String>) -> PhpException {
    PhpException::new(
        message.into(),
        0,
        ClassEntry::try_find("InvalidArgumentException").expect("SPL exception"),
    )
}

fn protocol<T>(result: OpaqueResult<T>) -> ApiResult<T> {
    result.map_err(|message| {
        PhpException::new(
            message,
            0,
            ClassEntry::try_find("RuntimeException").expect("SPL exception"),
        )
    })
}

fn required(params: &ZendHashTable, key: &str) -> ApiResult<String> {
    params
        .get(key)
        .and_then(Zval::string)
        .ok_or_else(|| invalid(format!("Missing or invalid parameter: {key}")))
}

fn fields<const N: usize>(names: [&'static str; N], values: Vec<String>) -> Fields {
    let mut result = ZendHashTable::new();
    for (name, value) in names.into_iter().zip(values) {
        result.insert(name, value).expect("fixed API field name");
    }
    result
}

// Use PHP's JSON conversion to retain the facade's PHP array/object semantics.
// Discard conversion exceptions rather than retaining their raw argument traces.
fn json(value: &Zval, flags: i64) -> ApiResult<serde_json::Value> {
    let result = ZendCallable::try_from_name("json_encode")
        .and_then(|encode| encode.try_call(vec![value, &flags]));
    if result.is_err() {
        drop(ExecutorGlobals::take_exception());
    }
    result
        .ok()
        .and_then(|value| value.string())
        .and_then(|value| serde_json::from_str(&value).ok())
        .ok_or_else(|| invalid("Invalid options"))
}

fn client_options(params: &ZendHashTable) -> ApiResult<Option<String>> {
    let mut options = serde_json::Map::new();
    if let Some(ids) = params.get("identifiers") {
        if ids.array().is_none() {
            return Err(invalid("Invalid identifiers"));
        }
        options.insert("identifiers".into(), json(ids, 16)?);
    }
    if let Some(ksf) = params.get("keyStretching") {
        options.insert("keyStretching".into(), json(ksf, 0)?);
    }
    Ok(Some(serde_json::Value::Object(options).to_string()))
}

fn server_options(params: &ZendHashTable) -> ApiResult<Option<String>> {
    let ids = match params.get("identifiers") {
        Some(ids) => {
            if ids.array().is_none() {
                return Err(invalid("Invalid identifiers"));
            }
            json(ids, 16)?
        }
        None => serde_json::json!({}),
    };
    Ok(Some(serde_json::json!({"identifiers": ids}).to_string()))
}

#[php_class]
#[php(name = "Opaque\\Client", flags = ClassFlags::Final)]
pub struct Client;

#[php_impl]
impl Client {
    pub fn start_login(params: &ZendHashTable) -> ApiResult<Fields> {
        Ok(fields(
            ["clientLoginState", "startLoginRequest"],
            protocol(opaque_start_client_login(required(params, "password")?))?,
        ))
    }

    pub fn finish_login(params: &ZendHashTable) -> ApiResult<Option<Fields>> {
        let state = required(params, "clientLoginState")?;
        let response = required(params, "loginResponse")?;
        let password = required(params, "password")?;
        let options = client_options(params)?;
        Ok(protocol(opaque_finish_client_login(
            state, response, password, options,
        ))?
        .map(|values| {
            fields(
                [
                    "finishLoginRequest",
                    "sessionKey",
                    "exportKey",
                    "serverStaticPublicKey",
                ],
                values,
            )
        }))
    }

    pub fn start_registration(params: &ZendHashTable) -> ApiResult<Fields> {
        Ok(fields(
            ["clientRegistrationState", "registrationRequest"],
            protocol(opaque_start_client_registration(required(
                params, "password",
            )?))?,
        ))
    }

    pub fn finish_registration(params: &ZendHashTable) -> ApiResult<Fields> {
        let state = required(params, "clientRegistrationState")?;
        let response = required(params, "registrationResponse")?;
        let password = required(params, "password")?;
        let options = client_options(params)?;
        Ok(fields(
            ["registrationRecord", "exportKey", "serverStaticPublicKey"],
            protocol(opaque_finish_client_registration(
                state, response, password, options,
            ))?,
        ))
    }
}

#[php_class]
#[php(name = "Opaque\\Server", flags = ClassFlags::Final)]
pub struct Server;

#[php_impl]
impl Server {
    pub fn start_login(params: &ZendHashTable) -> ApiResult<Fields> {
        let setup = required(params, "serverSetup")?;
        let record = match params.get("registrationRecord") {
            Some(value) if !value.is_null() => Some(
                value
                    .string()
                    .ok_or_else(|| invalid("Invalid registrationRecord"))?,
            ),
            _ => None,
        };
        let request = required(params, "startLoginRequest")?;
        let user = required(params, "userIdentifier")?;
        let options = server_options(params)?;
        Ok(fields(
            ["serverLoginState", "loginResponse"],
            protocol(opaque_start_server_login(
                setup, record, request, user, options,
            ))?,
        ))
    }

    pub fn finish_login(params: &ZendHashTable) -> ApiResult<Fields> {
        let state = required(params, "serverLoginState")?;
        let request = required(params, "finishLoginRequest")?;
        let options = server_options(params)?;
        Ok(fields(
            ["sessionKey"],
            vec![protocol(opaque_finish_server_login(
                state, request, options,
            ))?],
        ))
    }

    pub fn create_setup() -> String {
        opaque_create_setup()
    }

    #[php(name = "getPublicKey")]
    pub fn get_public_key(serverSetup: String) -> ApiResult<String> {
        protocol(opaque_get_public_key(serverSetup))
    }

    pub fn create_registration_response(params: &ZendHashTable) -> ApiResult<Fields> {
        let setup = required(params, "serverSetup")?;
        let user = required(params, "userIdentifier")?;
        let request = required(params, "registrationRequest")?;
        Ok(fields(
            ["registrationResponse"],
            vec![protocol(opaque_create_registration_response(
                setup, user, request,
            ))?],
        ))
    }
}
