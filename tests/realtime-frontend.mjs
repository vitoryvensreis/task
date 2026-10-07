import fs from 'node:fs';

const app=fs.readFileSync(new URL('../public/assets/app.js',import.meta.url),'utf8');
const realtime=fs.readFileSync(new URL('../public/assets/realtime-client.js',import.meta.url),'utf8');
const index=fs.readFileSync(new URL('../public/index.php',import.meta.url),'utf8');
const sw=fs.readFileSync(new URL('../public/service-worker.js',import.meta.url),'utf8');

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
check(index.includes("$appJsVersion=is_file(ROOT.'/public/assets/app.js')?filemtime"),'app.js must use filemtime cache busting');
check(index.includes("$realtimeJsVersion=is_file(ROOT.'/public/assets/realtime-client.js')?filemtime"),'realtime client must use filemtime cache busting');
check(sw.includes("cache:'reload'")&&sw.includes("scriptOrStyle"),'service worker must prefer network for JS/CSS while online');
check(sw.includes("taskesc-shell-v3.27.0-realtime6"),'service worker cache generation must be bumped');
check(app.includes("chat:'chat'"),'chat route must be available');
check(app.includes("window.addEventListener('taskesc:realtime-publication'")&&app.includes("startsWith('chat.')"),'chat must consume realtime publications');
check(app.includes("api('chat.contacts')")&&app.includes("api('chat.messages'")&&app.includes("api('chat.send'"),'chat frontend must use authenticated chat endpoints');
check(app.includes("data-chat-peer")&&app.includes("chat-nav-badge"),'chat contacts and unread badge must be rendered');
check(app.includes("handleChatRealtime(publication)"),'chat realtime handler must be wired');
check(index.includes("$appCssVersion=is_file(ROOT.'/public/assets/app.css')?filemtime"),'chat CSS must use cache busting');


console.log('Realtime frontend: client channels, fanout dedupe, debounced refresh, modal safety and HTTP fallback OK');
