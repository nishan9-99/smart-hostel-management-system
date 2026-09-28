/* ============================================================
   Password field UX
   - Every password input gets an eye-icon show/hide toggle.
   - Inputs with data-pw-meter get a live strength checklist
     (8+ chars, upper, lower, number, special) - green/red.
   - Inputs with data-pw-match="#otherId" get a live
     "passwords match" indicator against the other field.
   ============================================================ */
var PW_ICON_SHOW = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>';
var PW_ICON_HIDE = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94"/><path d="M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19"/><path d="M14.12 14.12a3 3 0 1 1-4.24-4.24"/><line x1="1" y1="1" x2="23" y2="23"/></svg>';

var PW_RULES = [
    ['length',  '8+ characters',     function (v) { return v.length >= 8; }],
    ['upper',   'Uppercase letter',  function (v) { return /[A-Z]/.test(v); }],
    ['lower',   'Lowercase letter',  function (v) { return /[a-z]/.test(v); }],
    ['number',  'Number',            function (v) { return /[0-9]/.test(v); }],
    ['special', 'Special character', function (v) { return /[^A-Za-z0-9]/.test(v); }]
];

function pwEnhanceAll() {
    document.querySelectorAll('input[type="password"]').forEach(function (input) {
        if (input.dataset.pwEnhanced) return;
        input.dataset.pwEnhanced = '1';

        /* wrap + eye toggle */
        var wrap = document.createElement('div');
        wrap.className = 'pw-wrap';
        input.parentNode.insertBefore(wrap, input);
        wrap.appendChild(input);

        var btn = document.createElement('button');
        btn.type = 'button';
        btn.className = 'pw-toggle';
        btn.setAttribute('aria-label', 'Show password');
        btn.setAttribute('title', 'Show password');
        btn.innerHTML = PW_ICON_SHOW;
        btn.addEventListener('click', function () {
            var show = input.type === 'password';
            input.type = show ? 'text' : 'password';
            btn.innerHTML = show ? PW_ICON_HIDE : PW_ICON_SHOW;
            btn.setAttribute('aria-label', show ? 'Hide password' : 'Show password');
            input.focus();
        });
        wrap.appendChild(btn);

        /* live strength rules */
        if (input.hasAttribute('data-pw-meter')) {
            var list = document.createElement('ul');
            list.className = 'pw-rules';
            list.hidden = true;
            PW_RULES.forEach(function (r) {
                var li = document.createElement('li');
                li.setAttribute('data-rule', r[0]);
                li.textContent = r[1];
                list.appendChild(li);
            });
            wrap.parentNode.insertBefore(list, wrap.nextSibling);

            input.addEventListener('input', function () {
                if (input.value !== '') list.hidden = false;
                var items = list.querySelectorAll('li');
                PW_RULES.forEach(function (r, i) {
                    items[i].classList.toggle('ok', r[2](input.value));
                });
            });
        }

        /* live "passwords match" indicator */
        var matchSel = input.getAttribute('data-pw-match');
        if (matchSel) {
            var target = document.querySelector(matchSel);
            if (target) {
                var ind = document.createElement('div');
                ind.className = 'pw-match';
                ind.hidden = true;
                wrap.parentNode.insertBefore(ind, wrap.nextSibling);

                var update = function () {
                    if (input.value === '') { ind.hidden = true; return; }
                    ind.hidden = false;
                    var ok = input.value === target.value;
                    ind.textContent = ok ? '\u2713 Passwords match' : '\u2717 Passwords do not match';
                    ind.className = 'pw-match ' + (ok ? 'ok' : 'bad');
                };
                input.addEventListener('input', update);
                target.addEventListener('input', update);
            }
        }
    });
}

