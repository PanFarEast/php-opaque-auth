PHP_ARG_ENABLE([opaque_auth], [whether to enable opaque_auth],
  [AS_HELP_STRING([--enable-opaque_auth], [Enable OPAQUE password authentication])], [yes])

if test "$PHP_OPAQUE_AUTH" != "no"; then
  OPAQUE_AUTH_ROOT=$(CDPATH= cd "$srcdir/.." && pwd)
  AC_MSG_CHECKING([for the OPAQUE Rust build prerequisites])
  if PHP_CONFIG="$PHP_PHP_CONFIG" sh "$OPAQUE_AUTH_ROOT/pie/build.sh" check; then
    AC_MSG_RESULT([yes])
  else
    AC_MSG_ERROR([OPAQUE build prerequisites are unavailable; see the error above])
  fi

  cat >>Makefile.objects<<EOF
.PHONY: opaque_auth_build
all: opaque_auth_build
build-modules: opaque_auth_build
opaque_auth_build:
	PHP_CONFIG="$PHP_PHP_CONFIG" sh "$OPAQUE_AUTH_ROOT/pie/build.sh" build "\$(phplibdir)"
EOF
fi
