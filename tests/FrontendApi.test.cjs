const { test } = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');

function client(fetch) {
  const source = fs.readFileSync(require('node:path').join(__dirname, '../../api.js'), 'utf8')
    .replace(/^import .*;$/gm, '').replace(/export /g, '');
  const context = vm.createContext({ fetch, FormData, AbortController, setTimeout, clearTimeout,
    Platform: { OS: 'android' }, NativeModules: {}, process: { env: { EXPO_PUBLIC_API_URL: 'http://192.168.1.5:8000/api' } } });
  return vm.runInContext(source + '\n({api, session, mapEvent, mediaUrl})', context);
}

test('authenticated JSON requests carry the token and payload', async () => {
  let request;
  const c = client(async (url, options) => { request = { url, options }; return { ok: true, json: async () => ({ saved: true }) }; });
  c.session.set('test-token', { username: 'test' });
  const result = await c.api('events/5/save', { method: 'POST', body: { value: true } });
  assert.equal(result.saved, true);
  assert.equal(request.url, 'http://192.168.1.5:8000/api/events/5/save');
  assert.equal(request.options.headers.Authorization, 'Bearer test-token');
  assert.equal(request.options.body, '{"value":true}');
});

test('validation errors reach the screen and expired tokens clear the session', async () => {
  const c = client(async () => ({ ok: false, status: 422, json: async () => ({ errors: { email: ['Already registered.'] } }) }));
  await assert.rejects(c.api('auth/register'), /Already registered/);
  const expired = client(async () => ({ ok: false, status: 401, json: async () => ({ message: 'Unauthenticated.' }) }));
  expired.session.set('expired', { username: 'test' });
  let notified = false;
  expired.session.subscribe(user => { notified = user === null; });
  await assert.rejects(expired.api('profile'), /Unauthenticated/);
  assert.equal(expired.session.user, null);
  assert.equal(notified, true);
});

test('event resources map host fields and device-reachable media', () => {
  const c = client();
  const event = c.mapEvent({ id: 5, host: { name: 'Host', username: 'host' }, image: 'http://localhost/storage/photo.jpg', likes_count: 7 });
  assert.equal(event.id, '5');
  assert.equal(event.hostUsername, 'host');
  assert.equal(event.image.uri, 'http://192.168.1.5:8000/storage/photo.jpg');
  assert.equal(event.likes, 7);
  assert.equal(c.mediaUrl('/storage/avatar.jpg'), 'http://192.168.1.5:8000/storage/avatar.jpg');
  assert.equal(c.mediaUrl('https://cdn.example.com/image.jpg'), 'https://cdn.example.com/image.jpg');
});

test('multipart uploads preserve FormData and browser-selected boundary', async () => {
  let options;
  const c = client(async (_, value) => { options = value; return { ok: true, json: async () => ({}) }; });
  const form = new FormData(); form.append('account_type', 'ngo');
  await c.api('verification-requests', { method: 'POST', body: form });
  assert.equal(options.body, form);
  assert.equal(options.headers['Content-Type'], undefined);
});
