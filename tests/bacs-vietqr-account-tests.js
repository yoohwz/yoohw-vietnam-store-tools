'use strict';
// Execute the shipped script against a small DOM and its real capture handlers.
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const source = fs.readFileSync(`${__dirname}/../assets/js/admin/bacs-vietqr.js`, 'utf8');
function scenario(label, localized = label) {
  let focused, observer;
  let nativeSaves = 0;
  const listeners = {};
  class Element {
    constructor(tag, classes = '') { this.tagName = tag.toUpperCase(); this.className = classes; this.children = []; this.attributes = {}; this.listeners = {}; this.value = ''; this.textContent = ''; }
    get classList() { return {contains: c => this.className.split(' ').includes(c), add: c => { if(!this.className.split(' ').includes(c)) this.className += ` ${c}`; }}; }
    matches(selector) { return selector.trim().startsWith('.') ? this.classList.contains(selector.trim().slice(1)) : this.tagName === selector.trim().toUpperCase(); }
    querySelectorAll(selector) { const result=[]; for(const child of this.children) { if(selector.split(',').some(s=>child.matches(s))) result.push(child); result.push(...child.querySelectorAll(selector)); } return result; }
    querySelector(selector) { return this.querySelectorAll(selector)[0] || null; }
    closest(selector) { for(let node=this; node; node=node.parentElement) if(selector.split(',').some(s=>node.matches(s))) return node; return null; }
    appendChild(child) { this.children.push(child); child.parentElement = this; child.parentNode = this; return child; }
    remove() { this.parentElement.children = this.parentElement.children.filter(c=>c!==this); }
    setAttribute(k,v) { this.attributes[k]=v; }
    getAttribute(k) { return this.attributes[k] ?? null; }
    hasAttribute(k) { return k in this.attributes; }
    addEventListener(type,fn) { (this.listeners[type] ??= []).push(fn); }
    dispatchEvent(event) { for(const fn of this.listeners[event.type] || []) fn(event); }
    focus() { focused=this; }
  }
  const body=new Element('body'), head=new Element('head');
  const modal=body.appendChild(new Element('div','bank-account-modal'));
  function field(text,value) { const wrapper=modal.appendChild(new Element('div','bank-account-modal__field')); const label=wrapper.appendChild(new Element('label')); label.textContent=text; const input=wrapper.appendChild(new Element('input')); input.value=value; return {wrapper,label,input}; }
  const account=field(label,'001-ABC /9');
  const bank=field('Bank Name','');
  const bin=field('BIC / SWIFT','');
  const select=bank.wrapper.appendChild(new Element('select','vck-vietqr-bank-select'));
  const save=modal.appendChild(new Element('button','bank-account-modal__save'));
  const document={body,head,readyState:'loading',createElement:tag=>new Element(tag),getElementById:id=>head.children.find(c=>c.id===id),querySelectorAll:s=>body.querySelectorAll(s),querySelector:s=>body.querySelector(s),addEventListener:(type,fn,capture)=>{(listeners[type] ??= []).push({fn,capture});}};
  vm.runInNewContext(source,{document,window:{location:{href:'https://fixture.test/wp-admin/admin.php?page=wc-settings&tab=checkout'},requestAnimationFrame:fn=>fn()},yoohwVietnamStoreToolsBacsVietqr:{isBacsSettings:true,i18n:{accountNumberLabel:localized,accountNumberRequired:'localized presence error'},banks:[{bin:'970418',code:'BIDV',short_name:'BIDV'}]},MutationObserver:class {constructor(fn){observer=fn;}observe(){}},Event:class {constructor(type){this.type=type;}}});
  listeners.DOMContentLoaded[0].fn();
  const error=()=>account.wrapper.querySelector('.vck-vietqr-error');
  function click() { let blocked=false, stopped=false, immediate=false; const event={target:save,preventDefault(){blocked=true;},stopPropagation(){stopped=true;},stopImmediatePropagation(){immediate=true;}}; for(const {fn,capture} of listeners.click) {assert.equal(capture,true); fn(event); if(immediate) break;} if(!stopped) ++nativeSaves; return blocked; }
  assert.equal(account.input.value,'001-ABC /9','Enhancement never rewrites existing number');
  assert.equal(account.label.textContent,`${localized} *`);
  observer(); observer();
  assert.equal(account.label.textContent,`${localized} *`,'Required star is idempotent');
  assert.equal(account.input.listeners.input.length,1,'Observer does not duplicate listeners');
  bin.input.value='970418';
  for(const empty of ['', ' \t\n ']) { account.input.value=empty; assert.equal(click(),true,'Empty/whitespace blocks native save'); assert.equal(focused,account.input); assert.equal(error().textContent,'localized presence error'); assert.equal(account.input.value,empty,'Validation does not normalize value'); assert.equal(nativeSaves,0,'Rejected click never reaches native save/persistence'); }
  account.input.value='000A-9 /'; account.input.dispatchEvent({type:'input'});
  assert.equal(error(),null,'Correction clears error immediately');
  assert.equal(click(),false,'Any non-empty number passes presence validation');
  assert.equal(account.input.value,'000A-9 /','Leading zeros, letters and punctuation preserved');
  bin.input.value=''; bank.input.value=''; select.value=''; account.input.value='';
  assert.equal(click(),true,'Both invalid fields block'); assert.equal(focused,account.input,'Account Number receives deterministic focus when both invalid');
  assert.ok(bank.wrapper.querySelector('.vck-vietqr-error'),'Bank validation still independently enforced');
  account.input.value='valid'; account.input.dispatchEvent({type:'input'});
  assert.equal(click(),true,'Valid number cannot bypass invalid Bank'); assert.equal(focused,select);
  bin.input.value='970418'; assert.equal(click(),false); assert.equal(bin.input.value,'970418','Existing BIN is preserved'); assert.equal(bank.input.value,'BIDV','Bank display selection still follows BIN'); assert.equal(bank.wrapper.querySelector('.vck-vietqr-error'),null);
}
for(const label of ['Account Number','Số tài khoản','So tai khoan']) scenario(label);
scenario('Số tài khoản','Account Number');
console.log('PASS: BACS account required presence, EN/VI discovery, observer idempotence, capture blocking, correction/focus and independent Bank validation.');
