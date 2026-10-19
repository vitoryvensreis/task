import {readFileSync} from 'node:fs';
import assert from 'node:assert/strict';
import {spawnSync} from 'node:child_process';
const rules=readFileSync(new URL('../public/.htaccess',import.meta.url),'utf8');
const page=readFileSync(new URL('../public/master.php',import.meta.url),'utf8');
const js=readFileSync(new URL('../public/assets/master.js',import.meta.url),'utf8');
assert.match(rules,/master\(\?:\/\(\?:[\s\S]*whatsapp[\s\S]*\)\(\?:\/\[\^\.\]\*\)\?\)/,'Master nested routes must be rewritten');
assert.match(page,/\$relative=preg_replace/,'Page must resolve the primary nested section');
assert.match(js,/const section=relative\.split\('\/'\)\[0\]/,'Client router must resolve nested paths');
assert.match(js,/window\.addEventListener\('popstate'/,'Back/forward should keep the current Master page');
assert.match(rules,/master\.php \[END,QSA\]/,'Deep links must be internal rewrites, not visible redirects');
assert.doesNotMatch(js,/if\(location\.pathname\.endsWith\('\/master\.php'\)\)history\.replaceState/,'Client must not mask server redirects');
// Exercise the real PHP front controller, not only static Apache rewrite rules.
// This reproduces Nginx/VPS try_files passing friendly URLs to index.php.
function renderThroughFrontController(path){
 const code='$_SERVER["REQUEST_URI"]='+JSON.stringify(path)+'; include "public/index.php";';
 const result=spawnSync('php',['-r',code],{cwd:new URL('..',import.meta.url),encoding:'utf8',timeout:15000});
 assert.equal(result.status,0,'PHP front controller failed for '+path+': '+result.stderr);
 return result.stdout;
}
const deepPlan=renderThroughFrontController('/master/planos');
assert.match(deepPlan,/<title>TaskEsc · ADM Master<\\/title>/,'Master plan URL must render the correct PHP document');
assert.match(deepPlan,/data-initial-tab="plans"/,'Master plan URL must render the plans tab');
assert.doesNotMatch(deepPlan,/id="app" class="app hidden"/,'Master plan URL must not return ordinary workspace dashboard');
assert.match(renderThroughFrontController('/master'),/data-initial-tab="overview"/,'Root Master link must load Master');
assert.match(renderThroughFrontController('/dashboard'),/id="app" class="app hidden"/,'Tenant dashboard must stay intact');
console.log('Master deep link regression: OK');
