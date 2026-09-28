/* Smart Hostel - shared JS */

document.addEventListener('DOMContentLoaded', function () {

    /* Auto-hide flash alerts */
    document.querySelectorAll('.alert[data-autohide]').forEach(function (el) {
        setTimeout(function () {
            el.style.transition = 'opacity .5s';
            el.style.opacity = '0';
            setTimeout(function () { el.style.display = 'none'; }, 500);
        }, 6000);
    });

    /* Confirm dangerous actions */
    document.querySelectorAll('[data-confirm]').forEach(function (el) {
        el.addEventListener('submit', function (e) {
            if (!confirm(el.getAttribute('data-confirm'))) e.preventDefault();
        });
        el.addEventListener('click', function (e) {
            if (el.tagName === 'A' && !confirm(el.getAttribute('data-confirm'))) e.preventDefault();
        });
    });

    /* Modal open/close */
    document.querySelectorAll('[data-modal-open]').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var m = document.getElementById(btn.getAttribute('data-modal-open'));
            if (m) m.classList.add('show');
        });
    });
    document.querySelectorAll('[data-modal-close]').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var m = btn.closest('.modal-overlay');
            if (m) m.classList.remove('show');
        });
    });
    document.querySelectorAll('.modal-overlay').forEach(function (m) {
        m.addEventListener('click', function (e) {
            if (e.target === m) m.classList.remove('show');
        });
    });

    /* Password field UX: eye toggle + live validation (see below) */
    pwEnhanceAll();
    authBladeTransition();
});

/* Modal helpers used by inline page scripts */
function openModal(id) { var m = document.getElementById(id); if (m) m.classList.add('show'); }
function closeModal(id) { var m = document.getElementById(id); if (m) m.classList.remove('show'); }

/* In-place card transition: a diagonal opaque blade hides the handoff. */
function authBladeTransition() {
    var page = document.querySelector('.auth-showcase');
    if (!page) return;
    var reduced = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    var show = function (register) {
        page.classList.toggle('auth-register', register);
        page.classList.toggle('auth-login', !register);
        document.getElementById('panel-login').inert = register;
        document.getElementById('panel-register').inert = !register;
        history.replaceState({}, '', register ? '/auth/login.php?panel=register' : '/auth/login.php');
    };
    show(new URLSearchParams(location.search).get('panel') === 'register' || !!document.querySelector('#panel-register .alert')); 
    document.querySelectorAll('a.auth-switch').forEach(function (link) {
        link.addEventListener('click', function (e) {
            if (e.button !== 0 || e.ctrlKey || e.metaKey || e.shiftKey || e.altKey) return;
            e.preventDefault();
            if (page.classList.contains('auth-moving')) return;
            var next = page.classList.contains('auth-login');
            if (reduced) { show(next); return; }
            page.classList.add(next ? 'auth-moving-right' : 'auth-moving-left');
            setTimeout(function () { show(next); }, 420);
            setTimeout(function () { page.classList.remove('auth-moving-right', 'auth-moving-left'); }, 850);
        });
    });
}
