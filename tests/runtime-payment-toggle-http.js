#!/usr/bin/env node
/* VST-92 native admin mutation/default/read-only contracts on the owned VST-90 fixture. */
const fs = require('node:fs');
const assert = require('node:assert/strict');
const openAdminSession = require('./support/settings-http-session');
const run = process.env.VST_TEST_RUN;
assert.match(run || '', /^vst90-[a-f0-9]{24}$/);
const fixture = JSON.parse(fs.readFileSync(`${__dirname}/fixtures/.vst90-${run}.php`, 'utf8').replace(/^<\?php exit; \?>\n/, ''));
assert.equal(fixture.run, run);
const base = fixture.base;
assert.ok(['localhost', '127.0.0.1', process.env.VST_TEST_HOST].filter(Boolean).includes(new URL(base).hostname));
const cookies = new Map();
async function request(method, target, body) {
  const url = new URL(target, base);
  assert.equal(url.origin, new URL(base).origin);
  const response = await fetch(url, {method, body, headers: {Cookie: [...cookies].map(([k,v]) => `${k}=${v}`).join('; ')}, redirect: 'manual'});
  for (const cookie of response.headers.getSetCookie()) { const first = cookie.split(';')[0]; const i = first.indexOf('='); cookies.set(first.slice(0,i), first.slice(i+1)); }
  return {response, text: await response.text()};
}
function nonce(html, name = '_wpnonce') {
  const match = html.match(new RegExp(`name="${name}"[^>]*value="([^"]+)"`));
  assert.ok(match, `Native nonce ${name}`);
  return match[1];
}
const option = 'yoohw_vietnam_store_tools_payment_reconciliation_enabled';
const box = 'id="yoohw-vietnam-store-tools-payment-reconciliation"';
const controls = 'name="vck_payment_operation"';
const form = 'id="vck-payment-reconciliation-form"';
const route = id => fixture.hpos ? `/wp-admin/admin.php?page=wc-orders&action=edit&id=${id}` : `/wp-admin/post.php?post=${id}&action=edit`;
async function dashboard() {
  const r = await request('GET', '/wp-admin/admin.php?page=yoohw-vietnam-store');
  assert.equal(r.response.status, 200);
  return r.text;
}
async function probe() {
  const html = await dashboard();
  const r = await request('POST', '/wp-admin/admin-ajax.php', new URLSearchParams({action:'vst85_settings_probe', _wpnonce:nonce(html)}));
  assert.equal(r.response.status, 200);
  return JSON.parse(r.text).data;
}
async function toggle(enabled) {
  const html = await dashboard();
  const body = new URLSearchParams({action:'yoohw_vietnam_store_tools_save_features', _wpnonce:nonce(html)});
  // Preserve every sibling control/value during this focused feature save.
  for (const tag of html.matchAll(/<input[^>]*>/g)) {
    const name = tag[0].match(/name="([^"]+)"/);
    const value = tag[0].match(/value="([^"]*)"/);
    if (name && value && !['action','_wpnonce','_wp_http_referer'].includes(name[1]) && !name[1].includes(option) && (!/type="checkbox"/.test(tag[0]) || /checked/.test(tag[0]))) body.set(name[1], value[1]);
  }
  for (const select of html.matchAll(/<select[^>]*name="([^"]+)"[^>]*>([\s\S]*?)<\/select>/g)) {
    const selected = select[2].match(/<option[^>]*value="([^"]+)"[^>]*selected/);
    if (selected) body.set(select[1], selected[1]);
  }
  if (enabled) body.set(`features[${option}]`, 'yes');
  const r = await request('POST', '/wp-admin/admin-post.php', body);
  assert.equal(r.response.status, 302);
  const reload = await dashboard();
  const tag = reload.match(new RegExp(`<input[^>]*id="${option}"[^>]*>`));
  assert.ok(tag);
  assert.equal(/checked/.test(tag[0]), enabled);
  assert.equal((await probe()).options[option], enabled ? 'yes' : 'no');
}
async function paymentPost(id, token, fields) {
  const r = await request('POST', '/wp-admin/admin-post.php', new URLSearchParams({action:'yoohw_vietnam_store_tools_reconcile_payment',vck_payment_order_id:String(id),vck_payment_nonce:token,...fields}));
  if (r.response.status === 302) return new URL(r.response.headers.get('location'), base).searchParams.get('vck_payment_notice');
  return r.response.status;
}
(async () => {
  await request('GET', '/wp-login.php');
  const login = await request('POST', '/wp-login.php', new URLSearchParams({log:fixture.login,pwd:fixture.password,testcookie:'1',redirect_to:`${base}/wp-admin/`}));
  assert.equal(login.response.status, 302, login.text.match(/<div id="login_error"[\s\S]*?<\/div>/)?.[0] || 'Native login redirect required');
  await openAdminSession(request, base);
  const initial = await probe();
  const initialHtml = await dashboard();
  assert.equal((initialHtml.match(new RegExp(`id="${option}"`, 'g')) || []).length, 1, 'One dedicated toggle');
  assert.equal(/checked/.test(initialHtml.match(new RegExp(`<input[^>]*id="${option}"[^>]*>`))[0]), false, 'Absent/default option is OFF');
  for (const order of initial.orders) assert.deepEqual(order.payment_history, [], 'Fresh owned fixture has no history');
  const [emptyId, historyId] = fixture.orders;
  const emptyPage = (await request('GET', route(emptyId))).text;
  assert.equal(emptyPage.includes(box), false);
  assert.equal(emptyPage.includes(form), false);
  await toggle(true);
  const onPage = (await request('GET', route(historyId))).text;
  assert.ok(onPage.includes(box) && onPage.includes(controls) && onPage.includes(form));
  const token = nonce(onPage, 'vck_payment_nonce');
  const emptyOnPage = (await request('GET', route(emptyId))).text;
  assert.ok(emptyOnPage.includes(box) && emptyOnPage.includes(form), 'ON empty BACS exposes manual workflow');
  const emptyToken = nonce(emptyOnPage, 'vck_payment_nonce');
  const evidence = {vck_payment_operation:'observe',vck_payment_amount:'250000',vck_payment_reference:run,vck_payment_observed_at:'2026-10-04T10:00'};
  assert.equal(await paymentPost(historyId, 'invalid', evidence), 403, 'Nonce enforced while ON');
  assert.equal(await paymentPost(historyId, token, evidence), 'saved');
  const recorded = (await probe()).orders.find(o => o.id === historyId);
  assert.equal(recorded.payment_history.length, 1);
  const observation = recorded.payment_history[0].id;
  assert.equal(await paymentPost(historyId, token, {vck_payment_operation:'match',vck_payment_selected_observation:observation,vck_payment_expected_observation:'stale'}), 'stale');
  const matchFields = {vck_payment_operation:'match',vck_payment_selected_observation:observation,vck_payment_expected_observation:observation};
  assert.equal(await paymentPost(historyId, token, matchFields), 'saved');
  const matched = (await probe()).orders.find(o => o.id === historyId);
  assert.equal(matched.payment_history.length, 2);
  await toggle(false);
  const retained = (await request('GET', route(historyId))).text;
  assert.ok(retained.includes(box) && retained.includes(run));
  assert.equal(retained.includes(controls), false);
  assert.equal(retained.includes(form), false);
  assert.equal((await request('GET', route(emptyId))).text.includes(box), false);
  for (const operation of ['observe','match','reverse']) {
    assert.equal(await paymentPost(historyId, token, {...evidence,vck_payment_operation:operation}), 'yoohw_vietnam_store_tools_payment_feature_disabled');
  }
  assert.equal(await paymentPost(emptyId, emptyToken, evidence), 'yoohw_vietnam_store_tools_payment_feature_disabled', 'Valid stale form on empty OFF BACS cannot write');
  assert.deepEqual((await probe()).orders.find(o => o.id === emptyId).payment_meta, [], 'Disabled empty BACS POST writes no payment metadata');
  assert.equal(await paymentPost(emptyId, token, evidence), 403, 'Nonce remains order-bound');
  assert.equal(await paymentPost(historyId, 'invalid', evidence), 403, 'Nonce enforced while OFF');
  assert.deepEqual((await probe()).orders.find(o => o.id === historyId).payment_meta, matched.payment_meta, 'OFF history/meta bytes retained across stale/forged POST');
  await toggle(true);
  assert.ok((await request('GET', route(historyId))).text.includes(controls));
  assert.equal(await paymentPost(historyId, token, {vck_payment_operation:'reverse',vck_payment_selected_observation:observation,vck_payment_expected_match:matched.payment_history[1].id}), 'saved');
  await toggle(false);
  const page = (await request('GET', route(historyId))).text;
  const href = page.match(/href="([^"]*section=yoohw_vietnam_store_tools_customer_electronic_invoice_email[^"]*)"[^>]*target="_blank"/);
  assert.ok(href, 'Invoice panel retains same-origin settings link');
  const url = new URL(href[1].replace(/&amp;|&#038;/g, '&'), base);
  assert.equal(url.origin, new URL(base).origin);
  assert.equal(url.searchParams.get('section'), 'yoohw_vietnam_store_tools_customer_electronic_invoice_email');
  const emailPage = (await request('GET', url.href)).text;
  assert.ok(emailPage.includes('id="woocommerce_yoohw_vietnam_store_tools_customer_electronic_invoice_enabled"'), 'Actual registered invoice settings form rendered');
  console.log(`PASS: VST-92 ${fixture.hpos ? 'HPOS' : 'legacy'} native Dashboard default/ON/OFF, observation/match/reverse/stale/nonce, read-only retained history, registered email settings form`);
})().catch(error => { console.error(error); process.exitCode = 1; });
