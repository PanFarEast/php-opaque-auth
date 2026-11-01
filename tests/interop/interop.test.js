import assert from 'node:assert/strict';
import { spawn } from 'node:child_process';
import { once } from 'node:events';
import { createInterface } from 'node:readline';
import { test } from 'node:test';
import { client as jsClient, ready, server as jsServer } from '@serenity-kit/opaque';

await ready;

const php = process.env.PHP_BINARY || 'php';
const extension = process.env.OPAQUE_EXTENSION;
if (!extension) throw new Error('Set OPAQUE_EXTENSION to the built extension path');
const worker = spawn(php, ['-n', '-d', 'display_errors=0', '-d', 'zend.exception_ignore_args=1', '-d', `extension=${extension}`, new URL('./php-worker.php', import.meta.url).pathname], {
  stdio: ['pipe', 'pipe', 'ignore'],
});
const lines = createInterface({ input: worker.stdout });
const responses = lines[Symbol.asyncIterator]();
let workerError;
const spawnFailure = new Promise((resolve) => worker.once('error', () => {
  workerError = true;
  resolve({ failedToSpawn: true });
}));
let workerExited = false;
worker.on('exit', () => { workerExited = true; });
worker.stdin.on('error', () => { workerError = true; });
const workerTermination = once(worker, 'exit').catch(() => undefined);

async function call(className, method, args = {}) {
  assert.ok(!workerError && !workerExited && !worker.stdin.destroyed, 'PHP worker is unavailable');
  try {
    worker.stdin.write(`${JSON.stringify({ class: className, method, args })}\n`);
  } catch {
    assert.fail('PHP worker could not accept a request');
  }
  const nextResponse = await Promise.race([responses.next(), spawnFailure]);
  assert.ok(!nextResponse.failedToSpawn, 'PHP worker could not be started');
  const { value, done } = nextResponse;
  assert.ok(!done, 'PHP worker exited before responding');
  let response;
  try {
    response = JSON.parse(value);
  } catch {
    assert.fail('PHP worker returned an invalid response');
  }
  assert.ok(response.ok, 'PHP public API operation failed');
  return response.result;
}

function assertEncoded(value) {
  assert.ok(typeof value === 'string' && /^[A-Za-z0-9_-]+$/.test(value), 'protocol value must be URL-safe unpadded Base64');
}

function assertAllEncoded(result) {
  const values = typeof result === 'string' ? [result] : Object.values(result);
  assert.ok(values.length > 0, 'protocol operation must return values');
  for (const value of values) assertEncoded(value);
}

async function phpRegistrationAndLogin(keyStretching, identifiers) {
  const setup = await call('server', 'createSetup');
  const publicKey = await call('server', 'getPublicKey', { serverSetup: setup });
  assertAllEncoded(setup);
  assertAllEncoded(publicKey);
  const start = await call('client', 'startRegistration', { password: 'cross-runtime-test-password' });
  assertAllEncoded(start);
  const response = jsServer.createRegistrationResponse({
    serverSetup: setup,
    userIdentifier: 'interop-user',
    registrationRequest: start.registrationRequest,
  });
  assertAllEncoded(response);
  const registration = await call('client', 'finishRegistration', {
    clientRegistrationState: start.clientRegistrationState,
    registrationResponse: response.registrationResponse,
    password: 'cross-runtime-test-password',
    ...(identifiers ? { identifiers } : {}),
    ...(keyStretching ? { keyStretching } : {}),
  });
  assertAllEncoded(registration);
  const loginStart = await call('client', 'startLogin', { password: 'cross-runtime-test-password' });
  assertAllEncoded(loginStart);
  const loginResponse = jsServer.startLogin({
    serverSetup: setup,
    registrationRecord: registration.registrationRecord,
    startLoginRequest: loginStart.startLoginRequest,
    userIdentifier: 'interop-user',
    ...(identifiers ? { identifiers } : {}),
  });
  assertAllEncoded(loginResponse);
  const loginFinish = await call('client', 'finishLogin', {
    clientLoginState: loginStart.clientLoginState,
    loginResponse: loginResponse.loginResponse,
    password: 'cross-runtime-test-password',
    ...(identifiers ? { identifiers } : {}),
    ...(keyStretching ? { keyStretching } : {}),
  });
  assertAllEncoded(loginFinish);
  const serverFinish = jsServer.finishLogin({
    serverLoginState: loginResponse.serverLoginState,
    finishLoginRequest: loginFinish.finishLoginRequest,
    ...(identifiers ? { identifiers } : {}),
  });
  assertAllEncoded(serverFinish);
  assert.ok(loginFinish.sessionKey === serverFinish.sessionKey, 'PHP and JS session keys must match');
  assert.ok(registration.exportKey === loginFinish.exportKey, 'PHP export key must persist across login');
  assert.ok(registration.serverStaticPublicKey === publicKey, 'registration public key must match server setup');
}

test('PHP client interoperates with the JS server using default KSF', { timeout: 60_000 }, async () => {
  await phpRegistrationAndLogin(undefined, undefined);
});

test('PHP client interoperates with JS using custom identifiers and KSF', { timeout: 60_000 }, async () => {
  await phpRegistrationAndLogin(
    { 'argon2id-custom': { iterations: 1, memory: 32, parallelism: 1 } },
    { client: 'php-client', server: 'js-server' },
  );
});

async function jsRegistrationAndLogin(identifiers) {
  const setup = await call('server', 'createSetup');
  const publicKey = await call('server', 'getPublicKey', { serverSetup: setup });
  assertAllEncoded(setup);
  assertAllEncoded(publicKey);
  const start = jsClient.startRegistration({ password: 'reverse-cross-runtime-password' });
  assertAllEncoded(start);
  const response = await call('server', 'createRegistrationResponse', {
    serverSetup: setup,
    userIdentifier: 'interop-user',
    registrationRequest: start.registrationRequest,
  });
  assertAllEncoded(response);
  const registration = jsClient.finishRegistration({
    clientRegistrationState: start.clientRegistrationState,
    registrationResponse: response.registrationResponse,
    password: 'reverse-cross-runtime-password',
    ...(identifiers ? { identifiers } : {}),
  });
  assertAllEncoded(registration);
  const loginStart = jsClient.startLogin({ password: 'reverse-cross-runtime-password' });
  assertAllEncoded(loginStart);
  const loginResponse = await call('server', 'startLogin', {
    serverSetup: setup,
    registrationRecord: registration.registrationRecord,
    startLoginRequest: loginStart.startLoginRequest,
    userIdentifier: 'interop-user',
    ...(identifiers ? { identifiers } : {}),
  });
  assertAllEncoded(loginResponse);
  const clientFinish = jsClient.finishLogin({
    clientLoginState: loginStart.clientLoginState,
    loginResponse: loginResponse.loginResponse,
    password: 'reverse-cross-runtime-password',
    ...(identifiers ? { identifiers } : {}),
  });
  assert.ok(clientFinish, 'valid password must produce a client login result');
  assertAllEncoded(clientFinish);
  const serverFinish = await call('server', 'finishLogin', {
    serverLoginState: loginResponse.serverLoginState,
    finishLoginRequest: clientFinish.finishLoginRequest,
  });
  assertAllEncoded(serverFinish);
  assert.ok(clientFinish.sessionKey === serverFinish.sessionKey, 'JS and PHP session keys must match');
  assert.ok(registration.exportKey === clientFinish.exportKey, 'JS export key must persist across login');
  assert.ok(registration.serverStaticPublicKey === publicKey, 'registration public key must match server setup');
}

test('JS client interoperates with the PHP server using default identifiers', { timeout: 60_000 }, async () => {
  await jsRegistrationAndLogin(undefined);
});

test('JS client interoperates with the PHP server using custom identifiers', { timeout: 60_000 }, async () => {
  await jsRegistrationAndLogin({ client: 'js-client', server: 'php-server' });
});

test('PHP server rejects wrong-password client authentication without disclosing protocol data', { timeout: 60_000 }, async () => {
  const setup = await call('server', 'createSetup');
  const start = jsClient.startRegistration({ password: 'correct-password' });
  const response = await call('server', 'createRegistrationResponse', {
    serverSetup: setup,
    userIdentifier: 'interop-user',
    registrationRequest: start.registrationRequest,
  });
  const registration = jsClient.finishRegistration({
    clientRegistrationState: start.clientRegistrationState,
    registrationResponse: response.registrationResponse,
    password: 'correct-password',
  });
  const loginStart = jsClient.startLogin({ password: 'wrong-password' });
  const loginResponse = await call('server', 'startLogin', {
    serverSetup: setup,
    registrationRecord: registration.registrationRecord,
    startLoginRequest: loginStart.startLoginRequest,
    userIdentifier: 'interop-user',
  });
  const result = jsClient.finishLogin({
    clientLoginState: loginStart.clientLoginState,
    loginResponse: loginResponse.loginResponse,
    password: 'wrong-password',
  });
  assert.ok(result === undefined, 'wrong password must not authenticate');
});

test('PHP client returns null for a wrong password against the JS server', { timeout: 60_000 }, async () => {
  const setup = await call('server', 'createSetup');
  const start = await call('client', 'startRegistration', { password: 'correct-password' });
  const response = jsServer.createRegistrationResponse({
    serverSetup: setup,
    userIdentifier: 'interop-user',
    registrationRequest: start.registrationRequest,
  });
  const registration = await call('client', 'finishRegistration', {
    clientRegistrationState: start.clientRegistrationState,
    registrationResponse: response.registrationResponse,
    password: 'correct-password',
  });
  const loginStart = await call('client', 'startLogin', { password: 'wrong-password' });
  const loginResponse = jsServer.startLogin({
    serverSetup: setup,
    registrationRecord: registration.registrationRecord,
    startLoginRequest: loginStart.startLoginRequest,
    userIdentifier: 'interop-user',
  });
  const result = await call('client', 'finishLogin', {
    clientLoginState: loginStart.clientLoginState,
    loginResponse: loginResponse.loginResponse,
    password: 'wrong-password',
  });
  assert.ok(result === null, 'wrong password must return null from the PHP facade');
});

test('PHP client and JS server reject mismatched identifiers', { timeout: 60_000 }, async () => {
  const setup = await call('server', 'createSetup');
  const identifiers = { client: 'expected-client', server: 'expected-server' };
  const start = await call('client', 'startRegistration', { password: 'identifier-test-password' });
  const response = jsServer.createRegistrationResponse({
    serverSetup: setup,
    userIdentifier: 'interop-user',
    registrationRequest: start.registrationRequest,
  });
  const registration = await call('client', 'finishRegistration', {
    clientRegistrationState: start.clientRegistrationState,
    registrationResponse: response.registrationResponse,
    password: 'identifier-test-password',
    identifiers,
  });
  const loginStart = await call('client', 'startLogin', { password: 'identifier-test-password' });
  const loginResponse = jsServer.startLogin({
    serverSetup: setup,
    registrationRecord: registration.registrationRecord,
    startLoginRequest: loginStart.startLoginRequest,
    userIdentifier: 'interop-user',
    identifiers: { client: 'other-client', server: 'other-server' },
  });
  const result = await call('client', 'finishLogin', {
    clientLoginState: loginStart.clientLoginState,
    loginResponse: loginResponse.loginResponse,
    password: 'identifier-test-password',
    identifiers,
  });
  assert.ok(result === null, 'mismatched identifiers must not authenticate');
});

test('JS client and PHP server reject mismatched identifiers', { timeout: 60_000 }, async () => {
  const setup = await call('server', 'createSetup');
  const identifiers = { client: 'expected-client', server: 'expected-server' };
  const start = jsClient.startRegistration({ password: 'identifier-test-password' });
  const response = await call('server', 'createRegistrationResponse', {
    serverSetup: setup,
    userIdentifier: 'interop-user',
    registrationRequest: start.registrationRequest,
  });
  const registration = jsClient.finishRegistration({
    clientRegistrationState: start.clientRegistrationState,
    registrationResponse: response.registrationResponse,
    password: 'identifier-test-password',
    identifiers,
  });
  const loginStart = jsClient.startLogin({ password: 'identifier-test-password' });
  const loginResponse = await call('server', 'startLogin', {
    serverSetup: setup,
    registrationRecord: registration.registrationRecord,
    startLoginRequest: loginStart.startLoginRequest,
    userIdentifier: 'interop-user',
    identifiers: { client: 'other-client', server: 'other-server' },
  });
  const result = jsClient.finishLogin({
    clientLoginState: loginStart.clientLoginState,
    loginResponse: loginResponse.loginResponse,
    password: 'identifier-test-password',
    identifiers,
  });
  assert.ok(result === undefined, 'mismatched identifiers must not authenticate');
});

test('PHP and JS reject a key-stretching object with multiple variants', { timeout: 60_000 }, async () => {
  const setup = jsServer.createSetup();
  const keyStretching = {
    'argon2id-custom': { memory: 32, iterations: 1, parallelism: 1 },
    unsupported: true,
  };
  const jsStart = jsClient.startRegistration({ password: 'configuration-test-password' });
  const jsResponse = jsServer.createRegistrationResponse({
    serverSetup: setup,
    userIdentifier: 'interop-user',
    registrationRequest: jsStart.registrationRequest,
  });
  assert.throws(() => jsClient.finishRegistration({
    ...jsStart, ...jsResponse, password: 'configuration-test-password', keyStretching,
  }), 'JS must reject multiple KSF variants');
  const phpStart = await call('client', 'startRegistration', { password: 'configuration-test-password' });
  const phpResponse = jsServer.createRegistrationResponse({
    serverSetup: setup,
    userIdentifier: 'interop-user',
    registrationRequest: phpStart.registrationRequest,
  });
  await assert.rejects(call('client', 'finishRegistration', {
    ...phpStart, ...phpResponse, password: 'configuration-test-password', keyStretching,
  }), 'PHP must reject multiple KSF variants');
});

test.after(async () => {
  if (!workerExited) {
    worker.stdin.end();
    worker.kill('SIGKILL');
  }
  await workerTermination;
});
