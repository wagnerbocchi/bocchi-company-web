(() => {
  'use strict';

  // ======= NAV: scrolled state =======
  const nav = document.getElementById('nav');
  const onScroll = () => {
    if (window.scrollY > 12) nav.classList.add('is-scrolled');
    else nav.classList.remove('is-scrolled');
  };
  document.addEventListener('scroll', onScroll, { passive: true });
  onScroll();

  // ======= NAV: mobile menu =======
  const burger = document.getElementById('navBurger');
  const mobile = document.getElementById('navMobile');
  burger?.addEventListener('click', () => {
    const open = mobile.classList.toggle('is-open');
    burger.setAttribute('aria-expanded', String(open));
  });
  mobile?.querySelectorAll('a').forEach(a => {
    a.addEventListener('click', () => {
      mobile.classList.remove('is-open');
      burger.setAttribute('aria-expanded', 'false');
    });
  });

  // ======= REVEAL ON SCROLL =======
  const reduceMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
  const reveals = document.querySelectorAll('.reveal');

  if (reduceMotion || !('IntersectionObserver' in window)) {
    reveals.forEach(el => el.classList.add('is-visible'));
  } else {
    const io = new IntersectionObserver((entries) => {
      entries.forEach((entry, i) => {
        if (entry.isIntersecting) {
          const target = entry.target;
          // small staggered delay among siblings
          const siblings = target.parentElement?.querySelectorAll(':scope > .reveal') || [];
          const idx = Array.from(siblings).indexOf(target);
          if (idx >= 0) target.style.setProperty('--reveal-delay', `${Math.min(idx * 70, 350)}ms`);
          target.classList.add('is-visible');
          io.unobserve(target);
        }
      });
    }, { threshold: 0.12, rootMargin: '0px 0px -40px 0px' });
    reveals.forEach(el => io.observe(el));
  }

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
