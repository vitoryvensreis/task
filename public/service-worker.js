const CACHE='taskesc-shell-v3.12.0';
const scopeUrl=new URL('./',self.registration.scope);
const assetUrl=path=>new URL(path,scopeUrl).toString();
const SHELL=[
  assetUrl('offline.html'),
  assetUrl('assets/app.css?v=3.4.0'),
  assetUrl('assets/app.js?v=3.12.0'),
  assetUrl('assets/ui-fixes.css?v=3.10.3'),
  assetUrl('assets/ux-v3.css?v=3.12.0'),
  assetUrl('assets/ux-v3.js?v=3.12.0'),
  assetUrl('assets/taskesc-logo.svg')
];

self.addEventListener('install',event=>{
  event.waitUntil(caches.open(CACHE).then(cache=>cache.addAll(SHELL)).then(()=>self.skipWaiting()));
});

self.addEventListener('activate',event=>{
  event.waitUntil(caches.keys().then(keys=>Promise.all(keys.filter(k=>k!==CACHE).map(k=>caches.delete(k)))).then(()=>self.clients.claim()));
});

self.addEventListener('fetch',event=>{
  const request=event.request;
  if(request.method!=='GET')return;
  const url=new URL(request.url);
  if(url.origin!==self.location.origin)return;

  const sensitive=/\/(api\.php|api\/|drive-upload\.php|download\.php|download-all\.php|google|oauth|cron\.php|mcp\.php)/i.test(url.pathname);
  if(sensitive)return;

  if(request.mode==='navigate'){
    event.respondWith(fetch(request).catch(()=>caches.match(assetUrl('offline.html'))));
    return;
  }

  if(/\/(assets|icons)\//.test(url.pathname)){
    event.respondWith(caches.match(request).then(cached=>{
      const network=fetch(request).then(response=>{
        if(response&&response.ok){const copy=response.clone();caches.open(CACHE).then(cache=>cache.put(request,copy));}
        return response;
      }).catch(()=>cached);
      return cached||network;
    }));
  }
});

self.addEventListener('push',event=>{
  event.waitUntil((async()=>{
    let payload={};
    try{if(event.data)payload=event.data.json()||{}}catch{try{payload={body:event.data?.text()||''}}catch{}}
    const title=String(payload.title||'TaskEsc');
    const body=String(payload.body||'Você tem uma nova atualização no TaskEsc.');
    const target=String(payload.url||assetUrl(payload.demand_id?'kanban/task/'+Number(payload.demand_id):'dashboard'));
    const tag=String(payload.tag||('taskesc-'+String(payload.notification_id||'update')));
    await self.registration.showNotification(title,{
      body,
      tag,
      icon:assetUrl('assets/taskesc-logo.svg'),
      badge:assetUrl('assets/taskesc-logo.svg'),
      data:{url:target,notification_id:Number(payload.notification_id||0),demand_id:Number(payload.demand_id||0)},
      renotify:true
    });
    const count=Number(payload.badge_count||0);
    try{if(count>0&&self.navigator?.setAppBadge)await self.navigator.setAppBadge(count);else if(self.navigator?.clearAppBadge)await self.navigator.clearAppBadge()}catch{}
  })());
});

self.addEventListener('notificationclick',event=>{
  event.notification.close();
  event.waitUntil((async()=>{
    const target=event.notification.data?.url||assetUrl('dashboard'),all=await clients.matchAll({type:'window',includeUncontrolled:true});
    for(const client of all){if('focus' in client){try{await client.navigate(target)}catch{}return client.focus();}}
    return clients.openWindow?clients.openWindow(target):undefined;
  })());
});
