/* Execute the shipped assistant controller with a minimal DOM/AJAX fixture. */
'use strict';
const fs = require('fs');
const vm = require('vm');
const assert = require('assert');
const source = fs.readFileSync('assets/js/admin/devvn-migration-tools.js', 'utf8');
function fixture() {
  const nodes = new Map(), requests = [], timers = [];
  class Node {
    constructor(key) { this.key = key; this.length = key.startsWith('#form_') ? 0 : 1; this.props = {}; this.handlers = {}; this.items = []; this.value = ''; }
    find(key) { return node(key); }
    on(event, handler) { this.handlers[event] = handler; return this; }
    prop(key, value) { this.props[key] = value; return this; }
    text(value) { this.value = value; return this; }
    empty() { this.items = []; this.value = ''; return this; }
    appendTo(target) { target.items.push(this); return this; }
    children() { return this.items; }
    each() { return this; }
  }
  function node(key) { if (!nodes.has(key)) nodes.set(key, new Node(key)); return nodes.get(key); }
  function $(key) { if (typeof key === 'function') { key(); return; } return key === '<p>' || key === '<li>' ? new Node(key) : node(key); }
  $.post = (url, payload) => { const req = {payload, done(fn) { this.resolve = fn; return this; }, fail(fn) { this.reject = fn; return this; }}; requests.push(req); return req; };
  const window = {confirm: () => false, setTimeout: fn => timers.push(fn), yoohwVietnamStoreToolsDevvnMigrationTools: {ajaxUrl: '/ajax',nonce:'test',migrationTool:'migration',strings:{stopped:'STOP',completed:'DONE',requestFailed:'FAIL',manualOnly:'REVIEW ONLY',noMigratable:'NO DATA'}}};
  vm.runInNewContext(source, {jQuery:$,window});
  return {nodes, requests, timers, window, click(key) { node(key).handlers.click(); }, resolve(data) { requests.shift().resolve({success:true,data}); }};
}
const f = fixture();
assert.equal(f.requests.length,0,'Page initialization never scans the legacy corpus');
f.click('.vck-health-migrate');
assert.equal(f.requests.length,0,'Cannot migrate before scan');
f.click('.vck-health-scan');
assert.equal(f.requests[0].payload.mode,'scan');
assert.equal(f.nodes.get('.vck-health-migrate').props.disabled,true);
f.resolve({remaining:3,addressesSafe:1,customerAddressesSafe:1,trackingRemaining:1,addressesReview:2,report:'Safe plus needs-review rows'});
assert.equal(f.nodes.get('.vck-health-migrate').props.disabled,false);
assert.equal(f.nodes.get('.vck-health-assistant').props.hidden,false,'Exact-safe rows reveal assistant');
assert.equal(f.nodes.get('[data-health-metric="legacy"]').value,3,'Safe metric uses authoritative remaining count');
assert.equal(f.nodes.get('[data-health-metric="review"]').value,2,'Review metric is populated only after scan');
f.click('.vck-health-migrate');
assert.equal(f.requests.length,0,'Cancelled confirmation never writes');
f.window.confirm = () => true;
f.click('.vck-health-migrate');
assert.equal(f.requests[0].payload.mode,'step');
f.click('.vck-health-migrate');
assert.equal(f.requests.length,1,'Double click does not launch parallel batches');
f.resolve({remaining:0,done:true,report:'Needs-review remains',step:{orderAddressesMoved:1,customerAddressesMoved:1,trackingSynced:1}});
assert.equal(f.requests[0].payload.mode,'scan','Completion always triggers post-validation');
f.resolve({remaining:0,report:'Only needs-review remains'});
assert.equal(f.nodes.get('.vck-health-migrate').props.disabled,true);
assert.equal(f.nodes.get('.vck-health-assistant').props.hidden,true,'Assistant closes after all safe rows sync');
assert.equal(f.nodes.get('.vck-health-progress').items.length,4,'Three domain counters and completion notice');
assert.equal(f.nodes.get('.vck-health-report').value,'Only needs-review remains');
const stalled = fixture();
stalled.window.confirm=()=>true;
stalled.click('.vck-health-scan'); stalled.resolve({remaining:10});
stalled.click('.vck-health-migrate');
stalled.resolve({remaining:10,done:false,step:{addressErrors:Array(20).fill('<b>failure</b>')}});
assert.equal(stalled.timers.length,0,'No-progress stops even if server claims more work');
assert.equal(stalled.nodes.get('.vck-health-errors').items.length,15,'Bounded visible failures');
assert.equal(stalled.nodes.get('.vck-health-errors').items[0].value,'<b>failure</b>','Errors passed to text, never HTML');
stalled.resolve({remaining:10});
assert.equal(stalled.nodes.get('.vck-health-progress').items.at(-1).value,'STOP');
const failed = fixture();
failed.click('.vck-health-scan'); failed.requests.shift().reject();
assert.equal(failed.nodes.get('.vck-health-migrate').props.disabled,true,'Failed scan cannot authorize write');
assert.equal(failed.nodes.get('.vck-health-scan').props.disabled,false,'Operator can retry scan');
const reviewOnly = fixture();
reviewOnly.click('.vck-health-scan');
reviewOnly.resolve({remaining:0,addressesReview:2,customerAddressesReview:1,report:'Review needed'});
assert.equal(reviewOnly.nodes.get('.vck-health-assistant').props.hidden,true,'Review-only result never reveals assistant');
assert.equal(reviewOnly.nodes.get('.vck-health-scan-result').props.hidden,false,'Review-only result shows compact state');
assert.equal(reviewOnly.nodes.get('.vck-health-scan-result').value,'REVIEW ONLY','Review-only result explains manual review');
reviewOnly.click('.vck-health-migrate');
assert.equal(reviewOnly.requests.length,0,'Review-only result cannot start migration');
const empty = fixture();
empty.click('.vck-health-scan');
empty.resolve({remaining:0,addressesReview:0,customerAddressesReview:0,report:'Empty'});
assert.equal(empty.nodes.get('.vck-health-assistant').props.hidden,true,'Empty scan never reveals assistant');
assert.equal(empty.nodes.get('.vck-health-scan-result').props.hidden,false,'Empty scan shows compact state');
assert.equal(empty.nodes.get('.vck-health-scan-result').value,'NO DATA','Empty scan shows neutral message');
console.log('PASS: migration assistant UI scan/confirmation/batch/post-validation/error contracts.');
