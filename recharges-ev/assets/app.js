(function () {
  'use strict';

  var form = document.getElementById('recharge-form');
  if (!form) return;

  var kwhInput = document.getElementById('kwh');
  var preview = document.getElementById('preview');
  var geoStatus = document.getElementById('geo-status');
  var btn = document.getElementById('submit-btn');
  var fLat = form.elements.lat, fLng = form.elements.lng, fAcc = form.elements.acc;
  var rates = { AC: parseInt(form.dataset.rateAc, 10), DC: parseInt(form.dataset.rateDc, 10) };
  var vat = parseInt(form.dataset.vat, 10);
  var money = new Intl.NumberFormat('fr-BE', { style: 'currency', currency: 'EUR' });

  /* --- Même logique de calcul que le serveur (entiers uniquement) --- */
  function parseKwh(s) {
    s = String(s).replace(/[\s  ]/g, '').replace(',', '.');
    if (s.charAt(0) === '.') s = '0' + s;
    var m = /^(\d{1,4})(?:\.(\d{1,2}))?$/.exec(s);
    if (!m) return null;
    var h = parseInt(m[1], 10) * 100 + parseInt(((m[2] || '') + '00').slice(0, 2), 10);
    return h > 0 && h <= 100000 ? h : null;
  }

  function updatePreview() {
    var h = parseKwh(kwhInput.value);
    if (h === null) { preview.innerHTML = '&nbsp;'; return; }
    var type = form.elements.type.value;
    var billed = Math.floor((h + 99) / 100);
    var htva = Math.floor((billed * rates[type] + 50) / 100);
    var ttc = htva + Math.floor((htva * vat + 50) / 100);
    preview.textContent = billed + ' kWh facturés → ' + money.format(ttc / 100) + ' TVAC';
  }

  kwhInput.addEventListener('input', updatePreview);
  Array.prototype.forEach.call(form.elements.type, function (r) { r.addEventListener('change', updatePreview); });
  updatePreview();

  /* --- Géolocalisation --- */
  function setStatus(text, cls) {
    geoStatus.textContent = 'Position : ' + text;
    geoStatus.className = 'geo' + (cls ? ' ' + cls : '');
  }

  function locate(timeoutMs) {
    return new Promise(function (resolve) {
      if (!navigator.geolocation) {
        if (!fLat.value) setStatus('indisponible sur cet appareil — recharge enregistrée sans position', 'warn');
        return resolve(false);
      }
      navigator.geolocation.getCurrentPosition(function (p) {
        fLat.value = p.coords.latitude.toFixed(6);
        fLng.value = p.coords.longitude.toFixed(6);
        fAcc.value = Math.round(p.coords.accuracy);
        setStatus('obtenue (± ' + Math.round(p.coords.accuracy) + ' m)', 'ok');
        resolve(true);
      }, function (err) {
        if (!fLat.value) {
          setStatus(err && err.code === 1
            ? 'autorisation refusée — recharge enregistrée sans position'
            : 'indisponible — recharge enregistrée sans position', 'warn');
        }
        resolve(false);
      }, { enableHighAccuracy: true, timeout: timeoutMs, maximumAge: 60000 });
    });
  }

  locate(15000);

  /* Au clic sur Enregistrer : on rafraîchit la position (max 10 s) puis on envoie. */
  form.addEventListener('submit', function (e) {
    if (form.dataset.sending === '1') { e.preventDefault(); return; }
    if (!form.checkValidity() || parseKwh(kwhInput.value) === null) {
      e.preventDefault();
      kwhInput.setCustomValidity('Saisissez un nombre de kWh (2 décimales maximum).');
      kwhInput.reportValidity();
      kwhInput.addEventListener('input', function once() { kwhInput.setCustomValidity(''); kwhInput.removeEventListener('input', once); });
      return;
    }
    e.preventDefault();
    form.dataset.sending = '1';
    btn.disabled = true;
    btn.textContent = 'Enregistrement…';
    locate(10000).then(function () { form.submit(); });
  });

  /* Retour via l'historique du navigateur : on réactive le bouton. */
  window.addEventListener('pageshow', function (ev) {
    if (ev.persisted) { form.dataset.sending = ''; btn.disabled = false; btn.textContent = 'Enregistrer la recharge'; }
  });
})();
