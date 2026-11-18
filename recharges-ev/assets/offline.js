/* Saisie hors ligne : service worker (écran principal disponible sans réseau), file d'attente locale (IndexedDB)
   et envoi automatique, sans doublon, au retour du réseau. */
(function () {
  'use strict';

  var form = document.getElementById('recharge-form');
  if (!form) return;

  if ('serviceWorker' in navigator) {
    navigator.serviceWorker.register('sw.php').catch(function () { /* hors HTTPS ou refusé : l'app reste utilisable en ligne */ });
  }

  var netStatus = document.getElementById('net-status');
  var msg = document.getElementById('offline-msg');
  var section = document.getElementById('pending');
  var list = document.getElementById('pending-list');
  var sendBtn = document.getElementById('pending-send');
  var kwhInput = document.getElementById('kwh');
  var btn = document.getElementById('submit-btn');
  var flushing = false;

  /* ---------- Petite couche IndexedDB ---------- */
  var DBNAME = 'recharges_ev_offline', STORE = 'pending';
  function open() {
    return new Promise(function (resolve, reject) {
      if (!window.indexedDB) { reject(new Error('indexedDB')); return; }
      var rq = indexedDB.open(DBNAME, 1);
      rq.onupgradeneeded = function () { rq.result.createObjectStore(STORE, { keyPath: 'uid' }); };
      rq.onsuccess = function () { resolve(rq.result); };
      rq.onerror = function () { reject(rq.error); };
    });
  }
  function tx(mode, fn) {
    return open().then(function (db) {
      return new Promise(function (resolve, reject) {
        var t = db.transaction(STORE, mode), s = t.objectStore(STORE), out = fn(s);
        t.oncomplete = function () { db.close(); resolve(out && out.result !== undefined ? out.result : undefined); };
        t.onerror = t.onabort = function () { db.close(); reject(t.error); };
      });
    });
  }
  function all() { return tx('readonly', function (s) { return s.getAll(); }).then(function (r) { return (r || []).sort(function (a, b) { return a.ts - b.ts; }); }); }
  function put(item) { return tx('readwrite', function (s) { return s.put(item); }); }
  function del(uid) { return tx('readwrite', function (s) { return s.delete(uid); }); }

  function uuid() {
    if (window.crypto && crypto.randomUUID) return crypto.randomUUID();
    var b = new Uint8Array(16); crypto.getRandomValues(b);
    b[6] = (b[6] & 0x0f) | 0x40; b[8] = (b[8] & 0x3f) | 0x80;
    var h = Array.prototype.map.call(b, function (x) { return ('0' + x.toString(16)).slice(-2); }).join('');
    return h.slice(0, 8) + '-' + h.slice(8, 12) + '-' + h.slice(12, 16) + '-' + h.slice(16, 20) + '-' + h.slice(20);
  }

  function say(text, cls) {
    if (!msg) return;
    msg.textContent = text || '';
    msg.className = 'msg ' + (cls || 'ok');
    msg.hidden = !text;
  }

  /* ---------- Liste « à envoyer » ---------- */
  function fmtDate(ts) {
    var d = new Date(ts * 1000);
    return d.toLocaleString('fr-BE', { weekday: 'short', day: '2-digit', month: '2-digit', hour: '2-digit', minute: '2-digit' });
  }
  function render() {
    if (!section) return Promise.resolve();
    return all().then(function (items) {
      section.hidden = items.length === 0;
      list.textContent = '';
      items.forEach(function (it) {
        var li = document.createElement('li');
        var main = document.createElement('div');
        main.className = 'line1';
        var a = document.createElement('span'); a.className = 'kwh'; a.textContent = it.kwh + ' kWh ' + it.type;
        var b = document.createElement('span'); b.className = 'billed'; b.textContent = fmtDate(it.ts) + (it.odo ? ' · ' + it.odo + ' km' : '');
        var x = document.createElement('button'); x.type = 'button'; x.className = 'linkbtn'; x.textContent = 'Supprimer';
        x.addEventListener('click', function () {
          if (window.confirm('Supprimer cette recharge en attente ?')) del(it.uid).then(render);
        });
        main.appendChild(a); main.appendChild(b); main.appendChild(x);
        li.appendChild(main);
        if (it.error) {
          var e = document.createElement('div'); e.className = 'line2 neg'; e.textContent = it.error; li.appendChild(e);
        }
        list.appendChild(li);
      });
    }).catch(function () { /* stockage indisponible */ });
  }

  /* ---------- Mise en file ---------- */
  function resetForm() {
    kwhInput.value = '';
    kwhInput.dispatchEvent(new Event('input'));
    if (form.elements.odo) form.elements.odo.value = '';
    form.dataset.sending = '';
    btn.disabled = false;
    btn.textContent = 'Enregistrer la recharge';
  }
  function queue(item, reason) {
    return put(item).then(function () {
      resetForm();
      return render();
    }).then(function () {
      say(reason === 'session'
        ? 'Session expirée : la recharge est conservée sur ce téléphone. Reconnectez-vous pour l\'envoyer.'
        : 'Pas de réseau : la recharge est conservée sur ce téléphone et sera envoyée dès le retour de la connexion.', 'warn');
    }).catch(function () {
      resetForm();
      say('Pas de réseau et stockage local indisponible : la recharge n\'a PAS été enregistrée. Réessayez avec du réseau.', 'bad');
    });
  }

  /* ---------- Envoi du formulaire (appelé par app.js) ---------- */
  window.rechargesSubmit = function (f) {
    var uid = uuid();
    var item = {
      uid: uid, kwh: f.elements.kwh.value.trim(), type: f.elements.type.value, odo: f.elements.odo ? f.elements.odo.value.trim() : '',
      lat: f.elements.lat.value, lng: f.elements.lng.value, acc: f.elements.acc.value, ts: Math.floor(Date.now() / 1000)
    };
    if (navigator.onLine === false || !window.fetch || !window.indexedDB) {
      if (window.indexedDB && navigator.onLine === false) { queue(item); } else { f.submit(); }
      return;
    }
    var fd = new FormData(f);
    fd.set('uid', uid);
    var ctl = window.AbortController ? new AbortController() : null;
    var timer = setTimeout(function () { if (ctl) ctl.abort(); }, 20000);
    fetch(f.getAttribute('action') || location.href, { method: 'POST', body: fd, credentials: 'same-origin', signal: ctl ? ctl.signal : undefined })
      .then(function (res) {
        clearTimeout(timer);
        if (res.redirected) {
          if (/login\.php/.test(res.url)) { queue(item, 'session'); return; }
          location.href = res.url;
          return;
        }
        f.submit();   // erreur de validation : le serveur réaffiche le formulaire avec son message
      })
      .catch(function () { clearTimeout(timer); queue(item); });
  };

  /* ---------- Envoi de la file ---------- */
  function flush() {
    if (flushing || navigator.onLine === false) return Promise.resolve();
    flushing = true;
    var sent = 0, warns = [], stop = null;
    return all().then(function (items) {
      var chain = Promise.resolve();
      items.forEach(function (it) {
        chain = chain.then(function () {
          if (stop) return;
          var fd = new FormData();
          fd.set('csrf', form.elements.csrf.value);
          ['uid', 'kwh', 'type', 'odo', 'lat', 'lng', 'acc', 'ts'].forEach(function (k) { fd.set(k, String(it[k] == null ? '' : it[k])); });
          return fetch('sync.php', { method: 'POST', body: fd, credentials: 'same-origin' }).then(function (res) {
            if (res.status === 401 || res.status === 403) { stop = 'auth'; return; }
            return res.json().then(function (j) {
              if (res.ok && j.ok) {
                sent++;
                if (j.warning) warns.push(it.kwh + ' kWh : ' + j.warning);
                return del(it.uid);
              }
              if (res.status === 422) { it.error = j.error || 'Refusée par le serveur.'; return put(it); }
              stop = 'server';
            });
          });
        });
      });
      return chain.catch(function () { stop = stop || 'network'; });
    }).then(function () {
      flushing = false;
      return render();
    }).then(function () {
      if (stop === 'auth') {
        say('Reconnectez-vous pour envoyer les recharges en attente.', 'warn');
      } else if (sent > 0) {
        say(sent + ' recharge' + (sent > 1 ? 's envoyées' : ' envoyée') + (warns.length ? ' — ' + warns.join(' ; ') : '') + '.', warns.length ? 'warn' : 'ok');
        setTimeout(function () { location.reload(); }, warns.length ? 4000 : 1200);
      }
    }).catch(function () { flushing = false; });
  }

  function netChanged() {
    if (netStatus) netStatus.hidden = navigator.onLine !== false;
    if (navigator.onLine !== false) flush();
  }
  window.addEventListener('online', netChanged);
  window.addEventListener('offline', netChanged);
  document.addEventListener('visibilitychange', function () { if (document.visibilityState === 'visible') flush(); });
  if (sendBtn) sendBtn.addEventListener('click', flush);

  render().then(netChanged);
})();
