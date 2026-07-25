<?php

namespace App\Http\Controllers;

use Illuminate\Http\Response;

/**
 * Serve el snippet público /track.js. Sin auth ni CSRF (es JS estático
 * para embeber desde landings externas): captura UTMs + click IDs en
 * localStorage con política first-touch y los inyecta como hidden inputs
 * al enviar cualquier form con [data-komo-track].
 *
 * Uso en la landing externa:
 *   <script src="https://komo.tu-dominio/track.js" defer></script>
 *   <form action="https://komo.tu-dominio/f/{token}" method="POST" data-komo-track>
 *     ...
 *   </form>
 *
 * O si se quiere leer el tracking desde JS:
 *   window.KomoTrack.get()   // devuelve el objeto guardado
 *   window.KomoTrack.applyTo(formEl)  // inyecta hidden inputs
 */
class TrackController extends Controller
{
    public function snippet(): Response
    {
        $js = <<<'JS'
(function () {
  'use strict';
  var LS_KEY = 'komo_first_touch_v1';
  var UTM_KEYS = ['utm_source','utm_medium','utm_campaign','utm_content','utm_term',
                  'gclid','fbclid','ttclid','msclkid'];
  var EXTRA_KEYS = ['landing_url','referrer_url','first_touch_at'];
  var ALL_KEYS = UTM_KEYS.concat(EXTRA_KEYS);

  function readStored() {
    try { return JSON.parse(localStorage.getItem(LS_KEY) || 'null'); } catch (e) { return null; }
  }
  function writeStored(obj) {
    try { localStorage.setItem(LS_KEY, JSON.stringify(obj)); } catch (e) {}
  }
  function captureCurrent() {
    var qs = new URLSearchParams(window.location.search);
    var out = {};
    var got = false;
    UTM_KEYS.forEach(function (k) {
      var v = qs.get(k);
      if (v) { out[k] = v; got = true; }
    });
    // Guardamos la landing/referrer aun cuando no haya UTMs — sirve para
    // tráfico orgánico y directo (Google Analytics-style).
    out.landing_url = window.location.href;
    out.referrer_url = document.referrer || '';
    out.first_touch_at = new Date().toISOString();
    return got || out.referrer_url ? out : null;
  }

  // First-touch: solo escribimos si no había nada guardado.
  var stored = readStored();
  if (!stored) {
    var captured = captureCurrent();
    if (captured) {
      stored = captured;
      writeStored(stored);
    }
  }

  function applyTo(form) {
    if (!form || !stored) return;
    ALL_KEYS.forEach(function (k) {
      if (!stored[k]) return;
      var existing = form.querySelector('[name="' + k + '"]');
      if (existing) {
        if (!existing.value) existing.value = stored[k];
      } else {
        var input = document.createElement('input');
        input.type = 'hidden';
        input.name = k;
        input.value = stored[k];
        form.appendChild(input);
      }
    });
  }

  // Auto-attach: cualquier form con [data-komo-track] recibe los hidden
  // inputs justo antes del submit.
  document.addEventListener('submit', function (ev) {
    var form = ev.target;
    if (form && form.matches && form.matches('form[data-komo-track]')) {
      applyTo(form);
    }
  }, true);

  window.KomoTrack = {
    get: function () { return stored ? Object.assign({}, stored) : null; },
    applyTo: applyTo,
    reset: function () { try { localStorage.removeItem(LS_KEY); } catch (e) {} stored = null; }
  };
})();
JS;

        return response($js, 200, [
            'Content-Type' => 'application/javascript; charset=utf-8',
            // Cache 1h en el navegador y en CDN; el snippet cambia rara vez.
            'Cache-Control' => 'public, max-age=3600',
            // CORS abierto: se embebe desde cualquier landing externa.
            'Access-Control-Allow-Origin' => '*',
        ]);
    }
}
