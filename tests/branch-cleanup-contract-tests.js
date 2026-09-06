'use strict';

// Exercise the actual inline workflow code with mocked GitHub APIs; never delete real refs.
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');

const root = path.resolve(__dirname, '..');
const workflow = fs.readFileSync(path.join(root, '.github/workflows/cleanup-agent-branch.yml'), 'utf8');
const marker = '          script: |\n';
assert.equal(workflow.split(marker).length, 2, 'exactly one inline cleanup step');
const [preamble, indented] = workflow.split(marker);
// Constrain the whole workflow envelope: closed-only trigger, one pinned action, no
// checkout/shell/PR-code execution, no additional jobs, credentials, or write scopes.
assert.equal(preamble, `name: Clean up merged agent branch

on:
  pull_request:
    types: [closed]

permissions:
  contents: write
  pull-requests: read

jobs:
  cleanup:
    name: Selective task branch cleanup
    runs-on: ubuntu-latest
    timeout-minutes: 5
    steps:
      - name: Check eligibility and delete the exact merged head
        uses: actions/github-script@ed597411d8f924073f98dfc5c65a23a2325f34cd # v8
        with:
`);
const script = indented.trimEnd().split('\n').map((line) => {
  assert.ok(!line.trim() || line.startsWith('            '), 'no steps/jobs after inline code');
  return line.slice(12);
}).join('\n');
assert.ok(!script.includes('${{'), 'untrusted expressions must not be interpolated into code');
const compiled = new vm.Script(`(async () => {\n${script}\n})()`);
const ci = fs.readFileSync(path.join(root, '.github/workflows/ci.yml'), 'utf8');
assert.ok(ci.includes('run: node tests/branch-cleanup-contract-tests.js'), 'required CI must run this suite');
const policy = fs.readFileSync(path.join(root, 'AGENTS.md'), 'utf8');
for (const rule of ['disposable task branches', 'All `release/*` branches are retained',
  '`delete_branch_on_merge` setting disabled', 'Closed-but-unmerged', 'HUMAN_DECISION_REQUIRED']) {
  assert.ok(policy.includes(rule), `missing lifecycle policy: ${rule}`);
}

const sha = 'a'.repeat(40);
function fixture() {
  const repository = { id: 1, full_name: 'owner/repo', default_branch: 'main' };
  return {
    eventName: 'pull_request', repo: { owner: 'owner', repo: 'repo' },
    payload: {
      action: 'closed', repository,
      pull_request: { number: 12, state: 'closed', merged: true,
        head: { ref: 'agent/task', sha, repo: { ...repository } } },
    },
  };
}
function apiError(status) {
  return Object.assign(new Error(`API error ${status}`), { status });
}
async function execute(context = fixture(), options = {}) {
  const calls = [];
  const logs = [];
  const params = { owner: 'owner', repo: 'repo' };
  const ref = context.payload.pull_request?.head?.ref;
  let reads = 0;
  const github = {
    rest: {
      pulls: { list: Symbol('pulls.list') },
      repos: { getBranch: async (args) => {
        calls.push('getBranch');
        assert.equal(JSON.stringify(args), JSON.stringify({ ...params, branch: ref }));
        reads++;
        if (options.readError) throw apiError(options.readError);
        if (reads > 1 && options.missingAfterDelete) throw apiError(404);
        return { data: { name: ref, protected: false, commit: { sha }, ...options.branch } };
      } },
      git: { deleteRef: async (args) => {
        calls.push('deleteRef');
        assert.equal(JSON.stringify(args), JSON.stringify({ ...params, ref: `heads/${ref}` }));
        if (options.deleteError) throw apiError(options.deleteError);
      } },
    },
    paginate: async (endpoint, args) => {
      calls.push('listOpenPulls');
      assert.equal(endpoint, github.rest.pulls.list);
      assert.equal(JSON.stringify(args), JSON.stringify({
        ...params, state: 'open', head: `owner:${ref}`, per_page: 100,
      }));
      if (options.listError) throw apiError(options.listError);
      return options.openPulls || [];
    },
  };
  let error;
  try {
    await compiled.runInNewContext({ context, github, core: { info: (message) => logs.push(message) } });
  } catch (caught) {
    error = caught;
  }
  return { calls, logs, error };
}

(async () => {
  let count = 0;
  const pass = await execute();
  assert.ifError(pass.error);
  assert.deepEqual(pass.calls, ['listOpenPulls', 'getBranch', 'deleteRef']);
  assert.match(pass.logs[0], /Deleted merged task branch owner\/repo: heads\/agent\/task/);
  count++;

  const ineligible = [
    ['unmerged', (c) => { c.payload.pull_request.merged = false; }],
    ['truthy non-boolean merged', (c) => { c.payload.pull_request.merged = 'true'; }],
    ['open state', (c) => { c.payload.pull_request.state = 'open'; }],
    ['reopened action', (c) => { c.payload.action = 'reopened'; }],
    ['wrong event', (c) => { c.eventName = 'workflow_dispatch'; }],
    ['missing PR', (c) => { delete c.payload.pull_request; }],
    ['missing repository', (c) => { delete c.payload.repository; }],
    ['missing head', (c) => { delete c.payload.pull_request.head; }],
    ['deleted fork repository', (c) => { c.payload.pull_request.head.repo = null; }],
    ['fork ID', (c) => { c.payload.pull_request.head.repo.id = 2; }],
    ['fork name', (c) => { c.payload.pull_request.head.repo.full_name = 'fork/repo'; }],
    ['wrong target repository', (c) => { c.payload.repository.full_name = 'other/repo'; }],
    ['missing default branch', (c) => { delete c.payload.repository.default_branch; }],
    ['agent default branch', (c) => { c.payload.repository.default_branch = 'agent/task'; }],
    ['missing SHA', (c) => { delete c.payload.pull_request.head.sha; }],
    ['malformed SHA', (c) => { c.payload.pull_request.head.sha = 'not-a-sha'; }],
  ];
  for (const ref of [null, 42, '', 'main', 'release/1.2.0', 'feature/task', 'refs/heads/agent/task',
    'agent/', 'agent//task', 'agent/../main', 'agent/.hidden', 'agent/task.lock', 'agent/task.',
    'agent/task/', 'agent/task@{1}', 'agent/task\n', 'agent/task;echo', 'agent/task$(id)', 'agent/a%2fb']) {
    ineligible.push([`invalid/retained ref ${JSON.stringify(ref)}`, (c) => { c.payload.pull_request.head.ref = ref; }]);
  }
  for (const [label, mutate] of ineligible) {
    const context = fixture();
    mutate(context);
    const result = await execute(context);
    assert.ifError(result.error);
    assert.deepEqual(result.calls, [], label);
    assert.match(result.logs[0], /^Skip branch cleanup: .+/, label);
    count++;
  }
  for (const options of [
    { openPulls: [{ number: 99 }] },
    { readError: 404 },
    { branch: { protected: true } },
    { branch: { protected: undefined } },
    { branch: { name: 'release/1.2.0' } },
    { branch: { commit: { sha: 'b'.repeat(40) } } },
    { branch: { commit: null } },
  ]) {
    const result = await execute(fixture(), options);
    assert.ifError(result.error);
    assert.ok(!result.calls.includes('deleteRef'));
    assert.match(result.logs[0], /^Skip branch cleanup: .+/);
    count++;
  }
  for (const options of [{ listError: 403 }, { listError: 500 }, { readError: 403 }, { readError: 500 }]) {
    const result = await execute(fixture(), options);
    assert.ok(result.error, 'API read failures must fail closed');
    assert.ok(!result.calls.includes('deleteRef'));
    count++;
  }
  for (const status of [403, 404, 422, 500]) {
    const result = await execute(fixture(), { deleteError: status });
    assert.ok(result.error, 'delete errors must not be silently ignored while ref exists');
    assert.equal(result.calls.filter((call) => call === 'deleteRef').length, 1);
    count++;
  }
  for (const status of [404, 422]) {
    const result = await execute(fixture(), { deleteError: status, missingAfterDelete: true });
    assert.ifError(result.error);
    assert.match(result.logs[0], /removed concurrently/);
    count++;
  }
  const nested = fixture();
  nested.payload.pull_request.head.ref = 'agent/1.2.0/nested-task';
  const result = await execute(nested);
  assert.ifError(result.error);
  assert.ok(result.calls.includes('deleteRef'));
  count++;
  console.log(`PASS: ${count} branch cleanup cases; constrained workflow, exact-ref deletion and fail-closed guards.`);
})().catch((error) => { console.error(error); process.exitCode = 1; });
