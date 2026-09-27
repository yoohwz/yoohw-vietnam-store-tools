#!/usr/bin/env node
/* Exact-candidate HTTP Classic and Store API checkout checks in disposable wp-env. */
const fs = require('node:fs');
const path = require('node:path');
const assert = require('node:assert/strict');

const mode = process.argv[2];
assert.ok(['classic', 'blocks', 'order-pay'].includes(mode), 'Choose classic, blocks or order-pay');
const fixture = JSON.parse(fs.readFileSync(path.join(__dirname, 'fixtures/.vst62-http-fixture.json'), 'utf8'));
const base = new URL(fixture.classic_url).origin;
assert.ok(['localhost', '127.0.0.1'].includes(new URL(base).hostname), 'Disposable localhost only');
const cookies = new Map();

async function request(method, target, body, headers = {}) {
  let url = new URL(target, base).href;
  for (let redirects = 0; redirects < 6; redirects++) {
    const sent = { ...headers };
    if (cookies.size) sent.Cookie = [...cookies].map(([name, value]) => `${name}=${value}`).join('; ');
    const response = await fetch(url, { method, headers: sent, body, redirect: 'manual' });
    for (const cookie of response.headers.getSetCookie()) {
      const first = cookie.split(';', 1)[0];
      const separator = first.indexOf('=');
      if (separator > 0) cookies.set(first.slice(0, separator), first.slice(separator + 1));
    }
    if ([301, 302, 303, 307, 308].includes(response.status) && response.headers.get('location')) {
      url = new URL(response.headers.get('location'), url).href;
      if ([301, 302, 303].includes(response.status)) {
        method = 'GET';
        body = undefined;
      }
      continue;
    }
    return { response, text: await response.text(), url };
  }
  throw new Error('Too many redirects');
}

function checkResponse(result, label) {
  assert.equal(result.response.status, 200, `${label}: HTTP ${result.response.status}: ${result.text.slice(0, 500)}`);
  return result;
}

async function addToCart() {
  const result = await request('GET', `/?add-to-cart=${fixture.product_id}`);
  checkResponse(result, 'Add virtual product to cart');
}

async function classic() {
  await addToCart();
  const page = checkResponse(await request('GET', fixture.classic_url), 'Classic checkout page');
  assert.match(page.text, /woocommerce-checkout/, 'Classic checkout form renders');
  assert.match(page.text, /billing_state/, 'Province field renders');
  assert.match(page.text, /billing_city/, 'Ward field renders');
  assert.match(page.text, /yoohw_vietnam_store_tools_tax_invoice_requested/, 'VAT request field renders');
  const nonce = page.text.match(/name="woocommerce-process-checkout-nonce"[^>]*value="([^"]+)"/);
  assert.ok(nonce, 'Classic checkout nonce renders');
  const fields = new URLSearchParams({
    billing_first_name: 'VST', billing_last_name: 'Buyer', billing_country: 'VN',
    billing_state: '01', billing_city: fixture.ward, billing_address_1: '123 Fixture Street',
    billing_phone: '0901234567', billing_email: 'classic62@example.test',
    payment_method: 'bacs',
    yoohw_vietnam_store_tools_tax_invoice_requested: '1',
    yoohw_vietnam_store_tools_tax_invoice_company_name: 'VST Fixture Company',
    yoohw_vietnam_store_tools_tax_invoice_tax_code: '0123456789',
    yoohw_vietnam_store_tools_tax_invoice_company_address: 'Ha Noi',
    yoohw_vietnam_store_tools_tax_invoice_email: 'invoice62@example.test',
    'woocommerce-process-checkout-nonce': nonce[1],
    _wp_http_referer: new URL(fixture.classic_url).pathname,
  });
  const submit = checkResponse(await request('POST', '/?wc-ajax=checkout', fields, {
    'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8',
    'X-Requested-With': 'XMLHttpRequest',
  }), 'Classic checkout submission');
  const result = JSON.parse(submit.text);
  assert.equal(result.result, 'success', `Classic checkout: ${JSON.stringify(result).slice(0, 700)}`);
  const received = checkResponse(await request('GET', result.redirect), 'Classic order-received page');
  assert.match(received.text, /VST-62 HTTP|Fixture Bank|123456789/, 'Customer BACS output renders');
  console.log('PASS: Classic HTTP address, phone, VAT, native BACS and customer order-received output');
}

async function blocks() {
  await addToCart();
  const page = checkResponse(await request('GET', fixture.blocks_url), 'Blocks checkout page');
  assert.ok(page.text.includes('wc-block-checkout') || page.text.includes('woocommerce/checkout'), 'Blocks checkout page renders');
  const cart = checkResponse(await request('GET', '/wp-json/wc/store/v1/cart'), 'Store API cart');
  const nonce = cart.response.headers.get('nonce');
  const cartToken = cart.response.headers.get('cart-token');
  assert.ok(nonce || cartToken, 'Store API grants a cart nonce or token');
  const address = {
    first_name: 'VST', last_name: 'Buyer', country: 'VN', state: '01', city: fixture.ward,
    address_1: '123 Fixture Street', address_2: '', postcode: '', phone: '0901234567', email: 'blocks62@example.test',
  };
  const payload = {
    billing_address: address, shipping_address: address, payment_method: 'bacs',
    additional_fields: {
      'yoohw-vietnam-store-tools/tax-invoice-requested': true,
      'yoohw-vietnam-store-tools/tax-invoice-company-name': 'VST Fixture Company',
      'yoohw-vietnam-store-tools/tax-invoice-tax-code': '0123456789',
      'yoohw-vietnam-store-tools/tax-invoice-company-address': 'Ha Noi',
      'yoohw-vietnam-store-tools/tax-invoice-email': 'invoice62@example.test',
    },
  };
  const headers = { 'Content-Type': 'application/json' };
  if (nonce) headers.Nonce = nonce;
  if (cartToken) headers['Cart-Token'] = cartToken;
  const submit = await request('POST', '/wp-json/wc/store/v1/checkout', JSON.stringify(payload), headers);
  checkResponse(submit, 'Store API checkout submission');
  const result = JSON.parse(submit.text);
  assert.ok(result.order_id, `Store API created an order: ${JSON.stringify(result).slice(0, 700)}`);
  console.log('PASS: Blocks page and Store API address, phone, VAT and native BACS checkout');
}

async function orderPay() {
  cookies.set(fixture.auth_cookie_name, fixture.auth_cookie);
  const page = checkResponse(await request('GET', fixture.pay_url), 'Native order-pay page');
  assert.match(page.text, /id="order_review"/, 'Native order-pay form renders for the order customer');
  assert.match(page.text, /payment_method_bacs/, 'BACS gateway is available on order-pay');
  const nonce = page.text.match(/name="woocommerce-pay-nonce"[^>]*value="([^"]+)"/);
  assert.ok(nonce, 'Native order-pay nonce renders');
  const fields = new URLSearchParams({ woocommerce_pay: '1', payment_method: 'bacs', 'woocommerce-pay-nonce': nonce[1] });
  const paid = checkResponse(await request('POST', fixture.pay_url, fields, {
    'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8',
  }), 'Native order-pay BACS submission');
  assert.match(paid.text, /VST-62 HTTP|Fixture Bank|123456789/, 'Native order-pay customer BACS output renders');
  console.log('PASS: Native order-pay HTTP form, nonce, BACS gateway and customer output');
}

({ classic, blocks, 'order-pay': orderPay }[mode])().catch(error => { console.error(error); process.exitCode = 1; });
