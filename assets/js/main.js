(() => {
  'use strict';

  const reduceMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;

  // ======= NAV: scrolled state =======
  const nav = document.getElementById('nav');
  const onScroll = () => {
    if (window.scrollY > 16) nav.classList.add('is-scrolled');
    else nav.classList.remove('is-scrolled');
  };
  document.addEventListener('scroll', onScroll, { passive: true });
  onScroll();

  // ======= NAV: mobile menu =======
  const burger = document.getElementById('navBurger');
  const mobile = document.getElementById('navMobile');
  const closeMenu = () => {
    mobile?.classList.remove('is-open');
    burger?.setAttribute('aria-expanded', 'false');
  };
  burger?.addEventListener('click', () => {
    const open = mobile.classList.toggle('is-open');
    burger.setAttribute('aria-expanded', String(open));
    if (open) { const first = mobile.querySelector('a'); if (first) first.focus(); }
  });
  mobile?.querySelectorAll('a').forEach(a => a.addEventListener('click', closeMenu));
  document.addEventListener('keydown', (e) => {
    if (e.key === 'Escape' && mobile?.classList.contains('is-open')) {
      closeMenu();
      burger?.focus();
    }
  });

  // ======= MAGNETIC BUTTONS =======
  if (!reduceMotion && window.matchMedia('(hover: hover)').matches) {
    document.querySelectorAll('[data-magnetic]').forEach((btn) => {
      btn.addEventListener('mousemove', (ev) => {
        const r = btn.getBoundingClientRect();
        const x = ev.clientX - r.left - r.width / 2;
        const y = ev.clientY - r.top - r.height / 2;
        btn.style.transform = `translate(${x * 0.18}px, ${y * 0.22}px)`;
      });
      btn.addEventListener('mouseleave', () => { btn.style.transform = ''; });
    });
  }

  // ======= REVEAL ON SCROLL =======
  const reveals = document.querySelectorAll('.reveal');

  if (reduceMotion || !('IntersectionObserver' in window)) {
    reveals.forEach(el => el.classList.add('is-visible'));
  } else {
    const io = new IntersectionObserver((entries) => {
      entries.forEach((entry) => {
        if (!entry.isIntersecting) return;
        const target = entry.target;
        // Prefer the handoff's authored data-reveal-delay (ms);
        // otherwise derive a small stagger from the sibling index.
        const authored = target.getAttribute('data-reveal-delay');
        let delay;
        if (authored !== null) {
          delay = parseInt(authored, 10) || 0;
        } else {
          const siblings = target.parentElement?.querySelectorAll(':scope > .reveal') || [];
          const idx = Array.from(siblings).indexOf(target);
          delay = idx >= 0 ? Math.min(idx * 70, 350) : 0;
        }
        target.style.setProperty('--reveal-delay', `${delay}ms`);
        target.classList.add('is-visible');
        io.unobserve(target);
      });
    }, { threshold: 0.12, rootMargin: '0px 0px -7% 0px' });
    reveals.forEach(el => io.observe(el));
  }

  // ======= CONTACT FORM (progressive enhancement) =======
  const form = document.getElementById('contactForm');
  if (form) {
    const statusEl = document.getElementById('formStatus');
    const isPT = (form.elements['lang'] && form.elements['lang'].value) !== 'en';
    const emailRe = /^[^@\s]+@[^@\s]+\.[^@\s]+$/;
    const setStatus = (msg, ok) => {
      if (!statusEl) return;
      statusEl.hidden = false;
      statusEl.textContent = msg;
      statusEl.className = 'form-status ' + (ok ? 'form-status--ok' : 'form-status--err');
    };
    const validate = () => {
      let ok = true;
      [['name', v => v.length >= 2], ['email', v => emailRe.test(v)], ['message', v => v.length >= 10]]
        .forEach(([n, test]) => {
          const el = form.elements[n];
          const good = el && test(el.value.trim());
          const wrap = el && el.closest('.field');
          if (wrap) wrap.classList.toggle('field--invalid', !good);
          if (el) el.setAttribute('aria-invalid', good ? 'false' : 'true');
          if (!good) ok = false;
        });
      return ok;
    };

    form.addEventListener('input', (e) => {
      const wrap = e.target.closest && e.target.closest('.field');
      if (wrap) wrap.classList.remove('field--invalid');
      if (e.target && e.target.removeAttribute) e.target.removeAttribute('aria-invalid');
    });

    form.addEventListener('submit', (e) => {
      e.preventDefault();
      if (!validate()) {
        setStatus(isPT ? 'Revise os campos destacados.' : 'Please check the highlighted fields.', false);
        return;
      }
      const btn = form.querySelector('[type="submit"]');
      const label = btn ? btn.textContent : '';
      if (btn) { btn.disabled = true; btn.textContent = isPT ? 'Enviando…' : 'Sending…'; }

      fetch(form.action, {
        method: 'POST',
        headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' },
        body: new FormData(form),
      })
        .then(res => res.json().then(data => ({ ok: res.ok, data })).catch(() => ({ ok: res.ok, data: {} })))
        .then(({ ok, data }) => {
          if (ok && data.ok) {
            form.reset();
            setStatus(isPT
              ? 'Mensagem enviada — retornamos em até 24 horas úteis.'
              : "Message sent — we'll get back to you within 24 business hours.", true);
          } else {
            setStatus(data.error || (isPT ? 'Não foi possível enviar. Tente o e-mail direto.' : 'Could not send. Please email us directly.'), false);
          }
        })
        .catch(() => {
          setStatus(isPT ? 'Falha de conexão. Tente novamente ou use o e-mail.' : 'Connection error. Please try again or use email.', false);
        })
        .finally(() => {
          if (btn) { btn.disabled = false; btn.textContent = label; }
        });
    });
  }

  // ======= FOUNDER PHOTO FALLBACK (replaces inline onerror, CSP-friendly) =======
  document.querySelectorAll('.founder__photo').forEach((img) => {
    const showFallback = () => {
      img.style.display = 'none';
      const mono = img.nextElementSibling;
      if (mono) mono.style.display = 'flex';
    };
    img.addEventListener('error', showFallback);
    if (img.complete && img.naturalWidth === 0) showFallback();
  });

  // ======= YEAR =======
  const yearEl = document.getElementById('year');
  if (yearEl) yearEl.textContent = String(new Date().getFullYear());

  // ======= SMOOTH ANCHOR (offset for fixed nav) =======
  document.querySelectorAll('a[href^="#"]').forEach(link => {
    link.addEventListener('click', (e) => {
      const id = link.getAttribute('href');
      if (!id || id === '#') return;
      const target = document.querySelector(id);
      if (!target) return;
      e.preventDefault();
      const top = target.getBoundingClientRect().top + window.scrollY - 60;
      window.scrollTo({ top, behavior: reduceMotion ? 'auto' : 'smooth' });
    });
  });
})();
