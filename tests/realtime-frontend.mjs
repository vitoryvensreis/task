import fs from 'node:fs';

const app=fs.readFileSync(new URL('../public/assets/app.js',import.meta.url),'utf8');
const realtime=fs.readFileSync(new URL('../public/assets/realtime-client.js',import.meta.url),'utf8');
const index=fs.readFileSync(new URL('../public/index.php',import.meta.url),'utf8');
const sw=fs.readFileSync(new URL('../public/service-worker.js',import.meta.url),'utf8');
const icons=fs.readFileSync(new URL('../public/assets/taskesc-icons.js',import.meta.url),'utf8');

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
check(!app.includes("chat:'chat'"),'chat must not be exposed as a page route');
check(app.includes("window.addEventListener('taskesc:realtime-publication'")&&app.includes("startsWith('chat.')"),'admin chat must consume realtime publications');
check(app.includes("api('chat.contacts')")&&app.includes("api('chat.messages'")&&app.includes("api('chat.send'"),'admin chat frontend must use authenticated chat endpoints');
check(app.includes("admin-chat-fab")&&app.includes("admin-chat-popup")&&app.includes("chat-fab-badge"),'admin chat must render as floating popup with unread badge');
check(app.includes("Pesquisar administrador")&&app.includes("data-chat-peer"),'admin popup must include contact search and contact list');
check(app.includes("handleChatRealtime(publication)"),'admin chat realtime handler must be wired');
check(index.includes('id="admin-chat-root"')&&index.includes('id="admin-chat-fab"')&&index.includes('id="admin-chat-popup"'),'floating admin chat root must exist');
check(index.includes("$appCssVersion=is_file(ROOT.'/public/assets/app.css')?filemtime"),'chat CSS must use cache busting');
check(index.includes('data-lucide="message-circle"'),'floating chat button must use a conversation bubble icon');
check(icons.includes("'message-circle':")&&icons.includes("settings:'<path"),'custom icons must provide conversation bubble and gear settings icon');
check(app.includes("event.target.closest('[data-chat-peer]')"),'mobile contact selection must use delegated click handling');
check(app.includes('function showChatConversationView()')&&app.includes('function showChatContactsView()'),'mobile chat must switch explicitly between contacts and conversation');
check(app.includes("(max-width:820px)"),'chat mobile behavior must cover phones and small tablets');
check(app.includes("api('chat.tasks'")&&app.includes('scheduleChatTaskSuggestions()'),'chat composer must autocomplete @task mentions over authenticated HTTP');
check(app.includes('CHAT_EMOJIS')&&app.includes('chat-emoji-picker')&&app.includes('data-chat-emoji'),'chat composer must expose an emoji picker without an external CDN');
check(app.includes('task_mentions:taskMentions'),'chat send must persist only selected task references still present in the message');
check(app.includes('data-chat-demand')&&app.includes('openDemand(id)'),'linked tasks in chat messages must be clickable');
check(app.includes("new URLSearchParams(location.search).get('chat')")&&app.includes('openAdminChat(requestedChatPeer)'),'push deep links must open the sender conversation');
check(app.includes('appUnread=unread+(adminChatEnabled()?Number(S.chatUnread||0):0)'),'app badge must combine normal notifications with unread admin chat');
check(app.includes("String(publication.event_type||'').startsWith('demand.')"),'all demand sub-resource events must share the demand invalidation path');
check(app.includes("startsWith('wpp.')")&&app.includes('handleWppRealtime(publication)'),'frontend must route WPPConnect lifecycle events from Centrifugo');
check((app.match(/name="whatsapp_phone"/g)||[]).length>=2,'User and client forms must collect WhatsApp numbers.');
check(app.includes('name="whatsapp_recipient_mode"')&&app.includes('Quem criou a task')&&app.includes('Não enviar para esta task'),'Task form must choose creator, client or none for WhatsApp.');





check(app.includes('<option value="creator"')&&!app.includes('<option value="assigned_user"'),'Task-level WhatsApp selector must target creator/client/none; responsible is available only as an automation-action override.');

console.log('Realtime frontend: client channels, fanout dedupe, debounced refresh, creator WhatsApp recipient, modal safety and HTTP fallback OK');
