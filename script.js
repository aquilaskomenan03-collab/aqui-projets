const toggle = document.querySelector('.menu-toggle');
const nav = document.querySelector('.nav-links');
toggle.addEventListener('click', () => {
  const open = nav.classList.toggle('open');
  toggle.setAttribute('aria-expanded', String(open));
  toggle.setAttribute('aria-label', open ? 'Fermer le menu' : 'Ouvrir le menu');
});
nav.querySelectorAll('a').forEach(link => link.addEventListener('click', () => {
  nav.classList.remove('open');
  toggle.setAttribute('aria-expanded', 'false');
}));
document.getElementById('year').textContent = new Date().getFullYear();

const form = document.getElementById('quote-form');
const formStatus = document.getElementById('form-status');
const accountChoice = form.querySelector('input[name="create_account"]');
const accountPasswordWrap = document.getElementById('account-password-wrap');
const accountPasswordInput = form.elements.account_password;
accountChoice.addEventListener('change', () => {
  accountPasswordWrap.hidden = !accountChoice.checked;
  accountPasswordInput.required = accountChoice.checked;
});
form.addEventListener('submit', async event => {
  event.preventDefault();
  if (!form.reportValidity()) return;
  const button = form.querySelector('.form-submit');
  button.disabled = true;
  formStatus.textContent = 'Envoi en cours…';
  formStatus.className = 'form-status';
  const values = Object.fromEntries(new FormData(form).entries());
  try {
    const response = await fetch('/api/leads', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(values)
    });
    const result = await response.json();
    if (!response.ok) throw new Error(result.error || 'Impossible d’envoyer la demande.');
    form.reset(); accountPasswordWrap.hidden = true; accountPasswordInput.required = false;
    formStatus.replaceChildren();
    const confirmation = document.createElement('span');
      confirmation.textContent = result.account_created
        ? 'Demande envoyée et espace client créé. Connecte-toi avec ton e-mail et ton mot de passe pour retrouver tes travaux et leurs photos.'
        : result.account_exists
          ? 'Cette adresse e-mail possède déjà un espace client. Ta demande a été envoyée et rattachée à ce compte; aucun nouveau compte n’a été créé. Connecte-toi sur /client avec le mot de passe existant pour la retrouver.'
          : 'Merci, votre demande a bien été envoyée. Conserve ces codes ou crée un compte client à partir de la page de suivi.';
    const reference = document.createElement('strong');
    reference.textContent = `Référence : ${result.reference}`;
    const accessCode = document.createElement('strong');
    accessCode.textContent = `Code d’accès : ${result.access_code}`;
    const trackingLink = document.createElement('a');
    trackingLink.href = '/client';
    trackingLink.textContent = 'Ouvrir mon espace de suivi';
    trackingLink.className = 'tracking-link';
    const receiptActions = document.createElement('div');
    receiptActions.className = 'receipt-actions';
    const printReceipt = document.createElement('button');
    printReceipt.type = 'button'; printReceipt.textContent = 'Imprimer / enregistrer en PDF';
    printReceipt.addEventListener('click', () => window.print());
    const shareReceipt = document.createElement('a');
    const receiptText = `MG Multiservice — codes de suivi\nRéférence : ${result.reference}\nCode d’accès : ${result.access_code}\nLien : ${location.origin}/client\nConservez ces informations.`;
    shareReceipt.href = `https://wa.me/?text=${encodeURIComponent(receiptText)}`;
    shareReceipt.target = '_blank'; shareReceipt.rel = 'noopener noreferrer';
    shareReceipt.textContent = 'M’envoyer ces codes sur WhatsApp';
    receiptActions.append(printReceipt, shareReceipt);
    formStatus.append(confirmation, reference, accessCode, trackingLink, receiptActions);
    formStatus.classList.add('success', 'tracking-receipt');
  } catch (error) {
    formStatus.textContent = error.message || 'Une erreur est survenue. Réessayez ou contactez-nous par téléphone.';
    formStatus.classList.add('error');
  } finally {
    button.disabled = false;
  }
});

// Load the photos and practical company details maintained from the admin page.
const photoLabels = { Menuiserie: 'wood-placeholder', Peinture: 'paint-placeholder', Vernissage: 'varnish-placeholder' };
fetch('/api/photos').then(response => response.ok ? response.json() : []).then(photos => {
  const grid = document.getElementById('portfolio-grid');
  if (!grid || !Array.isArray(photos) || !photos.length) return;
  const populated = new Set(photos.map(photo => photo.category.toLowerCase()));
  grid.querySelectorAll('.photo-card[data-photo-slot]').forEach(card => { if (populated.has(card.dataset.photoSlot)) card.hidden = true; });
  for (const photo of photos) {
    if (!photoLabels[photo.category]) continue;
    const card = document.createElement('article');
    card.className = 'photo-card uploaded-photo';
    card.dataset.reveal = '';
    const image = document.createElement('img');
    image.src = photo.url; image.alt = `${photo.category} : ${photo.title}`; image.loading = 'lazy';
    const visual = document.createElement('div'); visual.className = `uploaded-photo-visual ${photoLabels[photo.category]}`; visual.append(image);
    const meta = document.createElement('div'); meta.className = 'photo-card-meta';
    const title = document.createElement('h3'); title.textContent = photo.title;
    const category = document.createElement('span'); category.textContent = photo.category;
    meta.append(title, category); card.append(visual, meta); grid.append(card); io.observe(card);
  }
}).catch(() => {});

fetch('/api/site-info').then(response => response.ok ? response.json() : null).then(info => {
  if (!info) return;
  for (const [key, value] of Object.entries(info)) {
    document.querySelectorAll(`[data-site-info="${key}"]`).forEach(element => { element.textContent = value; });
    if (key === 'publicEmail' && value) {
      const emailLink = document.querySelector('[data-public-email]');
      if (emailLink) { emailLink.href = `mailto:${value}`; emailLink.childNodes[1].textContent = `${value} `; }
    }
    const row = document.querySelector(`[data-info-row="${key}"]`);
    if (row && value) row.hidden = false;
  }
}).catch(() => {});

/* Animations : en-tête, progression, apparitions */
document.documentElement.classList.add('js');
const header = document.querySelector('.site-header');
const bar = document.querySelector('.progress');
const onScroll = () => {
  header.classList.toggle('scrolled', window.scrollY > 30);
  const max = document.documentElement.scrollHeight - innerHeight;
  bar.style.transform = `scaleX(${max > 0 ? scrollY / max : 0})`;
};
addEventListener('scroll', onScroll, { passive: true });
onScroll();
const io = new IntersectionObserver(entries => entries.forEach(e => {
  if (e.isIntersecting) { e.target.classList.add('in'); io.unobserve(e.target); }
}), { threshold: .18 });
document.querySelectorAll('[data-reveal],[data-steps],[data-mark]').forEach(el => io.observe(el));

// Scroll motion for the sections below the hero; the homepage scene stays untouched.
const secondaryMotion = document.querySelectorAll(
  '#approche .section-heading, #approche .step, footer .footer-brand, footer > p, footer .back-top, footer .admin-link'
);
secondaryMotion.forEach((el, index) => {
  el.setAttribute('data-reveal', '');
  el.style.setProperty('--d', `${Math.min(index * 0.08, 0.32)}s`);
  io.observe(el);
});

// A soft brand-colour highlight follows the pointer across service cards.
if (matchMedia('(pointer:fine)').matches && !matchMedia('(prefers-reduced-motion: reduce)').matches) {
  document.querySelectorAll('.service-card').forEach(card => {
    card.addEventListener('pointermove', event => {
      const rect = card.getBoundingClientRect();
      card.style.setProperty('--spot-x', `${event.clientX - rect.left}px`);
      card.style.setProperty('--spot-y', `${event.clientY - rect.top}px`);
    });
  });
}

/* Lumière d'ambiance pour les sections de contenu, en laissant la scène d'accueil intacte. */
(() => {
  const sections = ['#services', '#realisations', '#apropos', '#approche', '#contact']
    .map(selector => document.querySelector(selector))
    .filter(Boolean);
  if (!sections.length) return;

  const reduced = matchMedia('(prefers-reduced-motion: reduce)').matches;
  const finePointer = matchMedia('(pointer: fine)').matches;
  const palette = ['27,132,234', '245,160,28'];

  sections.forEach((section, sectionIndex) => {
    section.classList.add('light-stage');
    const canvas = document.createElement('canvas');
    canvas.className = 'section-light-canvas';
    canvas.setAttribute('aria-hidden', 'true');
    section.prepend(canvas);

    const context = canvas.getContext('2d');
    if (!context) return;
    const dpr = Math.min(devicePixelRatio || 1, 2);
    let width = 0, height = 0, frame = 0, active = false;
    let pointerX = .5, pointerY = .18, targetX = .5, targetY = .18;
    const particles = [];

    const resize = () => {
      const rect = section.getBoundingClientRect();
      width = rect.width;
      height = section.scrollHeight;
      pointerY = targetY = height > width * 1.45 ? .16 : .4;
      canvas.width = Math.max(1, Math.round(width * dpr));
      canvas.height = Math.max(1, Math.round(height * dpr));
      context.setTransform(dpr, 0, 0, dpr, 0, 0);
      const amount = Math.max(20, Math.min(42, Math.round(width / 26)));
      particles.length = 0;
      for (let i = 0; i < amount; i++) {
        particles.push({
          x: Math.random() * width,
          y: Math.random() * height,
          radius: 1 + Math.random() * 2,
          phase: Math.random() * Math.PI * 2,
          drift: .08 + Math.random() * .2,
          color: palette[(i + sectionIndex) % palette.length]
        });
      }
      paint(performance.now());
    };

    const paint = time => {
      context.clearRect(0, 0, width, height);
      pointerX += (targetX - pointerX) * .035;
      pointerY += (targetY - pointerY) * .035;

      // Broad moving shafts, deliberately faint so text remains easy to read.
      for (let i = 0; i < 2; i++) {
        const center = width * (.2 + .6 * i) + Math.sin(time * .00012 + i + sectionIndex) * width * .09;
        const beam = context.createLinearGradient(center - 150, 0, center + 190, height);
        beam.addColorStop(0, 'rgba(' + palette[(i + sectionIndex) % 2] + ',0)');
        beam.addColorStop(.5, 'rgba(' + palette[(i + sectionIndex) % 2] + ',.11)');
        beam.addColorStop(1, 'rgba(' + palette[(i + sectionIndex) % 2] + ',0)');
        context.fillStyle = beam;
        context.fillRect(0, 0, width, height);
      }

      const glowX = pointerX * width + Math.sin(time * .0003 + sectionIndex) * width * .08;
      const glowY = pointerY * height + Math.cos(time * .00022 + sectionIndex) * height * .05;
      const glow = context.createRadialGradient(glowX, glowY, 0, glowX, glowY, Math.max(300, width * .48));
      glow.addColorStop(0, 'rgba(27,132,234,.28)');
      glow.addColorStop(.48, 'rgba(245,160,28,.10)');
      glow.addColorStop(1, 'rgba(27,132,234,0)');
      context.fillStyle = glow;
      context.fillRect(0, 0, width, height);

      particles.forEach((particle, index) => {
        const twinkle = .28 + (Math.sin(time * .001 + particle.phase) + 1) * .22;
        const y = (particle.y - time * particle.drift * .012 + height) % height;
        const x = particle.x + Math.sin(time * .00025 + particle.phase) * 10;
        const radius = particle.radius * 16;
        const spark = context.createRadialGradient(x, y, 0, x, y, radius);
        spark.addColorStop(0, 'rgba(' + particle.color + ',' + Math.min(.9, twinkle + .2) + ')');
        spark.addColorStop(1, 'rgba(' + particle.color + ',0)');
        context.fillStyle = spark;
        context.beginPath();
        context.arc(x, y, radius, 0, Math.PI * 2);
        context.fill();
        context.beginPath();
        context.fillStyle = 'rgba(' + particle.color + ',' + Math.min(.85, twinkle + .25) + ')';
        context.arc(x, y, Math.max(1.2, particle.radius * .8), 0, Math.PI * 2);
        context.fill();

        for (let next = index + 1; next < particles.length; next++) {
          const other = particles[next];
          const otherY = (other.y - time * other.drift * .012 + height) % height;
          const dx = x - other.x, dy = y - otherY;
          const distance = Math.hypot(dx, dy);
          if (distance < 115) {
            context.strokeStyle = 'rgba(80,155,220,' + ((1 - distance / 115) * .11) + ')';
            context.lineWidth = 1;
            context.beginPath();
            context.moveTo(x, y);
            context.lineTo(other.x, otherY);
            context.stroke();
          }
        }
      });

      if (active && !reduced) frame = requestAnimationFrame(paint);
    };

    if (finePointer && !reduced) {
      section.addEventListener('pointermove', event => {
        const rect = section.getBoundingClientRect();
        targetX = Math.max(0, Math.min(1, (event.clientX - rect.left) / rect.width));
        targetY = Math.max(0, Math.min(1, (event.clientY - rect.top) / rect.height));
      }, { passive: true });
    }

    const observer = new IntersectionObserver(([entry]) => {
      active = entry.isIntersecting;
      if (active && !reduced && !frame) frame = requestAnimationFrame(paint);
      if (!active && frame) { cancelAnimationFrame(frame); frame = 0; }
      if (reduced && active) paint(0);
    }, { rootMargin: '100px 0px' });
    observer.observe(section);
    addEventListener('resize', resize, { passive: true });
    if ('ResizeObserver' in window) new ResizeObserver(resize).observe(section);
    resize();
  });
})();
