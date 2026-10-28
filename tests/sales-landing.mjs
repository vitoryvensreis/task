import {readFileSync} from 'node:fs';import assert from 'node:assert/strict';
const file=readFileSync(new URL('../public/index.php',import.meta.url),'utf8');
const landing=readFileSync(new URL('../public/landing.php',import.meta.url),'utf8');
assert.match(file,/\$requestedPath/);
assert.match(file,/require __DIR__\.'\/landing\.php'/);
for(const section of ['recursos','como-funciona','planos'])
 assert.ok(landing.includes('id="'+section+'"'),section+' is missing');
assert.ok(landing.includes('/dashboard'),'Login should remain available');
assert.doesNotMatch(landing,/\b(?:IA generativa|assistente de IA|agenda integrada)\b/i);
const legal=readFileSync(new URL('../public/legal.php',import.meta.url),'utf8');
const publicRoutes=readFileSync(new URL('../public/.htaccess',import.meta.url),'utf8');
const styles=readFileSync(new URL('../public/assets/legal.css',import.meta.url),'utf8');
for(const route of ['privacidade','termos-de-uso']){
 assert.ok(file.includes("'"+route+"'"),route+' must be routed by index.php even behind reverse proxies');
 assert.ok(publicRoutes.includes(route),route+' must be handled by Apache');
 assert.ok(landing.includes('/'+route),route+' must be linked from the public landing');
 assert.ok(legal.includes('/'+route),route+' must be linked from legal pages');
}
assert.ok(file.indexOf("require __DIR__.'/legal.php'")<file.indexOf('Auth::load(false)'),'Legal pages must be accessible without login/session check');
assert.ok(legal.includes('Escotilha Produtora'),'TaskEsc must identify its operating brand');
for(const scope of ['drive.file','openid','email'])assert.ok(legal.includes(scope),'Privacy policy must disclose Google scope '+scope);
assert.ok(legal.includes('Conta Google')&&legal.includes('revogação'),'Privacy policy must explain Google disconnect/revocation');
assert.ok(styles.includes('@media(max-width:640px)'),'Legal pages need mobile layout');
console.log('Sales landing routes and sections OK');
