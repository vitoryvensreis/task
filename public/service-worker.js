const CACHE='taskesc-shell-v2.11.0';
const SHELL=[
  '/offline.html',
  '/assets/app.css?v=2.11.0',
  '/assets/app.js?v=2.11.0',
  '/assets/taskesc-logo.svg',
  '/icons/icon-192.png',
  '/icons/icon-512.png',
  '/icons/icon-maskable-512.png'
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

  const sensitive=/\/(api\.php|api\/|drive-upload\.php|download\.php|google|oauth|cron\.php|mcp\.php)/i.test(url.pathname);
  if(sensitive)return;

  if(request.mode==='navigate'){
    event.respondWith(fetch(request).catch(()=>caches.match('/offline.html')));
    return;
  }

  if(/^\/(assets|icons)\//.test(url.pathname)){
    event.respondWith(caches.match(request).then(cached=>{
      const network=fetch(request).then(response=>{
        if(response&&response.ok){const copy=response.clone();caches.open(CACHE).then(cache=>cache.put(request,copy));}
        return response;
      }).catch(()=>cached);
      return cached||network;
    }));
  }
});
