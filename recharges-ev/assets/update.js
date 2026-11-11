/* Actualise en arrière-plan la vérification des mises à jour (une fois par jour au plus). */
(function () {
  'use strict';
  var s = document.querySelector('script[data-update-refresh]');
  var slot = document.getElementById('update-slot');
  if (!s || !slot || !window.fetch) return;
  var fd = new FormData();
  fd.append('csrf', s.getAttribute('data-csrf') || '');
  fetch('maj.php?action=refresh', { method: 'POST', body: fd, credentials: 'same-origin' })
    .then(function (r) { return r.ok ? r.json() : null; })
    .then(function (j) { if (j && typeof j.html === 'string') slot.innerHTML = j.html; })
    .catch(function () { /* silencieux : la page reste utilisable sans réseau */ });
})();
