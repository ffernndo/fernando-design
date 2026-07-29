/* fernando_ · interações */
(function () {
    'use strict';

    /* Menu mobile */
    var nav = document.querySelector('.nav');
    var burger = document.querySelector('.nav-burger');
    if (burger) {
        burger.addEventListener('click', function () {
            nav.classList.toggle('open');
            burger.setAttribute('aria-expanded', nav.classList.contains('open'));
        });
        nav.querySelectorAll('.nav-links a').forEach(function (a) {
            a.addEventListener('click', function () { nav.classList.remove('open'); });
        });
    }

    /* Marquee: duplica o conteúdo para o loop infinito */
    document.querySelectorAll('.marquee-track').forEach(function (track) {
        track.innerHTML += track.innerHTML;
    });

    /* Reveal on scroll */
    var io = new IntersectionObserver(function (entries) {
        entries.forEach(function (e) {
            if (e.isIntersecting) {
                e.target.classList.add('in');
                io.unobserve(e.target);
            }
        });
    }, { threshold: 0.12, rootMargin: '0px 0px -6% 0px' });
    document.querySelectorAll('.rv').forEach(function (el) { io.observe(el); });

    /* FAQ: fecha os outros ao abrir um */
    var items = document.querySelectorAll('.faq-item');
    items.forEach(function (d) {
        d.addEventListener('toggle', function () {
            if (d.open) items.forEach(function (o) { if (o !== d) o.open = false; });
        });
    });


    /* ══ Serviços: índice + painel (padrão tablist) ══
       No mobile o CSS mostra todos os painéis; aqui o script só age quando as
       abas estão visíveis, para não esconder conteúdo em tela pequena. */
    var tabs = Array.prototype.slice.call(document.querySelectorAll('.idx-item'));
    if (tabs.length) {
        var panes = tabs.map(function (t) { return document.getElementById(t.getAttribute('aria-controls')); });

        function select(i, foca) {
            tabs.forEach(function (t, k) {
                var on = k === i;
                t.classList.toggle('on', on);
                t.setAttribute('aria-selected', on);
                t.tabIndex = on ? 0 : -1;
                if (panes[k]) {
                    panes[k].classList.toggle('on', on);
                    if (on) {
                        panes[k].removeAttribute('hidden');
                        panes[k].classList.remove('anim');
                        void panes[k].offsetWidth;   /* reinicia a animação */
                        panes[k].classList.add('anim');
                    } else {
                        panes[k].setAttribute('hidden', '');
                    }
                }
            });
            if (foca) tabs[i].focus();
        }

        tabs.forEach(function (t, i) {
            t.addEventListener('click', function () { select(i); });
            t.addEventListener('keydown', function (e) {
                var d = e.key === 'ArrowDown' || e.key === 'ArrowRight' ? 1
                      : e.key === 'ArrowUp' || e.key === 'ArrowLeft' ? -1 : 0;
                if (d) { e.preventDefault(); select((i + d + tabs.length) % tabs.length, true); }
                else if (e.key === 'Home') { e.preventDefault(); select(0, true); }
                else if (e.key === 'End') { e.preventDefault(); select(tabs.length - 1, true); }
            });
        });
    }

    /* ══ Parallax (leve, rAF, respeita reduced-motion) ══ */
    var reduced = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    if (!reduced) {
        var glow = document.querySelector('.hero-glow');
        var grid = document.querySelector('.hero-grid');
        var inners = [];
        document.querySelectorAll('.case-img img, .sobre-foto img').forEach(function (img) {
            img.classList.add('plx-inner');
            inners.push({ img: img, box: img.parentElement });
        });
        var ticking = false;
        function frame() {
            ticking = false;
            var y = window.scrollY;
            var vh = window.innerHeight;
            if (glow) glow.style.transform = 'translateY(' + (y * 0.22) + 'px)';
            if (grid) grid.style.transform = 'translateY(' + (y * 0.1) + 'px)';
            inners.forEach(function (o) {
                var r = o.box.getBoundingClientRect();
                if (r.bottom < 0 || r.top > vh) return;
                var p = (r.top + r.height / 2 - vh / 2) / (vh / 2); // -1..1
                o.img.style.transform = 'translateY(' + (-p * 5 - 4) + '%)';
            });
        }
        function onScroll() { if (!ticking) { ticking = true; requestAnimationFrame(frame); } }
        window.addEventListener('scroll', onScroll, { passive: true });
        window.addEventListener('resize', onScroll, { passive: true });
        frame();
    }

    /* ══ Lightbox das galerias ══ */
    var lb = document.createElement('div');
    lb.className = 'lb';
    lb.innerHTML = '<img alt="">';
    document.body.appendChild(lb);
    var lbImg = lb.querySelector('img');
    document.querySelectorAll('.app-fig .frame').forEach(function (f) {
        f.addEventListener('click', function () {
            lbImg.src = f.querySelector('img').src;
            lb.classList.add('open');
        });
    });
    lb.addEventListener('click', function () { lb.classList.remove('open'); });
    document.addEventListener('keydown', function (e) { if (e.key === 'Escape') lb.classList.remove('open'); });

})();

/* ══ Meta Pixel: eventos ══ */
(function () {
    if (typeof fbq !== 'function') return;

    /* Contact — clique em qualquer link de WhatsApp */
    document.addEventListener('click', function (e) {
        var a = e.target.closest && e.target.closest('a[href*="wa.me"], a[href*="api.whatsapp.com"]');
        if (a) fbq('track', 'Contact', { content_name: document.title });
    });

    /* ViewContent — páginas de case */
    if (location.pathname.indexOf('case-') > -1) {
        fbq('track', 'ViewContent', {
            content_type: 'case',
            content_name: document.title
        });
    }
})();
