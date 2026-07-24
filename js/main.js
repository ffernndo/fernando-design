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
})();
