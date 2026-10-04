#!/usr/bin/env node
const assert = require('node:assert/strict');
const openAdminSession = require('./support/settings-http-session');
const base = 'http://localhost:8888';
function result(status, location) { return {response: {status, headers: new Headers(location ? {location} : {})}}; }
(async () => {
  let calls = [];
  await openAdminSession(async (method, target) => {calls.push([method, target]); return result(200);}, base);
  assert.deepEqual(calls, [['GET', '/wp-admin/']]);
  const sequence = [result(302, '/wp-admin/admin.php?page=wc-admin'), result(302, '/wp-admin/admin.php?page=wc-admin&path=%2Fsetup-wizard'), result(200)];
  calls = [];
  await openAdminSession(async (method, target) => {calls.push([method, target]); return sequence.shift();}, base);
  assert.equal(calls.length, 3); assert.ok(calls.every(([method]) => method === 'GET'));
  for (const redirect of ['https://other.test/wp-admin/', '/wp-login.php', '/wp-admin/admin.php?page=unexpected', '/wp-admin/admin.php?page=wc-admin&path=%2Fpayments']) {
    await assert.rejects(openAdminSession(async () => result(302, redirect), base));
  }
  await assert.rejects(openAdminSession(async () => result(403), base));
  let count = 0;
  await assert.rejects(openAdminSession(async () => {count++; return result(302, '/wp-admin/');}, base), /redirect limit exceeded/);
  assert.equal(count, 4);
  console.log('PASS: native admin session navigation, onboarding, origin/surface boundaries and redirect limit');
})().catch(error => {console.error(error); process.exit(1);});
