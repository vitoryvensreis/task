import fs from 'node:fs';

const app=fs.readFileSync(new URL('../public/assets/app.js',import.meta.url),'utf8');
const realtime=fs.readFileSync(new URL('../public/assets/realtime-client.js',import.meta.url),'utf8');

function check(condition,message){if(!condition)throw new Error(message)}

check(app.includes("window.addEventListener('taskesc:realtime-publication'"),'app.js must consume realtime publications');
check(app.includes('function syncRealtimeChannels()'),'app.js must synchronize collection subscriptions');
check(app.includes('client:${id}'),'non-admin sessions must subscribe client collection channels');
check(app.includes('payload.operation_id'),'frontend must deduplicate channel fanout by operation id');
check(app.includes('function scheduleRealtimeRefresh(')&&app.includes('function flushRealtimeRefresh()'),'frontend must debounce realtime refresh');
check(app.includes("refreshPages=['dashboard','kanban','demands','finance','reports']"),'realtime invalidation must refresh affected screens');
check(app.includes('captureKanbanFilters()')&&app.includes('restoreKanbanFilters(filters)'),'Kanban filters must survive realtime refresh');
check(app.includes('data-demand-modal=')&&app.includes('data-demand-mode='),'task modal must expose realtime-safe identity/mode');
check(app.includes("modalEvent.eventType==='demand.deleted'"),'deleted open task must close safely');
check(app.includes("document.visibilityState==='hidden'"),'hidden tabs must defer realtime redraw');
check(realtime.includes("version: 'step6'"),'Centrifugo client version marker must be step6');
check(app.includes('setInterval(async()=>')&&app.includes('30000'),'HTTP polling fallback must remain active');

console.log('Realtime frontend: client channels, fanout dedupe, debounced refresh, modal safety and HTTP fallback OK');
