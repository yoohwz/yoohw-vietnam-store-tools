/* Follow native WooCommerce first-login onboarding without bypassing its hooks. */
const assert = require('node:assert/strict');
module.exports = async function openAdminSession(request, base) {
  let target = '/wp-admin/';
  for (let hop = 0; hop < 4; hop++) {
    const result = await request('GET', target);
    if (result.response.status === 200) return;
    assert.ok([301, 302, 303].includes(result.response.status), 'Native admin navigation must succeed');
    const location = result.response.headers.get('location');
    assert.ok(location, 'Admin redirect supplies a location');
    const url = new URL(location, base);
    assert.equal(url.origin, new URL(base).origin, 'Admin redirects remain on the selected fixture');
    assert.ok(['/wp-admin/', '/wp-admin/index.php', '/wp-admin/admin.php'].includes(url.pathname), 'Admin navigation cannot redirect to login or another surface');
    assert.ok([null, 'wc-admin'].includes(url.searchParams.get('page')), 'Only native WooCommerce onboarding is followed');
    assert.ok([null, '/setup-wizard'].includes(url.searchParams.get('path')), 'Only native onboarding path is followed');
    target = url.href;
  }
  throw new Error('Native admin redirect limit exceeded');
};
