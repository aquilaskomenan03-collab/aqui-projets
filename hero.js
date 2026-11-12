(() => {
  const h = document.querySelector('.stage'); if (!h) return;
  const c = h.querySelector('.fx'), x = c.getContext('2d'), em = h.querySelector('.emblem');
  const still = matchMedia('(prefers-reduced-motion:reduce)').matches;
  const D = Math.min(devicePixelRatio || 1, 2);
  let W, H, P = [], mx = .5, my = .4, tx = .5, ty = .4, last = -1e9, on = true;
  const spark = (px, py, burst) => ({
    x: px ?? Math.random() * W, y: py ?? Math.random() * H, b: burst,
    r: burst ? 1 + Math.random() * 2 : 1 + Math.random() * 2.4, t: Math.random() * 6.3, l: 1,
    vx: (Math.random() - .5) * (burst ? 1.8 : .3), vy: burst ? (Math.random() - .85) * 1.6 : -(.12 + Math.random() * .4),
    c: Math.random() < .5 ? '27,132,234' : '245,160,28'
  });
  const size = () => {
    const r = h.getBoundingClientRect(); W = r.width; H = r.height;
    c.width = W * D; c.height = H * D; x.setTransform(D, 0, 0, D, 0, 0);
    P = Array.from({ length: W < 700 ? 38 : 80 }, () => spark());
  };
  size(); addEventListener('resize', size);
  h.addEventListener('pointermove', e => {
    const r = h.getBoundingClientRect();
    tx = (e.clientX - r.left) / r.width; ty = (e.clientY - r.top) / r.height; last = performance.now();
    if (P.length < 260) P.push(spark(e.clientX - r.left, e.clientY - r.top, true), spark(e.clientX - r.left, e.clientY - r.top, true));
  });
  new IntersectionObserver(([e]) => { on = e.isIntersecting; if (on) requestAnimationFrame(loop); }).observe(h);
  function loop(now) {
    if (!on) return;
    if (now - last > 2500) { tx = .5 + .32 * Math.sin(now / 2300); ty = .42 + .22 * Math.sin(now / 1700); }
    mx += (tx - mx) * .06; my += (ty - my) * .06;
    h.style.setProperty('--mx', mx * 100 + '%'); h.style.setProperty('--my', my * 100 + '%');
    em.style.transform = `perspective(900px) rotateY(${(mx - .5) * 16}deg) rotateX(${(.5 - my) * 11}deg)`;
    x.clearRect(0, 0, W, H); x.globalCompositeOperation = 'lighter';
    P = P.filter(p => {
      p.t += .035; p.x += p.vx; p.y += p.vy;
      if (p.b) { p.l -= .017; if (p.l <= 0) return false; }
      else { if (p.y < -12) { p.y = H + 12; p.x = Math.random() * W; } if (p.x < -12) p.x = W + 12; if (p.x > W + 12) p.x = -12; }
      const a = (.35 + .35 * Math.sin(p.t)) * (p.b ? p.l : 1), R = p.r * 6;
      const g = x.createRadialGradient(p.x, p.y, 0, p.x, p.y, R);
      g.addColorStop(0, `rgba(${p.c},${a})`); g.addColorStop(1, `rgba(${p.c},0)`);
      x.fillStyle = g; x.beginPath(); x.arc(p.x, p.y, R, 0, 6.283); x.fill(); return true;
    });
    if (!still) requestAnimationFrame(loop);
  }
  requestAnimationFrame(loop);
})();
