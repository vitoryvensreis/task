import assert from 'node:assert/strict';
import {readFileSync} from 'node:fs';

const js=readFileSync('public/assets/master.js','utf8');
const css=readFileSync('public/assets/master-dashboard.css','utf8');
const begin=js.indexOf('  const mk=');
const end=js.indexOf('  async function draw(tab){',begin);
assert.ok(begin>=0&&end>begin,'The Master dashboard renderer must exist.');
const renderer=js.slice(begin,end);
assert.match(renderer,/const mk=\(tag,cls='',text=''\)=>el\(tag,text,cls\)/,
 'Dashboard must explicitly adapt to the legacy DOM helper argument order.');
assert.match(renderer,/mk\('article','master-kpi'\)/,
 'KPI cards must apply their CSS class, not print it.');
assert.doesNotMatch(renderer.slice(renderer.indexOf('\n')+1),/\bel\(/,
 'Dashboard rendering must use mk(tag,className,text), not the old text-first helper.');
for(const name of ['master-kpi','master-dashboard-grid','master-bars','master-split-row']){
 assert.ok(css.includes('.'+name),'Missing stylesheet class: '+name);
}
console.log('Master dashboard DOM/CSS contract OK');
