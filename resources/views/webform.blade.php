<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $form->headline ?? $form->name }}</title>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body { font-family: -apple-system, 'Segoe UI', Roboto, sans-serif; background: #f3f4f6; min-height: 100vh; display: flex; align-items: center; justify-content: center; padding: 16px; }
        .card { background: #fff; border-radius: 20px; box-shadow: 0 10px 40px rgba(0,0,0,.08); max-width: 420px; width: 100%; overflow: hidden; }
        .head { background: linear-gradient(135deg, #045474, #1c486c); padding: 28px 28px 24px; color: #fff; }
        .head h1 { font-size: 20px; font-weight: 800; }
        .head p { font-size: 13px; opacity: .75; margin-top: 4px; }
        form { padding: 24px 28px 28px; }
        label { display: block; font-size: 13px; font-weight: 600; color: #374151; margin: 14px 0 6px; }
        input, textarea { width: 100%; border: 1px solid #e5e7eb; background: #f9fafb; border-radius: 12px; padding: 10px 14px; font-size: 14px; font-family: inherit; outline: none; transition: all .15s; }
        input:focus, textarea:focus { border-color: #059669; background: #fff; box-shadow: 0 0 0 3px rgba(5,150,105,.12); }
        button { width: 100%; margin-top: 20px; background: linear-gradient(90deg, #059669, #0d9488); color: #fff; border: 0; border-radius: 12px; padding: 12px; font-size: 15px; font-weight: 700; cursor: pointer; box-shadow: 0 8px 20px rgba(5,150,105,.25); transition: opacity .15s; }
        button:hover { opacity: .92; }
        .ok { padding: 48px 28px; text-align: center; }
        .ok .icon { width: 56px; height: 56px; margin: 0 auto 16px; border-radius: 16px; background: linear-gradient(135deg, #10b981, #0d9488); color: #fff; font-size: 26px; display: flex; align-items: center; justify-content: center; }
        .ok p { font-size: 15px; font-weight: 600; color: #111827; }
        .err { color: #dc2626; font-size: 12px; margin-top: 4px; }
        .hp { position: absolute; left: -9999px; }
    </style>
</head>
<body>
    <div class="card">
        @if ($sent)
            <div class="ok">
                <div class="icon">✓</div>
                <p>{{ $form->success_message }}</p>
            </div>
        @else
            <div class="head">
                <h1>{{ $form->headline ?? $form->name }}</h1>
                <p>Déjanos tus datos y te contactamos</p>
            </div>
            <form method="POST" action="{{ route('webforms.submit', $form->token) }}" id="komoForm">
                @csrf
                {{-- Honeypot anti-bots --}}
                <input class="hp" type="text" name="website" tabindex="-1" autocomplete="off">

                {{-- Tracking oculto: JS lo puebla al cargar (querystring, referrer, localStorage) --}}
                <input type="hidden" name="utm_source">
                <input type="hidden" name="utm_medium">
                <input type="hidden" name="utm_campaign">
                <input type="hidden" name="utm_content">
                <input type="hidden" name="utm_term">
                <input type="hidden" name="gclid">
                <input type="hidden" name="fbclid">
                <input type="hidden" name="ttclid">
                <input type="hidden" name="msclkid">
                <input type="hidden" name="landing_url">
                <input type="hidden" name="referrer_url">

                <label for="name">Nombre *</label>
                <input id="name" name="name" value="{{ old('name') }}" required maxlength="120">
                @error('name')<p class="err">{{ $message }}</p>@enderror

                <label for="phone">Teléfono (WhatsApp) *</label>
                <input id="phone" name="phone" value="{{ old('phone') }}" required maxlength="32" placeholder="+58 412 555 0000">
                @error('phone')<p class="err">{{ $message }}</p>@enderror

                <label for="email">Email</label>
                <input id="email" name="email" type="email" value="{{ old('email') }}" maxlength="255">
                @error('email')<p class="err">{{ $message }}</p>@enderror

                <label for="message">¿En qué te podemos ayudar?</label>
                <textarea id="message" name="message" rows="3" maxlength="2000">{{ old('message') }}</textarea>

                <button type="submit">{{ $form->button_label }}</button>
            </form>

            {{-- Captura de tracking inline. Ver también /track.js (snippet embebible
                 para landings externas): guarda el first-touch en localStorage y
                 esta captura lo prefiere sobre el querystring actual. --}}
            <script>
            (function () {
                var UTM_KEYS = ['utm_source','utm_medium','utm_campaign','utm_content','utm_term',
                                'gclid','fbclid','ttclid','msclkid'];
                var LS_KEY = 'komo_first_touch_v1';
                var qs = new URLSearchParams(window.location.search);
                var stored = null;
                try { stored = JSON.parse(localStorage.getItem(LS_KEY) || 'null'); } catch (e) {}
                var form = document.getElementById('komoForm');
                if (!form) return;
                function set(name, value) {
                    var el = form.querySelector('[name="' + name + '"]');
                    if (el && value) el.value = value;
                }
                UTM_KEYS.forEach(function (k) {
                    var v = (stored && stored[k]) || qs.get(k) || '';
                    if (v) set(k, v);
                });
                set('landing_url', (stored && stored.landing_url) || window.location.href);
                set('referrer_url', (stored && stored.referrer_url) || document.referrer || '');
            })();
            </script>
        @endif
    </div>
</body>
</html>
