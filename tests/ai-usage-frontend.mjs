import fs from 'node:fs';
import assert from 'node:assert/strict';
const tenant=fs.readFileSync('public/assets/app.js','utf8');
const master=fs.readFileSync('public/assets/master.js','utf8');
const ops=fs.readFileSync('public/assets/master-operations.js','utf8');
const routes=fs.readFileSync('public/.htaccess','utf8');
const api=fs.readFileSync('public/api.php','utf8');
const stylesheet=fs.readFileSync('public/assets/master-dashboard.css','utf8');
assert.ok(!tenant.includes("['ai-monitor','bot','Monitoramento IA'"),
 'Tenant administrators must not see platform AI monitoring in the menu.');
assert.ok(!tenant.includes("['logs','activity','Saúde & Logs'"),
 'Tenant administrators must not see platform health/logs in the menu.');
assert.ok(tenant.includes("page==='logs'||page==='ai-monitor'"),'Deep links must be blocked for tenants.');
for(const token of ["ai:'master/monitoramento-ia'","health:'master/saude-logs'","TaskEscMasterOperations.ai","TaskEscMasterOperations.health"])
 assert.ok(master.includes(token),'Master navigation/rendering missing: '+token);
for(const token of ['master.ai_usage','master.health','master.logs','master.ai_policy_save'])
 assert.ok(api.includes(token),'Master API missing: '+token);
assert.ok(api.includes("fail('Disponível somente no ADM Master.',403)"),
 'Old tenant diagnostic APIs must deny access.');
assert.ok(routes.includes('monitoramento-ia|saude-logs'),'Master-friendly diagnostics routes required.');
for(const token of ['master-stats','master-bars','master-ops-panel','master-ops-tabs'])
 assert.ok(stylesheet.includes('.'+token),'Monitoring/health style missing: '+token);
assert.ok(ops.includes("request(base,'master.ai_usage'"),'Master must load AI usage.');
assert.ok(ops.includes("request(base,'master.logs'"),'Master must load system logs.');
assert.ok(!ops.includes('console.log'),'Do not log private telemetry in the browser.');
console.log('Master-only AI and health UI guards, endpoints and charts OK');
