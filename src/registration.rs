//! Register native classes and redact their protocol arguments at MINIT.

use ext_php_rs::{
    builders::ClassBuilder,
    class::RegisteredClass,
    ffi::{HashTable, zend_string},
    types::ZendStr,
};

// ext-php-rs does not yet expose Zend's parameter-attribute helper. This is the
// exported API underlying zend_add_parameter_attribute in PHP 8.3–8.5.
unsafe extern "C" {
    fn zend_add_attribute(
        attributes: *mut *mut HashTable,
        name: *mut zend_string,
        argc: u32,
        flags: u32,
        offset: u32,
        lineno: u32,
    ) -> *mut std::ffi::c_void;
}

fn register<T: RegisteredClass>() -> ext_php_rs::error::Result<()> {
    let mut builder = ClassBuilder::new(T::CLASS_NAME).flags(T::FLAGS);
    for (method, flags) in T::method_builders() {
        builder = builder.method(method, flags);
    }
    builder
        .registration(|ce, arg_info| {
            let name = ZendStr::new_interned("SensitiveParameter", true);
            for (_, method) in ce.function_table.iter() {
                // SAFETY: this table contains registered zend_function pointers.
                // MINIT runs before requests, so persistent metadata is exclusively
                // initialized here. Zend owns the attribute table and interned name.
                unsafe {
                    let function = method.value.func;
                    for offset in 0..(*function).common.num_args {
                        zend_add_attribute(
                            &raw mut (*function).common.attributes,
                            name.as_ptr().cast_mut(),
                            0,
                            1,          // ZEND_ATTRIBUTE_PERSISTENT
                            offset + 1, // Zend reserves offset zero for function attributes.
                            0,
                        );
                    }
                }
            }
            T::get_metadata().set_ce(ce);
            T::get_metadata().set_arg_info(arg_info);
        })
        .register()
}

pub extern "C" fn startup(_module_type: i32, _module_number: i32) -> i32 {
    // Never unwind across the PHP C ABI. Fail module startup if registration
    // cannot complete; request-time code must not see partially registered API.
    match std::panic::catch_unwind(|| {
        register::<crate::api::Client>()?;
        register::<crate::api::Server>()
    }) {
        Ok(Ok(())) => 0,
        _ => -1,
    }
}
