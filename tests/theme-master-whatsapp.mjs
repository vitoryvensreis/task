import assert from 'node:assert/strict';
import {readFileSync} from 'node:fs';
const asset=(p)=>readFileSync(new URL('../'+p,import.meta.url),'utf8');
const pages=['public/index.php','public/master.php','public/landing.php','public/planos.php','public/convite.php','public/install.php'];
for(const path of pages){
 assert.match(asset(path),/taskesc-theme\.css/,path+' does not load shared identity');
}
const css=asset('public/assets/taskesc-theme.css');
for(const name of ['--te-bg','--te-primary','.landing-page','.master-page','.plans-page','.invite-page','.install-page']){
 assert.ok(css.includes(name),'Visual token or surface missing: '+name);
}
const script=asset('public/assets/master-settings.js');
for(const action of ['master.wpp_status','master.wpp_start','master.wpp_qr','master.wpp_close','master.wpp_disconnect']){
 assert.ok(script.includes(action),'WhatsApp Master control missing: '+action);
}
assert.match(script,/wpp\.prepend\(wppTools\)/,'Live connection state must appear before technical settings');
assert.match(script,/confirmMaster\('master\.wpp_disconnect'/,'Phone unlink must use Master confirmation');
assert.match(script,/setInterval\(/,'Connection status must refresh while visible');
console.log('TaskEsc unified theme + protected Master WhatsApp console: OK');
