import {readFileSync} from 'node:fs';import assert from 'node:assert/strict';
const file=readFileSync(new URL('../public/index.php',import.meta.url),'utf8');
const landing=readFileSync(new URL('../public/landing.php',import.meta.url),'utf8');
assert.match(file,/\$requestedPath/);
assert.match(file,/require __DIR__\.'\/landing\.php'/);
for(const section of ['recursos','como-funciona','planos'])
 assert.ok(landing.includes('id="'+section+'"'),section+' is missing');
assert.ok(landing.includes('/dashboard'),'Login should remain available');
assert.doesNotMatch(landing,/\b(?:IA generativa|assistente de IA|agenda integrada)\b/i);
console.log('Sales landing routes and sections OK');
