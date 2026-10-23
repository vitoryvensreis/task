import fs from 'node:fs';
import vm from 'node:vm';
import assert from 'node:assert/strict';
const read=p=>fs.readFileSync(new URL(p,import.meta.url),'utf8');
const code=fs.readFileSync(new URL('../public/assets/realtime-client.js',import.meta.url),'utf8');
const app=fs.readFileSync(new URL('../public/assets/app.js',import.meta.url),'utf8');
const auth=fs.readFileSync(new URL('../app/RealtimeDiagnostics.php',import.meta.url),'utf8');
const api=fs.readFileSync(new URL('../public/api.php',import.meta.url),'utf8');
assert(code.includes("basePath + '/realtime-auth.php'"),'Browser authentication must bypass friendly rewrite URL');
assert(!code.includes("basePath + '/realtime.auth'"),'Do not use rewrite-dependent realtime auth route');
assert(app.includes('WebSocket offline · HTTP')&&app.includes("const label=live?'Sistema Online':"),'Realtime indicator must display Sistema Online only when the WebSocket connection and authorized channel are active');
assert(!app.includes("'<i></i> Tempo real · '"),'HTTP polling must never impersonate WebSocket');
assert(app.includes("if(S.page==='kanban'?realtimeEditing():userIsInteracting())return;"),'Idle Kanban search focus must not block polling');
assert(app.includes("if(S.page==='kanban')await stableRender(refresh);else await refresh();"),'HTTP fallback must preserve Kanban search focus and scroll');

assert(api.includes("'realtime.health'=>")&&api.includes("'realtime.probe'=>"),'Admin diagnostics routes must exist');
assert(auth.includes('Auth::admin()')&&auth.includes('EventOutbox::transaction'),'Probe must be admin-only and use transactional Outbox');
assert(auth.includes('FROM event_outbox WHERE workspace_id=?'),'Tenant admins must not see other workspaces queue volume');
assert(auth.includes("WorkspaceTenantMode::enabled()?'kanban/ws-'"),'Probe must use tenant-scoped Kanban channel');
const realtimeAuth=read('../app/RealtimeAuth.php');
const demandRealtime=read('../app/DemandRealtime.php');
const automationRealtime=read('../app/AutomationEvent.php');
const probe=read('../app/RealtimeCliProbe.php');
for(const [source,name] of [[realtimeAuth,'JWT channels'],[demandRealtime,'demand publish'],[automationRealtime,'automation publish'],[probe,'CLI probe']]){
  assert(!source.includes('kanban/ws:')&&!source.includes('staff/ws:')&&!source.includes('diagnostic/ws:'),name+' must not use invalid Centrifugo namespaces');
}
assert(probe.includes("'kanban/ws-'.$ws")&&probe.includes("'kanban/global'"),'CLI must test the actual Kanban channel');
assert(realtimeAuth.includes("kanban/ws-")&&demandRealtime.includes("kanban/ws-"),'Backend and client must use the same workspace channel');
const events=[],urls=[],fakeSubscriptions=[];
class Centrifuge {
 constructor(url,options){this.url=url;this.options=options;this.handlers={}}
 on(type,cb){this.handlers[type]=cb}
 newSubscription(channel,opts){
  const handlers={};
  const sub={channel,on(type,cb){handlers[type]=cb},subscribe(){handlers.subscribed?.()},unsubscribe(){handlers.unsubscribed?.()}};
  fakeSubscriptions.push({channel,opts,sub});return sub;
 }
 connect(){this.handlers.connected?.({})}
 disconnect(){this.handlers.disconnected?.({code:0})}
}
const elements={app:{classList:{contains:()=>false}}};
const context={
 console:{debug(){}},document:{getElementById:id=>elements[id]||null,body:{dataset:{basePath:''}}},
 window:{Centrifuge,console:{debug(){}},setTimeout,clearTimeout,CustomEvent:class {constructor(type,init){this.type=type;this.detail=init.detail}},dispatchEvent:e=>events.push(e)},
 MutationObserver:class{observe(){}},
 fetch:async url=>{urls.push(url);return {ok:true,status:200,json:async()=>({enabled:true,token:'fake',websocket:'wss://example.invalid/realtime/connection/websocket',channels:['kanban/ws-3']})}},
 CustomEvent:class {constructor(type,init){this.type=type;this.detail=init.detail}}
};
vm.runInNewContext(code,context,{timeout:2000});
context.window.TaskEscRealtime.setCsrf('fake-csrf');
await new Promise(resolve=>setTimeout(resolve,25));
assert.equal(urls[0],'/realtime-auth.php','Auth request must reach direct PHP path');
assert.equal(context.window.TaskEscRealtime.status(),'connected','Client should connect successfully');
assert.equal(context.window.TaskEscRealtime.health().channels[0],'kanban/ws-3','Kanban subscription must be tracked as active');
assert(events.some(e=>e.type==='taskesc:realtime-state'&&e.detail.state==='subscribed'),'Subscribing should emit connection state');
context.window.TaskEscRealtime.subscribe('client:4');
assert.equal(fakeSubscriptions.length,2,'Dynamically subscribed channels must work');
context.window.TaskEscRealtime.unsubscribe('client:4');
assert(!context.window.TaskEscRealtime.health().channels.includes('client:4'),'Removed channels should not appear active');
console.log('Realtime diagnosis: direct auth, active channel state, health and admin E2E probe wiring OK');
