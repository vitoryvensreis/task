const CACHE='taskesc-shell-v3.0.0';
const scopeUrl=new URL('./',self.registration.scope);
const assetUrl=path=>new URL(path,scopeUrl).toString();
const SHELL=[
  assetUrl('offline.html'),
  assetUrl('assets/app.css?v=3.0.0'),
  assetUrl('assets/app.js?v=3.0.0'),
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
    let title='TaskEsc',body='Você tem uma nova atualização no TaskEsc.',target=assetUrl('dashboard'),tag='taskesc-update';
    try{
      const response=await fetch(assetUrl('api.php?action=notifications'),{credentials:'include',cache:'no-store',headers:{Accept:'application/json'}});
      if(response.ok){
        const data=await response.json(),item=Array.isArray(data)?data[0]:null;
        if(item){title=item.title||title;body=item.body||body;tag='taskesc-'+String(item.id||'update');if(item.demand_id)target=assetUrl('kanban/task/'+Number(item.demand_id));}
      }
    }catch{}
    await self.registration.showNotification(title,{body,tag,icon:assetUrl('assets/taskesc-logo.svg'),badge:assetUrl('assets/taskesc-logo.svg'),data:{url:target}});
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
