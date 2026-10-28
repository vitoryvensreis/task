import {readFileSync} from 'node:fs';
import assert from 'node:assert/strict';

const src = path => readFileSync(new URL('../'+path,import.meta.url),'utf8');
const landing = src('public/landing.php');
const catalog = src('app/Views/public-planos.php');
const checkout = src('app/Views/public-contratar.php');
const front = src('public/index.php');
const apache = src('public/.htaccess');
const mail = src('app/SelfServiceCheckout.php');
const oldCatalog = src('public/planos.php');
const oldCheckout = src('public/contratar.php');

// Each plan must go directly to checkout, including its ID, on the first click.
assert.match(landing, /btn btn-plan[^\n]*base\('contratar\?plan_id='/);
assert.doesNotMatch(landing, /href=[^>]*\/planos\.php/);
assert.match(catalog, /class="request"[^\n]*base\('contratar\?plan_id='/);
assert.doesNotMatch(catalog, /base\('contratar\.php/);

// The same server-side router handles public views through both Apache and proxy front controllers.
assert.match(front, /\$publicPath===\x27\/planos\x27/);
assert.match(front, /app\/Views\/public-planos\.php/);
assert.match(front, /app\/Views\/public-contratar\.php/);
assert.match(apache, /RewriteRule\s+\^\(\?:planos\|contratar\)\/\?\$\s+index\.php/);
assert.match(catalog, /ROOT\.\x27\/public\/assets\/taskesc-theme\.css\x27/);

// Historical physical URLs redirect, rather than render a second flow.
assert.match(oldCatalog, /Location: '\.base\('planos'\)/);
assert.match(oldCheckout, /Location: '\.base\('contratar'\)/);
assert.match(oldCheckout, /\$_SERVER\['QUERY_STRING'\]/);
assert.match(checkout, /base\('planos'\)/);
assert.match(mail, /base\('contratar\?token='/);

console.log('Public single-click checkout and clean routes OK');
