import fs from 'node:fs';
import assert from 'node:assert/strict';
const src=fs.readFileSync('public/assets/app.js','utf8');
const css=fs.readFileSync('public/assets/app.css','utf8');
const routes=fs.readFileSync('public/.htaccess','utf8');
const api=fs.readFileSync('public/api.php','utf8');
for(const token of [
 "'ai-monitor':'monitoramento-ia'",
 "['ai-monitor','bot','Monitoramento IA'",
 "'ai-monitor':renderAiMonitor",
 "async function renderAiMonitor()",
 "api('ai.usage_dashboard',{days:f.days,provider:f.provider})",
 "S.user?.role!=='admin'",
 "S.user?.is_preview",
 "const routeVisible=f.provider==='all'",
 "Tokens não reportados não são estimados",
 "Nenhuma chamada registrada no período",
 "go('ai-monitor',false,false,true)"
])assert.ok(src.includes(token),'Monitoring interface missing: '+token);
assert.ok(routes.includes('monitoramento-ia'),'Direct monitor URL rewrite required');
assert.ok(api.includes("'ai.usage_dashboard'=>AiWhatsAppUsageDashboard::read($p)"),'Protected API required');
assert.ok(css.includes('.ai-monitor-stats')&&css.includes('@media(max-width:620px)'),'Responsive dashboard rules required');
assert.ok(!src.includes('console.log(d)'), 'Do not log telemetry payload to browser console');
console.log('AI usage frontend route/permissions/filters/chart: OK');
