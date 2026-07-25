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

    /* ══ Parallax (leve, rAF, respeita reduced-motion) ══ */
    var reduced = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    if (!reduced) {
        var glow = document.querySelector('.hero-glow');
        var grid = document.querySelector('.hero-grid');
        var inners = [];
        document.querySelectorAll('.case-img img, .sobre-foto img, .app-fig .frame img').forEach(function (img) {
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

    /* ══════════════════════════════════════════════
       LOJA — carrinho + checkout (PIX / cartão / WhatsApp)
       Config de pagamento: edite aqui.
       ══════════════════════════════════════════════ */
    var PAY = {
        pixKey: '+5521990228622',            // chave PIX (celular). Troque se usar outra.
        pixName: 'Fernando Cezar',           // nome do recebedor (max 25)
        pixCity: 'RIO DE JANEIRO',
        cardLink: '',                        // cole aqui o link de pagamento (Mercado Pago / InfinitePay) quando criar
        whats: '5521990228622'
    };
    var PRODUCTS = {
        site: { name: 'Site Express 48h', desc: 'Landing page publicada em até 48h úteis', price: 699 },
        video: { name: 'Vídeo de lançamento', desc: 'Reel 15–30s pra divulgar o site novo', price: 297 }
    };
    var fab = document.getElementById('cartFab');
    if (fab) {
        var cart = [];
        try { cart = JSON.parse(localStorage.getItem('cart_fd') || '[]'); } catch (e) { cart = []; }

        var ov = document.getElementById('cartOv');
        var panel = document.getElementById('cartPanel');
        var itemsEl = document.getElementById('cartItems');
        var totalEl = document.getElementById('cartTotal');
        var stepCart = document.getElementById('stepCart');
        var stepPay = document.getElementById('stepPay');
        var stepPix = document.getElementById('stepPix');

        function brl(v) { return v.toLocaleString('pt-BR', { style: 'currency', currency: 'BRL' }); }
        function total() { return cart.reduce(function (s, k) { return s + PRODUCTS[k].price; }, 0); }
        function save() { try { localStorage.setItem('cart_fd', JSON.stringify(cart)); } catch (e) {} }

        function render() {
            fab.classList.toggle('show', cart.length > 0);
            var n = fab.querySelector('.n'); if (n) n.textContent = cart.length;
            itemsEl.innerHTML = cart.length ? '' : '<div class="cart-empty">Seu carrinho está vazio. <a href="#express" class="link-live">Ver o Site Express 48h ↑</a></div>';
            cart.forEach(function (k, i) {
                var p = PRODUCTS[k];
                var div = document.createElement('div');
                div.className = 'cart-item';
                div.innerHTML = '<div><b>' + p.name + '</b><small>' + p.desc + '</small><br><button class="rm" data-i="' + i + '">remover</button></div><div class="pr">' + brl(p.price) + '</div>';
                itemsEl.appendChild(div);
            });
            totalEl.textContent = brl(total());
            itemsEl.querySelectorAll('.rm').forEach(function (b) {
                b.addEventListener('click', function () { cart.splice(+b.dataset.i, 1); save(); render(); });
            });
            document.querySelectorAll('[data-add]').forEach(function (btn) {
                var k = btn.dataset.add;
                btn.textContent = cart.indexOf(k) >= 0 ? '✓ NO CARRINHO' : btn.dataset.label;
            });
        }
        function openCart(step) {
            ov.classList.add('open'); panel.classList.add('open');
            [stepCart, stepPay, stepPix].forEach(function (s) { s.classList.remove('on'); });
            (step || stepCart).classList.add('on');
        }
        function closeCart() { ov.classList.remove('open'); panel.classList.remove('open'); }

        document.querySelectorAll('[data-add]').forEach(function (btn) {
            btn.dataset.label = btn.textContent.trim();
            btn.addEventListener('click', function () {
                var k = btn.dataset.add;
                if (cart.indexOf(k) < 0) cart.push(k);
                save(); render(); openCart(stepCart);
            });
        });
        fab.addEventListener('click', function () { openCart(stepCart); });
        ov.addEventListener('click', closeCart);
        document.getElementById('cartClose').addEventListener('click', closeCart);
        document.getElementById('goPay').addEventListener('click', function () { if (cart.length) openCart(stepPay); });
        document.querySelectorAll('.ck-back').forEach(function (b) {
            b.addEventListener('click', function () { openCart(b.dataset.to === 'pay' ? stepPay : stepCart); });
        });

        function orderText() {
            var lines = cart.map(function (k) { return '• ' + PRODUCTS[k].name + ' — ' + brl(PRODUCTS[k].price); });
            return 'Oi, Fernando! Quero fechar meu pedido:%0A' + encodeURIComponent(lines.join('\n')) + '%0A%0ATotal: ' + encodeURIComponent(brl(total()));
        }
        function waUrl(extra) { return 'https://wa.me/' + PAY.whats + '?text=' + orderText() + (extra ? encodeURIComponent(extra) : ''); }

        /* PIX — BR Code estático (EMV) */
        function tlv(id, v) { return id + ('0' + v.length).slice(-2) + v; }
        function crc16(s) {
            var crc = 0xFFFF;
            for (var i = 0; i < s.length; i++) {
                crc ^= s.charCodeAt(i) << 8;
                for (var j = 0; j < 8; j++) crc = (crc & 0x8000) ? ((crc << 1) ^ 0x1021) & 0xFFFF : (crc << 1) & 0xFFFF;
            }
            return ('000' + crc.toString(16).toUpperCase()).slice(-4);
        }
        function pixPayload(amount) {
            var p = tlv('00', '01')
                + tlv('26', tlv('00', 'br.gov.bcb.pix') + tlv('01', PAY.pixKey))
                + tlv('52', '0000') + tlv('53', '986')
                + tlv('54', amount.toFixed(2))
                + tlv('58', 'BR')
                + tlv('59', PAY.pixName.slice(0, 25))
                + tlv('60', PAY.pixCity.slice(0, 15))
                + tlv('62', tlv('05', 'EXPRESS48H'))
                + '6304';
            return p + crc16(p);
        }
        document.getElementById('payPix').addEventListener('click', function () {
            var payload = pixPayload(total());
            var box = document.getElementById('pixQr');
            box.innerHTML = '';
            if (window.qrcode) {
                var qr = window.qrcode(0, 'M');
                qr.addData(payload); qr.make();
                box.innerHTML = qr.createSvgTag({ scalable: true, margin: 0 });
            }
            document.getElementById('pixCode').textContent = payload;
            document.getElementById('pixVal').textContent = brl(total());
            document.getElementById('pixDone').href = waUrl('\n\nPaguei via PIX — segue o comprovante:');
            openCart(stepPix);
        });
        document.getElementById('pixCopy').addEventListener('click', function () {
            navigator.clipboard.writeText(document.getElementById('pixCode').textContent).then(function () {
                document.getElementById('pixCopy').querySelector('span').textContent = '✓ CÓDIGO COPIADO';
            });
        });
        document.getElementById('payCard').addEventListener('click', function () {
            if (PAY.cardLink) { window.open(PAY.cardLink, '_blank', 'noopener'); }
            else { window.open(waUrl('\n\nQuero pagar no cartão de crédito.'), '_blank', 'noopener'); }
        });
        document.getElementById('payWa').addEventListener('click', function () {
            window.open(waUrl(''), '_blank', 'noopener');
        });
        render();
    }
})();
