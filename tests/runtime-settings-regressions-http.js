#!/usr/bin/env node
/* Native HTTP form saves and next-request behavior; opt-in Local fixture only. */
const fs = require('node:fs');
const assert = require('node:assert/strict');
const fixture = JSON.parse(fs.readFileSync(`${__dirname}/fixtures/.vst85-settings.json`));
const base = fixture.base;
assert.ok(['localhost', '127.0.0.1', process.env.VST_TEST_HOST].filter(Boolean).includes(new URL(base).hostname));
const cookies = new Map(Object.entries(fixture.cookies));
async function request(method, target, body, headers = {}, authenticated = true) {
  const url = new URL(target, `${base}/`);
  assert.equal(url.origin, new URL(base).origin, 'Requests stay on the explicitly selected fixture');
  const response = await fetch(url, {method, body, headers: {...headers, ...(authenticated ? {Cookie: [...cookies].map(([k,v]) => `${k}=${v}`).join('; ')} : {})}, redirect: 'manual'});
  if (authenticated) for (const cookie of response.headers.getSetCookie()) { const [first] = cookie.split(';'); const i = first.indexOf('='); cookies.set(first.slice(0,i), first.slice(i+1)); }
  return {response, text: await response.text()};
}
function nonce(html, name = '_wpnonce') { const match = html.match(new RegExp(`name="${name}"[^>]*value="([^"]+)"`)); assert.ok(match, `Actual form nonce ${name}`); return match[1]; }
const featureOptions = ['address_fields_enabled','phone_normalization_enabled','customer_shipment_display_enabled','order_management_enabled','allow_tax_invoice_request','electronic_invoice_enabled'].map(s => `yoohw_vietnam_store_tools_${s}`);
async function dashboard() { const r=await request('GET','/wp-admin/admin.php?page=yoohw-vietnam-store'); assert.equal(r.response.status,200); return r.text; }
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
  return probe();
}
(async()=>{
  const initial=await probe(); const oldLookup=initial.public_lookup_enabled;
  const on=await saveFeatures([...featureOptions,'paypal']);
  for(const id of featureOptions) assert.equal(on.options[id],'yes');
  for(const [name,count] of Object.entries(on.counts)) assert.ok(count>0,`ON runtime hooks ${name}`);
  assert.equal(on.shipment_details,true);assert.equal(on.shipment_timeline,true);assert.equal(on.vat_fields,true);assert.equal(on.invoice_save_hook,true);
  assert.equal(on.paypal.reason,'COMPATIBLE');assert.equal(on.paypal.ready,true);assert.equal(on.paypal_force_place_order,true);
  const off=await saveFeatures([]);
  for(const id of featureOptions) assert.equal(off.options[id],'no');
  for(const [name,count] of Object.entries(off.counts)) assert.equal(count,0,`OFF next-request hooks ${name}`);
  for(const name of ['shipment_details','shipment_timeline','vat_fields','invoice_save_hook','paypal_force_place_order']) assert.equal(off[name],false,name);
  assert.equal(off.invoice_number,'VST85-HISTORY');assert.equal(off.public_lookup_enabled,oldLookup);
  assert.equal(off.options.yoohw_vietnam_store_tools_paypal_conversion_settings.yoohw_vietnam_store_tools_paypal_vnd_usd_enabled,'no');
  const independent=await saveFeatures([featureOptions[1],featureOptions[4]]);
  assert.equal(independent.counts.Yoohw_Vietnam_Store_Tools_Address_Fields,0);assert.ok(independent.counts.Yoohw_Vietnam_Store_Tools_Phone_Normalization>0);assert.equal(independent.vat_fields,true);assert.equal(independent.invoice_save_hook,false);
  await saveFeatures([...featureOptions,'paypal']);
  console.log('PASS: all seven Dashboard controls submit -> persisted state -> reload -> next-request hooks/output; independent gates and history preserved');
  const prefix='yoohw_vietnam_store_tools_vietqr_';
  const route='/wp-admin/admin.php?page=wc-settings&tab=checkout&section=bacs';
  const keys=['enabled','transfer_content','include_amount','image_template','show_email'];
  async function saveBacs(values) {
    const page=await request('GET',route);
    assert.equal(page.response.status,200);
    for(const key of keys) assert.equal((page.text.match(new RegExp(`name="woocommerce_bacs_${prefix}${key}"`,'g'))||[]).length,1,`Exactly one native control ${key}`);
    const state=await probe();
    assert.deepEqual(state.options.woocommerce_bacs_accounts,initial.options.woocommerce_bacs_accounts,"Accounts unchanged before BACS submit");
    const form=new URLSearchParams({_wpnonce:nonce(page.text),save:'Save changes'});
    const settings={...state.options.woocommerce_bacs_settings,...values};
    for(const [key,value] of Object.entries(settings)) { if(value==='yes') form.set(`woocommerce_bacs_${key}`,'1'); else if(value!=='no') form.set(`woocommerce_bacs_${key}`,String(value)); }
    for(const account of state.options.woocommerce_bacs_accounts) for(const key of ['account_name','account_number','bank_name','sort_code','iban','bic']) form.append(`bacs_${key}[]`,account[key]||'');
    const saved=await request('POST',route,form);assert.ok([200,302].includes(saved.response.status),saved.text.slice(0,500));
    const reload=await request('GET',route);
    for(const key of keys) {
      const value=settings[prefix+key];
      const tag=reload.text.match(new RegExp(`<(?:input|select)[^>]*name="woocommerce_bacs_${prefix}${key}"[^>]*>`));assert.ok(tag,`Reload ${key}`);
      if(['enabled','include_amount','show_email'].includes(key)) assert.equal(/checked/.test(tag[0]),value==='yes',`Reload checkbox ${key}`);
      else if(key==='transfer_content') assert.ok(tag[0].includes(`value="${value}"`),'Reload transfer template');
      else assert.ok(reload.text.includes(`value="${value}"  selected`),'Reload selected QR template');
    }
    return probe();
  }
  const values={enabled:'yes',title:'VST85 transfer',description:'VST85 description',instructions:'VST85 instructions',[prefix+'enabled']:'yes',[prefix+'transfer_content']:'TEST-{order_number}',[prefix+'include_amount']:'yes',[prefix+'image_template']:'compact',[prefix+'show_email']:'no'};
  const rendered=await saveBacs(values);
  for(const [key,value] of Object.entries(values)) assert.equal(rendered.options.woocommerce_bacs_settings[key],value,`Persisted ${key}`);
  assert.equal(rendered.frontend_qr,true);assert.equal(rendered.admin_qr,true);assert.equal(rendered.email_qr,false);assert.equal(rendered.qr.length,1);assert.equal(rendered.qr[0].amount,'250000');assert.ok(rendered.qr[0].transfer_content.startsWith('TEST-'));assert.equal(rendered.non_bacs.length,0);assert.equal(rendered.non_vnd[0].amount,'');
  assert.deepEqual(rendered.options.woocommerce_bacs_accounts,initial.options.woocommerce_bacs_accounts,'Native account option preserved');
  for(const [key,value] of Object.entries(initial.options.woocommerce_bacs_settings)) if(!key.startsWith(prefix)&&!Object.hasOwn(values,key)) assert.deepEqual(rendered.options.woocommerce_bacs_settings[key],value,`Core/extension setting preserved ${key}`);
  const offQr=await saveBacs({[prefix+'enabled']:'no',[prefix+'include_amount']:'no',[prefix+'show_email']:'yes',[prefix+'image_template']:'qr_only'});
  assert.equal(offQr.qr.length,0);assert.equal(offQr.frontend_qr,false);assert.equal(offQr.admin_qr,false);assert.equal(offQr.email_qr,false);
  const noAmount=await saveBacs({[prefix+'enabled']:'yes'});assert.equal(noAmount.qr[0].amount,'');assert.equal(noAmount.email_qr,true);
  console.log('PASS: current BACS route native field save/reload/QR, core/account preservation, disabled/non-BACS/non-VND boundaries');
  for(const id of fixture.orders) {
    const orderQuery=process.env.VST_TEST_ISOLATE_ORDER==='1'?'&vst85_isolate=1':'';
    const route=fixture.hpos ? `/wp-admin/admin.php?page=wc-orders&action=edit&id=${id}${orderQuery}` : `/wp-admin/post.php?post=${id}&action=edit`;
    const page=await request('GET',route);assert.equal(page.response.status,200);
    const input=page.text.match(new RegExp(`<input[^>]*id="vck_manual_shipping_tracking_code_${id}"[^>]*>`));assert.ok(input,'Manual shipment control exists');assert.equal(/\brequired(?:[\s=>])/.test(input[0]),false,'Untouched shipment input does not constrain parent form');
    const action=page.text.match(/<button[^>]*data-vck-shipping-action="yoohw_vietnam_store_tools_save_manual_shipment"[^>]*>/);assert.ok(action);
    const token=action[0].match(/data-vck-shipping-nonce="([^"]+)"/)[1];
    const body=new URLSearchParams({action:'yoohw_vietnam_store_tools_save_manual_shipment',order_id:String(id),provider_id:'ghn',yoohw_vietnam_store_tools_shipping_nonce:token,'yoohw_vietnam_store_tools_shipping[tracking_code]':''});
    const rejected=await request('POST','/wp-admin/admin-post.php',body);assert.equal(rejected.response.status,302);assert.ok(new URL(rejected.response.headers.get('location')).searchParams.get('yoohw_vietnam_store_tools_shipping_error'),'Explicit empty shipment save rejects');
    body.set('yoohw_vietnam_store_tools_shipping_nonce','invalid');const badNonce=await request('POST','/wp-admin/admin-post.php',body);assert.equal(badNonce.response.status,403,'Nonce remains required');
    const update=new URLSearchParams({_wpnonce:nonce(page.text),action:fixture.hpos?'edit_order':'editpost',post_ID:String(id),_payment_method:'bacs',save:'Update',order_status:'wc-pending',original_post_status:'wc-pending','woocommerce_meta_nonce':nonce(page.text,'woocommerce_meta_nonce'),_billing_first_name:`VST85-UPDATE-${id}`,_billing_email:'vst85-order@example.test'});
    if(!fixture.hpos) update.set('post_type','shop_order');
    const saved=await request('POST',fixture.hpos?route:'/wp-admin/post.php',update);assert.equal(saved.response.status,302,'Ordinary Update order redirects after save');
    const updated=(await probe()).orders.find(order=>order.id===id);assert.equal(updated.first_name,`VST85-UPDATE-${id}`,'Ordinary Update persisted independent order field');assert.equal(updated.tracking_code,id===fixture.orders[0]?'':'VST85-TRACK','Ordinary Update preserves empty/populated tracking');

  }
  console.log(`PASS: ${fixture.hpos?'HPOS':'legacy'} parent form constraint and explicit empty shipment/security validation`);
})().catch(error=>{console.error(error);process.exitCode=1;});
