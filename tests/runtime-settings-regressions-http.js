#!/usr/bin/env node
/* Native HTTP form saves and next-request behavior; opt-in Local fixture only. */
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
async function request(method, target, body, headers = {}, authenticated = true) {
  const url = new URL(target, `${base}/`);
  assert.equal(url.origin, new URL(base).origin, 'Requests stay on the explicitly selected fixture');
  const response = await fetch(url, {method, body, headers: {...headers, ...(authenticated ? {Cookie: [...cookies].map(([k,v]) => `${k}=${v}`).join('; ')} : {})}, redirect: 'manual'});
  if (authenticated) for (const cookie of response.headers.getSetCookie()) { const [first] = cookie.split(';'); const i = first.indexOf('='); cookies.set(first.slice(0,i), first.slice(i+1)); }
  return {response, text: await response.text()};
}
function nonce(html, name = '_wpnonce') { const match = html.match(new RegExp(`name="${name}"[^>]*value="([^"]+)"`)); assert.ok(match, `Actual form nonce ${name}`); return match[1]; }
const featureOptions = ['address_fields_enabled','phone_normalization_enabled','customer_shipment_display_enabled','order_management_enabled','allow_tax_invoice_request','electronic_invoice_enabled','payment_reconciliation_enabled'].map(s => `yoohw_vietnam_store_tools_${s}`);
async function dashboard() { const r=await request('GET','/wp-admin/admin.php?page=yoohw-vietnam-store'); assert.equal(r.response.status,200, `Dashboard redirect path: ${r.response.headers.get('location') ? new URL(r.response.headers.get('location'),base).pathname : 'none'}`); return r.text; }
async function probe() { const html=await dashboard(); const r=await request('POST','/wp-admin/admin-ajax.php',new URLSearchParams({action:'vst85_settings_probe',_wpnonce:nonce(html)})); assert.equal(r.response.status,200,r.text.slice(0,300)); return JSON.parse(r.text).data; }
async function saveFeatures(enabled) {
  const html=await dashboard(); const body=new URLSearchParams({action:'yoohw_vietnam_store_tools_save_features',_wpnonce:nonce(html),'paypal_conversion[rate]':'25000'});
  for (const id of enabled) { if(id==='paypal') body.set('paypal_conversion[enabled]','yes'); else body.set(`features[${id}]`,'yes'); }
  const r=await request('POST','/wp-admin/admin-post.php',body);
  assert.equal(r.response.status,302,r.text.slice(0,300));
  const next=await dashboard();
  for (const id of featureOptions) {
    const tag=next.match(new RegExp(`<input[^>]*id="${id}"[^>]*>`)); assert.ok(tag,`Reload control ${id}`);
    assert.equal(/checked/.test(tag[0]),enabled.includes(id),`Reload reflects saved ${id}`);
  }
  const paypalTag=next.match(/<input[^>]*id="yoohw-paypal-conversion-enabled"[^>]*>/);assert.ok(paypalTag,'Reload PayPal control');assert.equal(/checked/.test(paypalTag[0]),enabled.includes('paypal'),'Reload reflects saved PayPal toggle');
  return probe();
}
(async()=>{
  const privateFile=await request('GET',`/wp-content/plugins/yoohw-vietnam-store-tools/tests/fixtures/.vst90-${run}.php`); assert.ok([200,403,404].includes(privateFile.response.status)); assert.equal(privateFile.text.includes(fixture.password),false,'Private fixture credentials cannot be fetched over HTTP');
  await request('GET','/wp-login.php');
  const login=await request('POST','/wp-login.php',new URLSearchParams({log:fixture.login,pwd:fixture.password,testcookie:'1',redirect_to:`${base}/wp-admin/`}));
  assert.equal(login.response.status,302,'Native HTTP login succeeds');
  assert.ok([...cookies.keys()].some(name=>name.startsWith('wordpress_logged_in_')),'Web session cookie received');
  await openAdminSession(request, base);
  const initial=await probe(); const oldLookup=initial.public_lookup_enabled;
  assert.equal(initial.fixture_mail_blocked,true,'Fixture mail is suppressed without sending a message');
  assert.equal(initial.ci_loaded,false,'Declared certification stack keeps Customer Intelligence inactive');
  const on=await saveFeatures([...featureOptions,'paypal']);
  for(const id of featureOptions) assert.equal(on.options[id],'yes');
  for(const [name,count] of Object.entries(on.counts)) assert.ok(count>0,`ON runtime hooks ${name}`);
  assert.equal(on.shipment_details,true);assert.equal(on.shipment_timeline,true);assert.equal(on.vat_fields,true);assert.equal(on.invoice_save_hook,true);
  assert.equal(on.paypal.reason,'COMPATIBLE');assert.equal(on.paypal.ready,true);assert.equal(on.paypal_force_place_order,true);
  const off=await saveFeatures([]);
  for(const id of featureOptions) assert.equal(off.options[id],'no');
  for(const [name,count] of Object.entries(off.counts)) assert.equal(count,0,`OFF next-request hooks ${name}`);
  for(const name of ['shipment_details','shipment_timeline','vat_fields','invoice_save_hook','paypal_force_place_order']) assert.equal(off[name],false,name);
  assert.equal(off.invoice_number,fixture.history);assert.equal(off.public_lookup_enabled,oldLookup);
  assert.equal(off.options.yoohw_vietnam_store_tools_paypal_conversion_settings.yoohw_vietnam_store_tools_paypal_vnd_usd_enabled,'no');
  const independent=await saveFeatures([featureOptions[1],featureOptions[4]]);
  assert.equal(independent.counts.Yoohw_Vietnam_Store_Tools_Address_Fields,0);assert.ok(independent.counts.Yoohw_Vietnam_Store_Tools_Phone_Normalization>0);assert.equal(independent.vat_fields,true);assert.equal(independent.invoice_save_hook,false);
  await saveFeatures([...featureOptions,'paypal']);
  console.log('PASS: all eight Dashboard controls submit -> persisted state -> reload -> next-request hooks/output; independent gates and history preserved');
  const prefix='yoohw_vietnam_store_tools_vietqr_';
  const route='/wp-admin/admin.php?page=wc-settings&tab=checkout&section=bacs';
  const keys=['enabled','include_amount','image_template','show_email'];
  async function saveVietqr(values) {
    const page=await dashboard();
    for(const key of keys) assert.equal((page.match(new RegExp(`name="vietqr_settings\\[${prefix}${key}\\]"`,'g'))||[]).length,1,`Exactly one Dashboard control ${key}`);
    assert.ok(page.includes('yoohw-vietnam-store__vietqr-settings'),'Dedicated VietQR settings card');
    assert.ok(page.includes('yoohw-vietnam-store__paypal-settings'),'PayPal settings alongside VietQR');
    assert.ok(page.indexOf('yoohw-vietnam-store__vietqr-settings')<page.indexOf('yoohw-vietnam-store__paypal-settings'),'VietQR precedes PayPal settings');
    assert.equal(page.includes(`name="vietqr_settings[${prefix}transfer_content]"`),false,'Transfer content stays on BACS');
    const before=await probe(); assert.equal(before.bacs_react,true,'Active VST preserves React BACS');
    const invalid=await request('POST','/wp-admin/admin-post.php',new URLSearchParams({action:'yoohw_vietnam_store_tools_save_features',_wpnonce:'invalid','vietqr_settings[present]':'1'}));assert.equal(invalid.response.status,403,'Dashboard nonce remains required');
    assert.deepEqual((await probe()).options.woocommerce_bacs_settings,before.options.woocommerce_bacs_settings,'Rejected save does not change BACS');
    const form=new URLSearchParams({action:'yoohw_vietnam_store_tools_save_features',_wpnonce:nonce(page),'paypal_conversion[rate]':'25000','paypal_conversion[enabled]':'yes','vietqr_settings[present]':'1'});
    for(const id of featureOptions) form.set(`features[${id}]`,'yes');
    for(const key of keys) if(values[key]!=='no') form.set(`vietqr_settings[${prefix}${key}]`,values[key]);
    // Attempted unowned keys must not replace native fields or the React-side template.
    form.set('vietqr_settings[title]','must-not-write');form.set(`vietqr_settings[${prefix}transfer_content]`,'must-not-write');
    const saved=await request('POST','/wp-admin/admin-post.php',form);assert.equal(saved.response.status,302);
    const reload=await dashboard();
    for(const key of keys) {
      const tag=reload.match(new RegExp(`<(?:input|select)[^>]*name="vietqr_settings\\[${prefix}${key}\\]"[^>]*>`));assert.ok(tag,`Reload ${key}`);
      if(key!=='image_template') assert.equal(/checked/.test(tag[0]),values[key]==='yes',`Reload checkbox ${key}`);
      else assert.ok(reload.includes(`value="${values[key]}"  selected`),'Reload selected QR template');
    }
    const after=await probe();
    for(const key of keys) assert.equal(after.options.woocommerce_bacs_settings[prefix+key],values[key],`Persisted ${key}`);
    for(const [key,value] of Object.entries(before.options.woocommerce_bacs_settings)) if(!keys.map(k=>prefix+k).includes(key)) assert.deepEqual(after.options.woocommerce_bacs_settings[key],value,`Dashboard preserves ${key}`);
    assert.deepEqual(after.options.woocommerce_bacs_accounts,before.options.woocommerce_bacs_accounts,'Dashboard never changes native accounts');
    return after;
  }
  async function bacsConfig() {
    const page=await request('GET',route);assert.equal(page.response.status,200);
    const config=page.text.match(/var yoohwVietnamStoreToolsBacsVietqr = (\{[^\n]+\});/);assert.ok(config,'React BACS localized settings');
    return JSON.parse(config[1]);
  }
  const beforeTransfer=await probe();const config=await bacsConfig();
  assert.equal(beforeTransfer.bacs_react,true);
  const transfer=await request('POST','/wp-admin/admin-ajax.php',new URLSearchParams({action:'yoohw_vietnam_store_tools_save_bacs_vietqr_settings',nonce:config.nonce,transfer_content:'TEST-{order_number}'}));
  assert.equal(transfer.response.status,200);assert.equal(JSON.parse(transfer.text).success,true);
  assert.equal((await bacsConfig()).settings.transferContent,'TEST-{order_number}','React transfer content reloads');
  const afterTransfer=await probe();
  for(const [key,value] of Object.entries(beforeTransfer.options.woocommerce_bacs_settings)) if(key!==prefix+'transfer_content') assert.deepEqual(afterTransfer.options.woocommerce_bacs_settings[key],value,`Transfer save preserves sibling ${key}`);
  assert.deepEqual(afterTransfer.options.woocommerce_bacs_accounts,beforeTransfer.options.woocommerce_bacs_accounts,'Transfer save preserves accounts');
  const invalidTransfer=await request('POST','/wp-admin/admin-ajax.php',new URLSearchParams({action:'yoohw_vietnam_store_tools_save_bacs_vietqr_settings',nonce:'invalid',transfer_content:'invalid'}));assert.equal(invalidTransfer.response.status,403);
  const rendered=await saveVietqr({enabled:'yes',include_amount:'yes',image_template:'compact',show_email:'no'});
  assert.equal(rendered.frontend_qr,true);assert.equal(rendered.admin_qr,true);assert.equal(rendered.email_qr,false);assert.equal(rendered.qr.length,1);assert.equal(rendered.qr[0].bank_bin,'970418','React-selected bank BIN overrides stale legacy sort code');assert.equal(rendered.qr[0].amount,'250000');assert.ok(rendered.qr[0].transfer_content.startsWith('TEST-'));assert.ok(rendered.qr[0].qr_url.includes('-compact.png'));assert.equal(rendered.non_bacs.length,0);assert.equal(rendered.non_vnd[0].amount,'');
  const offQr=await saveVietqr({enabled:'no',include_amount:'no',show_email:'yes',image_template:'qr_only'});
  assert.equal(offQr.qr.length,0);assert.equal(offQr.frontend_qr,false);assert.equal(offQr.admin_qr,false);assert.equal(offQr.email_qr,false);
  const noAmount=await saveVietqr({enabled:'yes',include_amount:'no',show_email:'yes',image_template:'compact2'});assert.equal(noAmount.qr[0].amount,'');assert.equal(noAmount.email_qr,true);assert.ok(noAmount.qr[0].qr_url.includes('-compact2.png'));
  console.log('PASS: React BACS retained; transfer save preserves siblings; Dashboard VietQR POST/DB/reload/runtime, core/account/unknown preservation and disabled/non-BACS/non-VND boundaries');
  for(const id of fixture.orders) {
    const orderQuery='';
    const route=fixture.hpos ? `/wp-admin/admin.php?page=wc-orders&action=edit&id=${id}${orderQuery}` : `/wp-admin/post.php?post=${id}&action=edit`;
    const page=await request('GET',route);assert.equal(page.response.status,200);
    const input=page.text.match(new RegExp(`<input[^>]*id="vck_manual_shipping_tracking_code_${id}"[^>]*>`));assert.ok(input,'Manual shipment control exists');assert.equal(/\brequired(?:[\s=>])/.test(input[0]),false,'Untouched shipment input does not constrain parent form');
    const action=page.text.match(/<button[^>]*data-vck-shipping-action="yoohw_vietnam_store_tools_save_manual_shipment"[^>]*>/);assert.ok(action);
    const token=action[0].match(/data-vck-shipping-nonce="([^"]+)"/)[1];
    const body=new URLSearchParams({action:'yoohw_vietnam_store_tools_save_manual_shipment',order_id:String(id),provider_id:'ghn',yoohw_vietnam_store_tools_shipping_nonce:token,'yoohw_vietnam_store_tools_shipping[tracking_code]':''});
    const rejected=await request('POST','/wp-admin/admin-post.php',body);assert.equal(rejected.response.status,302);assert.ok(new URL(rejected.response.headers.get('location')).searchParams.get('yoohw_vietnam_store_tools_shipping_error'),'Explicit empty shipment save rejects');
    body.set('yoohw_vietnam_store_tools_shipping_nonce','invalid');const badNonce=await request('POST','/wp-admin/admin-post.php',body);assert.equal(badNonce.response.status,403,'Nonce remains required');
    const update=new URLSearchParams({_wpnonce:nonce(page.text),action:fixture.hpos?'edit_order':'editpost',post_ID:String(id),_payment_method:'bacs',save:'Update',order_status:'wc-pending',original_post_status:'wc-pending','woocommerce_meta_nonce':nonce(page.text,'woocommerce_meta_nonce'),_billing_first_name:`${fixture.run}-UPDATE-${id}`,_billing_email:fixture.email,customer_user:String(fixture.userId)});
    if(!fixture.hpos) update.set('post_type','shop_order');
    const saved=await request('POST',fixture.hpos?route:'/wp-admin/post.php',update);assert.equal(saved.response.status,302,'Ordinary Update order redirects after save');
    const updated=(await probe()).orders.find(order=>order.id===id);assert.equal(updated.first_name,`${fixture.run}-UPDATE-${id}`,'Ordinary Update persisted independent order field');assert.equal(updated.tracking_code,id===fixture.orders[0]?'':fixture.tracking,'Ordinary Update preserves empty/populated tracking');

  }
  console.log(`PASS: ${fixture.hpos?'HPOS':'legacy'} parent form constraint and explicit empty shipment/security validation`);
})().catch(error=>{console.error(error);process.exitCode=1;});
