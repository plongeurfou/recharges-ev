<?php
declare(strict_types=1);
/* Service worker (saisie hors ligne). Aucune donnée ni accès à la base : seulement le numéro de version. */
require __DIR__ . '/inc/version.php';
header('Content-Type: application/javascript; charset=utf-8');
header('Cache-Control: no-cache');
?>
'use strict';
var CACHE = 'recharges-ev-<?= APP_VERSION ?>';
var SCOPE = self.registration.scope;

self.addEventListener('install', function () { self.skipWaiting(); });

self.addEventListener('activate', function (e) {
  e.waitUntil(caches.keys().then(function (keys) {
    return Promise.all(keys.filter(function (k) { return k !== CACHE; }).map(function (k) { return caches.delete(k); }));
  }).then(function () { return self.clients.claim(); }));
});

self.addEventListener('message', function (e) {
  if (e.data === 'clear') e.waitUntil(caches.keys().then(function (k) { return Promise.all(k.map(function (n) { return caches.delete(n); })); }));
});

function isIndex(url) {
  var p = url.pathname;
  return url.origin === location.origin && url.href.indexOf(SCOPE) === 0 && (p.slice(-1) === '/' || p.slice(-10) === '/index.php');
}

self.addEventListener('fetch', function (e) {
  var req = e.request;
  if (req.method !== 'GET') return;
  var url = new URL(req.url);
  if (url.origin !== location.origin || url.href.indexOf(SCOPE) !== 0) return;

  /* Écran principal : réseau d'abord, dernière version connue si hors ligne. */
  if (req.mode === 'navigate' && isIndex(url)) {
    e.respondWith(fetch(req).then(function (res) {
      if (res.ok && !res.redirected && url.search === '') {
        var copy = res.clone();
        caches.open(CACHE).then(function (c) { c.put(SCOPE + 'index.php', copy); });
      }
      return res;
    }).catch(function () {
      return caches.open(CACHE).then(function (c) { return c.match(SCOPE + 'index.php'); }).then(function (r) {
        return r || new Response('<!doctype html><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
          + '<title>Hors ligne</title><body style="font:16px system-ui;max-width:480px;margin:40px auto;padding:0 16px">'
          + '<h1>Hors ligne</h1><p>Ouvrez l\'application une première fois avec du réseau pour pouvoir saisir hors ligne.</p></body>',
          { status: 503, headers: { 'Content-Type': 'text/html; charset=utf-8' } });
      });
    }));
    return;
  }

  /* Ressources statiques : cache d'abord, mise à jour en arrière-plan. */
  if (url.pathname.indexOf('/assets/') !== -1 || url.pathname.slice(-16) === '.webmanifest') {
    e.respondWith(caches.open(CACHE).then(function (c) {
      return c.match(req).then(function (hit) {
        var net = fetch(req).then(function (res) { if (res.ok) c.put(req, res.clone()); return res; }).catch(function () { return hit; });
        return hit || net;
      });
    }));
  }
});
