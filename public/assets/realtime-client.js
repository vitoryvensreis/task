(function () {
  'use strict';

  var app = document.getElementById('app');
  var basePath = (document.body && document.body.dataset.basePath) || '';
  var authUrl = basePath + '/realtime.auth';
  var client = null;
  var starting = false;
  var retryTimer = 0;
  var retryAttempt = 0;
  var csrfToken = '';
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
    var error;
    if (window.Centrifuge && window.Centrifuge.UnauthorizedError) {
      error = new window.Centrifuge.UnauthorizedError(message || 'unauthorized');
    } else {
      error = new Error(message || 'unauthorized');
    }
    try { error.taskescUnauthorized = true; } catch (_) {}
    return error;
  }

  async function requestAuth(body) {
    if (!csrfToken) throw new Error('realtime csrf unavailable');
    var response = await fetch(authUrl, {
      method: 'POST',
      credentials: 'same-origin',
      cache: 'no-store',
      headers: {
        'Accept': 'application/json',
        'Content-Type': 'application/json',
        'X-CSRF-Token': csrfToken
      },
      body: JSON.stringify(body || {})
    });
    if (response.status === 401 || response.status === 403 || response.status === 419) {
      throw unauthorized('TaskEsc session not authorized');
    }
    if (!response.ok) throw new Error('realtime auth status ' + response.status);
    var data = await response.json();
    if (!data || typeof data !== 'object') throw new Error('invalid realtime auth response');
    return data;
  }

  async function connectionToken() {
    var data = await requestAuth({});
    if (!data.token) throw new Error('empty realtime connection token');
    return String(data.token);
  }

  async function subscriptionToken(channel) {
    var data = await requestAuth({channel: channel});
    if (!data.token || data.channel !== channel) throw new Error('invalid realtime subscription token');
    return String(data.token);
  }

  function emitState(state, channel) {
    try {
      window.dispatchEvent(new CustomEvent('taskesc:realtime-state', {
        detail: {state: state, channel: channel || '', at: Date.now()}
      }));
    } catch (_) {}
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
        getToken: function (ctx) { return subscriptionToken(String((ctx && ctx.channel) || channel)); },
        minResubscribeDelay: 500,
        maxResubscribeDelay: 20000
      });
      sub.on('publication', function (ctx) { emitPublication(channel, ctx); });
      sub.on('error', function (ctx) {
        technical('subscription error: ' + channel, ctx && ctx.error ? ctx.error.code : undefined);
      });
      sub.on('subscribed', function () { technical('subscribed: ' + channel); emitState('subscribed', channel); });
      sub.subscribe();
      subscriptions.set(channel, sub);
      return sub;
    } catch (_) {
      technical('subscription unavailable: ' + channel);
      return null;
    }
  }

  function scheduleRetry() {
    if (retryTimer || !visible() || client || !csrfToken) return;
    var ceiling = Math.min(30000, 500 * Math.pow(2, Math.min(retryAttempt, 6)));
    var delay = ceiling + Math.floor(Math.random() * Math.min(500, ceiling));
    retryAttempt += 1;
    retryTimer = window.setTimeout(function () {
      retryTimer = 0;
      start();
    }, delay);
  }

  async function start() {
    if (!visible() || !csrfToken || starting || client || typeof window.Centrifuge !== 'function') return;
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
        version: 'step6'
      });
      next.on('connecting', function () { lastState = 'connecting'; technical('connecting'); });
      next.on('connected', function () { lastState = 'connected'; retryAttempt = 0; technical('connected'); emitState('connected'); });
      next.on('disconnected', function (ctx) { lastState = 'disconnected'; technical('disconnected', ctx && ctx.code); emitState('disconnected'); });
      next.on('error', function (ctx) {
        technical('connection error', ctx && ctx.error ? ctx.error.code : undefined);
      });
      next.on('publication', function (ctx) {
        emitPublication(String((ctx && ctx.channel) || ''), ctx);
      });
      client = next;
      var bootstrap = Array.isArray(auth.channels) ? auth.channels : [];
      bootstrap.forEach(function (channel) {
        if (typeof channel === 'string' && channel.length <= 190) wanted.add(channel);
      });
      wanted.forEach(attachSubscription);
      next.connect();
    } catch (error) {
      lastState = 'fallback';
      technical('unavailable; normal HTTP flow remains active');
      client = null;
      if (!(error && error.taskescUnauthorized)) scheduleRetry();
    } finally {
      starting = false;
    }
  }

  function stop(clearWanted) {
    if (retryTimer) { clearTimeout(retryTimer); retryTimer = 0; }
    subscriptions.forEach(function (sub) { try { sub.unsubscribe(); } catch (_) {} });
    subscriptions.clear();
    if (client) { try { client.disconnect(); } catch (_) {} }
    client = null;
    starting = false;
    retryAttempt = 0;
    lastState = 'idle';
    if (clearWanted) wanted.clear();
  }

  function setCsrf(value) {
    csrfToken = String(value || '');
    if (!csrfToken) { stop(true); return; }
    if (visible()) start();
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
    setCsrf: setCsrf,
    subscribe: subscribe,
    unsubscribe: unsubscribe,
    status: function () { return lastState; }
  });

  if (app) {
    new MutationObserver(function () {
      if (visible()) start();
      else stop(true);
    }).observe(app, {attributes: true, attributeFilter: ['class']});
  }
})();