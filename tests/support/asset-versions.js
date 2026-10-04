/* Verify rendered plugin URLs against the exact candidate's plugin header. */
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const source = fs.readFileSync(path.join(__dirname, '../../yoohw-vietnam-store-tools.php'), 'utf8');
const version = source.match(/^\s*\* Version:\s*(\S+)/m)[1];

module.exports = function checkAssetVersions(html) {
  const paths = [];
  for (const match of html.matchAll(/(?:src|href)=["']([^"']+)["']/g)) {
    const url = new URL(match[1].replaceAll('&amp;', '&'), 'https://fixture.test');
    if (!/\/wp-content\/plugins\/(?:yoohw-vietnam-store-tools|vst94-release-candidate)\/(?:assets|blocks)\/.*\.(?:js|css)$/.test(url.pathname)) continue;
    assert.equal(url.searchParams.get('ver'), version, `Plugin asset URL uses release version: ${url.pathname}`);
    paths.push(url.pathname);
  }
  return paths;
};
