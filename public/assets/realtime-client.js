(function () {
  'use strict';

  var app = document.getElementById('app');
  var basePath = (document.body && document.body.dataset.basePath) || '';
  var authUrl = basePath + '/realtime.auth';
  var client = null;
  var starting = false;
  var retryTimer = 0;
  var retryAttempt = 0;
  var subscriptions = new Map();
  var wanted = new Set();
  var lastState = 'idle';

  function visible() {
    return !!app && !app.classList.contains('hidden');
  }

  function technical(message, extra) {
    if (!window.console || !console.debug) return;
    if (extra === undefined) console.debug('[TaskEsc realtime] ' + message);
    else console.debug('[TaskEsc realtime] ' + message, extra);
  }

  function unauthorized(message) {
    if (window.Centrifuge && window.Centrifuge.UnauthorizedError) {
      return new window.Centrifuge.UnauthorizedError(message || 'unauthorized');
    }
    return new Error(message || 'unauthorized');
  }

  async function requestAuth(body) {
    var response = await fetch(authUrl, {
      method: 'POST',
      credentials: 'same-origin',
      cache: 'no-store',
      headers: {'Accept': 'application/json', 'Content-Type': 'application/json'},
      body: JSON.stringify(body || {})
    });
    if (response.status === 401 || response.status === 403) throw unauthorized('TaskEsc session not authorized');
    if (!response.ok) throw new Error('realtime auth status ' + response.status);
    var data = await response.json();
    if (!data || typeof data !== 'object') throw new Error('invalid realtime auth response');
    return data;
  }

  async function connectionToken() {
    var data = await requestAuth({});
    return String(data.token || '');
  }

  async function subscriptionToken(channel) {
    var data = await requestAuth({channel: channel});
    return String(data.token || '');
  }

  function emitPublication(channel, ctx) {
    try {
      window.dispatchEvent(new CustomEvent('taskesc:realtime-publication', {
        detail: {channel: channel, data: ctx && ctx.data, offset: ctx && ctx.offset}
      }));
    } catch (_) {}
  }

  function attachSubscription(channel) {
    if (!client || subscriptions.has(channel)) return subscriptions.get(channel) || null;
    var sub;
    try {
      sub = client.newSubscription(channel, {
        getToken: function () { return subscriptionToken(channel); },
        minResubscribeDelay: 500,
        maxResubscribeDelay: 20000
      });
      sub.on('publication', function (ctx) { emitPublication(channel, ctx); });
      sub.on('error', function (ctx) { technical('subscription error: ' + channel, ctx && ctx.error ? ctx.error.code : undefined); });
      sub.on('subscribed', function () { technical('subscribed: ' + channel); });
      sub.subscribe();
      subscriptions.set(channel, sub);
      return sub;
    } catch (error) {
      technical('subscription unavailable: ' + channel);
      return null;
    }
  }

  function scheduleRetry() {
    if (retryTimer || !visible() || client) return;
    var ceiling = Math.min(30000, 500 * Math.pow(2, Math.min(retryAttempt, 6)));
    var delay = ceiling + Math.floor(Math.random() * Math.min(500, ceiling));
    retryAttempt += 1;
    retryTimer = window.setTimeout(function () {
      retryTimer = 0;
      start();
    }, delay);
  }

  async function start() {
    if (!visible() || starting || client || typeof window.Centrifuge !== 'function') return;
    starting = true;
    lastState = 'starting';
    try {
      var auth = await requestAuth({});
      if (!auth.enabled || !auth.token || !auth.websocket) throw new Error('realtime disabled');
      var next = new window.Centrifuge(String(auth.websocket), {
        token: String(auth.token),
        getToken: connectionToken,
        minReconnectDelay: 500,
        maxReconnectDelay: 20000,
        timeout: 5000,
        debug: false,
        name: 'taskesc-web',
        version: 'step4'
      });
      next.on('connecting', function () { lastState = 'connecting'; technical('connecting'); });
      next.on('connected', function () { lastState = 'connected'; retryAttempt = 0; technical('connected'); });
      next.on('disconnected', function (ctx) { lastState = 'disconnected'; technical('disconnected', ctx && ctx.code); });
      next.on('error', function (ctx) { technical('connection error', ctx && ctx.error ? ctx.error.code : undefined); });
      next.on('publication', function (ctx) { emitPublication(String(ctx && ctx.channel || ''), ctx); });
      client = next;
      var bootstrap = Array.isArray(auth.channels) ? auth.channels : [];
      bootstrap.forEach(function (channel) { if (typeof channel === 'string') wanted.add(channel); });
      wanted.forEach(attachSubscription);
      next.connect();
    } catch (error) {
      lastState = 'fallback';
      technical('unavailable; normal HTTP flow remains active');
      client = null;
      scheduleRetry();
    } finally {
      starting = false;
    }
  }

  function stop() {
    if (retryTimer) { clearTimeout(retryTimer); retryTimer = 0; }
    subscriptions.forEach(function (sub) { try { sub.unsubscribe(); } catch (_) {} });
    subscriptions.clear();
    if (client) { try { client.disconnect(); } catch (_) {} }
    client = null;
    starting = false;
    retryAttempt = 0;
    lastState = 'idle';
  }

  function subscribe(channel) {
    if (typeof channel !== 'string' || channel.length < 1 || channel.length > 190) return null;
    wanted.add(channel);
    if (!client) { start(); return null; }
    return attachSubscription(channel);
  }

  function unsubscribe(channel) {
    wanted.delete(channel);
    var sub = subscriptions.get(channel);
    if (!sub) return;
    try { sub.unsubscribe(); } catch (_) {}
    subscriptions.delete(channel);
  }

  window.TaskEscRealtime = Object.freeze({
    subscribe: subscribe,
    unsubscribe: unsubscribe,
    status: function () { return lastState; }
  });

  if (app) {
    new MutationObserver(function () { if (visible()) start(); else stop(); })
      .observe(app, {attributes: true, attributeFilter: ['class']});
  }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', function () { if (visible()) start(); });
  else if (visible()) start();
})();
