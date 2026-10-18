import {readFileSync} from 'node:fs';
import assert from 'node:assert/strict';
const js=readFileSync(new URL('../public/assets/app.js', import.meta.url),'utf8');
const route=/\{dashboard:renderDashboard,kanban:renderKanban,[^\n]*reports:renderReports,[^\n]*\}\[page\]/;
assert.match(js,route,'Main app route map must include reports');
for(const name of ['renderDashboard','renderKanban','renderDemandList','renderClients','renderContacts','renderServices','renderFinance','renderReports','renderAutomations','renderSettings','reportTable']){
 assert.match(js,new RegExp('(?:async\\s+)?function\\s+'+name+'\\s*\\('),'u','Required view function is missing: '+name);
}
assert.doesNotMatch(js,/function\s+renderAgenda\s*\(/,'Agenda must remain retired');
console.log('Frontend required views OK');
