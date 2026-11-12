'use strict';
const shell = document.getElementById('client-shell');
const form = document.getElementById('client-form');
const registerForm = document.getElementById('register-form');
const legacyForm = document.getElementById('legacy-form');
const feedback = document.getElementById('client-feedback');
const refreshStatus = document.getElementById('refresh-status');
const jobsList = document.getElementById('jobs-list');
const passwordForm = document.getElementById('password-form');
const passwordFeedback = document.getElementById('password-feedback');
const resetRequestForm = document.getElementById('reset-request-form');
const resetRequestFeedback = document.getElementById('reset-request-feedback');
const resetCompleteForm = document.getElementById('reset-complete-form');
const resetCompleteFeedback = document.getElementById('reset-complete-feedback');
const resetPanel = document.getElementById('client-reset-panel');
const resetToken = new URLSearchParams(window.location.hash.slice(1)).get('reset') || '';
if (resetToken) { document.getElementById('client-login').hidden = true; resetPanel.hidden = false; shell.classList.add('reset-mode'); }

function formatCfa(amount) { return `${Math.round(Number(amount || 0)).toLocaleString('fr-FR')} FCFA`; }
function formatUtc(value) { return new Date(`${value.replace(' ', 'T')}Z`).toLocaleString('fr-FR', { timeZone: 'UTC' }); }
function escapeHtml(value) { return String(value ?? '').replace(/[&<>"']/g, char => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[char])); }

function printQuote(job) {
  const popup = window.open('', '_blank');
  if (!popup) { window.alert('Autorise l’ouverture de la fenêtre pour imprimer ou enregistrer le devis en PDF.'); return; }
  const rows = (job.quote_items || []).map(item => `<tr><td>${escapeHtml(item.name)}</td><td>${escapeHtml(item.quantity)}</td><td>${formatCfa(item.unit_price)}</td><td>${formatCfa(item.total)}</td></tr>`).join('');
  const acceptedAt = job.quote_accepted_at ? formatUtc(job.quote_accepted_at) : 'En attente de réponse';
  const html = `<!doctype html><html lang="fr"><head><meta charset="utf-8"><title>Devis ${escapeHtml(job.tracking_ref)} — MG Multiservice</title><style>body{font:14px Arial,sans-serif;color:#182333;margin:42px}header{display:flex;justify-content:space-between;border-bottom:3px solid #f59a18;padding-bottom:18px}h1{font-size:26px;margin:0 0 8px}h2{font-size:17px;margin-top:28px}small,.muted{color:#526273}table{width:100%;border-collapse:collapse;margin-top:12px}th,td{text-align:left;border-bottom:1px solid #ccd5dd;padding:10px 8px}th{background:#eef3f7}.total{font-size:18px;font-weight:bold;text-align:right;margin-top:18px}.terms{margin-top:28px;padding:14px;background:#f2f5f8;white-space:pre-wrap}.signature{margin-top:36px;display:grid;grid-template-columns:1fr 1fr;gap:50px}.print{position:fixed;right:20px;top:20px;padding:10px 14px;background:#f59a18;border:0;border-radius:5px;font-weight:bold;cursor:pointer}@media print{body{margin:14mm}.print{display:none}}</style></head><body><button class="print" onclick="window.print()">Imprimer / Enregistrer en PDF</button><header><div><h1>MG Multiservice Groupe</h1><div>Menuiserie · Peinture · Vernissage · Abidjan</div><small>+225 01 03 01 85 78 · contact@devsitwo.ci</small></div><div><strong>DEVIS</strong><br>Référence : ${escapeHtml(job.tracking_ref)}<br>Émis le : ${escapeHtml(new Date(`${job.quote_sent_at.replace(' ', 'T')}Z`).toLocaleDateString('fr-FR'))}</div></header><h2>Client et projet</h2><p><strong>${escapeHtml(job.name)}</strong><br>${escapeHtml(job.phone)}${job.email ? `<br>${escapeHtml(job.email)}` : ''}<br>Service : ${escapeHtml(job.service)}<br>Description : ${escapeHtml(job.message)}</p><h2>Détail estimatif</h2><table><thead><tr><th>Matériaux / prestations</th><th>Qté</th><th>Prix unitaire</th><th>Total</th></tr></thead><tbody>${rows}<tr><td>Main-d’œuvre</td><td>—</td><td>—</td><td>${formatCfa(job.quote_labor)}</td></tr><tr><td>Autres frais</td><td>—</td><td>—</td><td>${formatCfa(job.quote_extra)}</td></tr></tbody></table><p class="total">TOTAL : ${formatCfa(job.quote_total)}</p>${Number(job.quote_deposit || 0) > 0 ? `<p><strong>Avance prévue après acceptation : ${formatCfa(job.quote_deposit)}</strong></p>` : ''}${job.quote_note ? `<div class="terms"><strong>Notes et conditions</strong><br>${escapeHtml(job.quote_note)}</div>` : ''}<p><strong>Réponse du client :</strong> ${escapeHtml(job.client_decision)}${job.quote_accepted_at ? ` — enregistrée le ${escapeHtml(acceptedAt)} (heure UTC)` : ''}</p><div class="signature"><div>Pour MG Multiservice Groupe<br><br>Nom et signature : __________________</div><div>Le client<br><br>Acceptation / date : __________________</div></div><p class="muted">Document généré depuis l’espace client. La preuve de décision électronique est conservée dans le système.</p><script>setTimeout(()=>window.print(),250)</script></body></html>`;
  popup.document.open(); popup.document.write(html); popup.document.close();
}

function addDetail(card, label, value) {
  const detail = document.createElement('div'); detail.className = 'job-detail';
  const title = document.createElement('span'); title.textContent = label;
  const content = document.createElement('p'); content.textContent = value || '—';
  detail.append(title, content); card.append(detail);
}

function renderQuote(job, card) {
  const box = document.createElement('section'); box.className = 'quote-box';
  const heading = document.createElement('h3'); heading.textContent = 'Devis détaillé'; box.append(heading);
  if (!job.quote_sent_at) {
    const pending = document.createElement('p'); pending.textContent = 'La demande est transmise à l’équipe. Le devis apparaîtra ici dès qu’il sera préparé.'; box.append(pending); card.append(box); return;
  }
  const table = document.createElement('div'); table.className = 'quote-client-lines';
  const header = document.createElement('strong'); header.textContent = 'Matériaux et prestations'; table.append(header);
  for (const item of job.quote_items || []) {
    const row = document.createElement('div'); row.className = 'quote-client-line';
    const description = document.createElement('span'); description.textContent = `${item.name} (${item.quantity} × ${Number(item.unit_price).toLocaleString('fr-FR')} FCFA)`;
    const total = document.createElement('b'); total.textContent = `${Number(item.total).toLocaleString('fr-FR')} FCFA`;
    row.append(description, total); table.append(row);
  }
  const costRow = (labelText, amount, total = false) => {
    const row = document.createElement('div'); row.className = `quote-client-line${total ? ' quote-client-total' : ''}`;
    const label = document.createElement(total ? 'strong' : 'span'); label.textContent = labelText;
    const value = document.createElement(total ? 'strong' : 'b'); value.textContent = `${Number(amount || 0).toLocaleString('fr-FR')} FCFA`;
    row.append(label, value); table.append(row);
  };
  costRow('Main-d’œuvre', job.quote_labor);
  costRow('Autres frais', job.quote_extra);
  costRow('TOTAL', job.quote_total, true);
  box.append(table);
  const printButton = document.createElement('button'); printButton.type = 'button'; printButton.className = 'quote-print-button'; printButton.textContent = 'Imprimer / Enregistrer le devis en PDF'; printButton.addEventListener('click', () => printQuote(job)); box.append(printButton);
  if (job.quote_note) addDetail(box, 'Notes et conditions', job.quote_note);
  if (job.client_decision === 'En attente') {
    const prompt = document.createElement('p'); prompt.className = 'quote-decision-prompt'; prompt.textContent = 'Choisis si tu acceptes ou refuses cette proposition.'; box.append(prompt);
    const actions = document.createElement('div'); actions.className = 'quote-decision-actions';
    for (const [label, decision] of [['Accepter le devis', 'Accepter'], ['Refuser le devis', 'Refuser']]) {
      const button = document.createElement('button'); button.type = 'button'; button.textContent = label;
      if (decision === 'Refuser') button.className = 'quote-decline';
      button.addEventListener('click', async () => {
        if (decision === 'Refuser' && !window.confirm('Confirmer le refus de ce devis ?')) return;
        actions.querySelectorAll('button').forEach(item => { item.disabled = true; });
        prompt.textContent = 'Enregistrement de ta réponse…';
        try {
          const response = await fetch('/api/client/quote-decision', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ lead_id: job.request_id, decision }) });
          const result = await response.json(); if (!response.ok) throw new Error(result.error || 'Réponse non enregistrée.');
          await refreshJob();
        } catch (error) { prompt.textContent = error.message; actions.querySelectorAll('button').forEach(item => { item.disabled = false; }); }
      });
      actions.append(button);
    }
    box.append(actions);
  } else {
    const decision = document.createElement('p'); decision.className = 'quote-decision-result';
    decision.textContent = job.client_decision === 'Accepté' ? `Devis accepté${job.quote_accepted_at ? ` le ${formatUtc(job.quote_accepted_at)} (UTC)` : ''}.` : 'Devis refusé. L’entreprise peut t’envoyer une version révisée dans cet espace, ou classer le dossier annulé. Tu peux revenir ici avec ton compte.';
    box.append(decision);
  }
  if (job.client_decision === 'Accepté' && Number(job.quote_deposit || 0) > 0) renderAdvancePayment(job, box);
  card.append(box);
}

function renderAdvancePayment(job, box) {
  const panel = document.createElement('section'); panel.className = 'advance-panel';
  const heading = document.createElement('h4'); heading.textContent = `Avance à régler avant le démarrage : ${formatCfa(job.quote_deposit)}`; panel.append(heading);
  let paymentInfo = {}; try { paymentInfo = typeof job.quote_payment_info === 'string' ? JSON.parse(job.quote_payment_info || '{}') : (job.quote_payment_info || {}); } catch {}
  const details = document.createElement('ul');
  for (const [provider, account] of Object.entries(paymentInfo)) { const li = document.createElement('li'); li.textContent = `${provider} : ${account}`; details.append(li); }
  if (details.children.length) panel.append(details);
  if (job.payment_confirmed_at) {
    const confirmed = document.createElement('p');
    confirmed.textContent = `Paiement de l’avance confirmé par l’entreprise le ${formatUtc(job.payment_confirmed_at)} (UTC).`; panel.append(confirmed);
  } else {
    if (job.payment_reference) { const pending = document.createElement('p'); pending.textContent = `Paiement signalé : ${job.payment_method || 'Mobile money'} · référence ${job.payment_reference}. En attente de vérification par l’entreprise.`; panel.append(pending); }
    const form = document.createElement('form'); form.className = 'client-form advance-form';
    const methodLabel = document.createElement('label'); methodLabel.textContent = 'Moyen utilisé'; const method = document.createElement('select'); method.name = 'method'; method.required = true;
    for (const provider of Object.keys(paymentInfo)) { const option = document.createElement('option'); option.value = provider; option.textContent = provider; method.append(option); }
    methodLabel.append(method);
    const referenceLabel = document.createElement('label'); referenceLabel.textContent = 'Référence de transaction'; const reference = document.createElement('input'); reference.name = 'reference'; reference.maxLength = 120; reference.required = true; reference.placeholder = 'Référence indiquée par l’opérateur'; referenceLabel.append(reference);
    const submit = document.createElement('button'); submit.type = 'submit'; submit.textContent = 'Transmettre le paiement à vérifier'; form.append(methodLabel, referenceLabel, submit);
    const feedbackLine = document.createElement('p'); feedbackLine.className = 'client-feedback'; feedbackLine.setAttribute('role', 'status');
    form.addEventListener('submit', async event => {
      event.preventDefault(); submit.disabled = true; feedbackLine.textContent = 'Transmission…';
      try {
        const response = await fetch('/api/client/advance-payment', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ lead_id: job.request_id, method: method.value, reference: reference.value }) });
        const result = await response.json(); if (!response.ok) throw new Error(result.error || 'Transmission impossible.');
        await refreshJob();
      } catch (error) { feedbackLine.textContent = error.message; submit.disabled = false; }
    });
    panel.append(form, feedbackLine);
  }
  const warning = document.createElement('p'); warning.className = 'client-note'; warning.textContent = 'Ne verse aucun argent vers un compte qui n’apparaît pas ici. Le site ne prélève pas les paiements : contacte l’entreprise en cas de doute.'; panel.append(warning);
  box.append(panel);
}

function renderJobs(jobs) {
  jobsList.replaceChildren();
  for (const job of jobs) {
    const card = document.createElement('article'); card.className = 'job-card';
    const heading = document.createElement('h2'); heading.textContent = `${job.service} — ${job.tracking_ref}`;
    const summary = document.createElement('div'); summary.className = 'job-summary';
    const status = document.createElement('div');
    const label = document.createElement('span'); label.textContent = 'Avancement';
    const value = document.createElement('strong'); value.className = 'job-status'; value.textContent = job.status;
    status.append(label, value); summary.append(status);
    card.append(heading, summary);
    addDetail(card, 'Description du projet', job.message);
    const date = new Date(`${job.created_at.replace(' ', 'T')}Z`);
    addDetail(card, 'Demande envoyée le', Number.isNaN(date.getTime()) ? job.created_at : date.toLocaleString('fr-FR'));
    renderQuote(job, card);
    const photoHeading = document.createElement('h3'); photoHeading.textContent = 'Photos du chantier'; card.append(photoHeading);
    if (job.photos?.length) {
      const gallery = document.createElement('div'); gallery.className = 'job-gallery';
      for (const photo of job.photos) {
        const figure = document.createElement('figure'); figure.className = 'job-photo';
        const image = document.createElement('img'); image.src = photo.url; image.alt = photo.title; image.loading = 'lazy';
        const caption = document.createElement('figcaption');
        const photoDate = new Date(`${photo.created_at.replace(' ', 'T')}Z`);
        caption.textContent = `${photo.title} · ${Number.isNaN(photoDate.getTime()) ? photo.created_at : photoDate.toLocaleDateString('fr-FR')}`;
        figure.append(image, caption); gallery.append(figure);
      }
      card.append(gallery);
    } else {
      const empty = document.createElement('p'); empty.className = 'job-empty'; empty.textContent = 'Aucune photo publiée pour le moment. Les photos apparaîtront ici lorsque l’équipe les ajoutera.'; card.append(empty);
    }
    jobsList.append(card);
  }
  shell.classList.add('authenticated');
}

async function refreshJob() {
  const response = await fetch('/api/client/session', { cache: 'no-store' });
  const session = await response.json();
  if (!response.ok) throw new Error(session.error || 'Impossible d’actualiser le suivi.');
  if (!session.authenticated) { shell.classList.remove('authenticated'); return false; }
  renderJobs(session.jobs || (session.job ? [session.job] : []));
  return true;
}

async function submitAuth(authForm, route, busyText) {
  if (!authForm.reportValidity()) return;
  const button = authForm.querySelector('button'); button.disabled = true; feedback.textContent = busyText;
  try {
    const values = Object.fromEntries(new FormData(authForm).entries());
    const response = await fetch(route, { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(values) });
    const result = await response.json();
    if (!response.ok) throw new Error(result.error || 'Connexion impossible.');
    authForm.reset(); feedback.textContent = '';
    await refreshJob();
  } catch (error) { feedback.textContent = error.message || 'Une erreur est survenue.'; }
  finally { button.disabled = false; }
}

form.addEventListener('submit', event => { event.preventDefault(); submitAuth(form, '/api/client/account/login', 'Connexion…'); });
registerForm.addEventListener('submit', event => { event.preventDefault(); submitAuth(registerForm, '/api/client/account/register', 'Création du compte…'); });
legacyForm.addEventListener('submit', event => { event.preventDefault(); submitAuth(legacyForm, '/api/client/login', 'Vérification des codes…'); });
passwordForm.addEventListener('submit', async event => {
  event.preventDefault(); if (!passwordForm.reportValidity()) return;
  const button = passwordForm.querySelector('button'); button.disabled = true; passwordFeedback.textContent = 'Mise à jour…';
  try {
    const response = await fetch('/api/client/account/password', { method: 'PATCH', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(Object.fromEntries(new FormData(passwordForm).entries())) });
    const result = await response.json(); if (!response.ok) throw new Error(result.error || 'Modification impossible.');
    passwordForm.reset(); passwordFeedback.textContent = 'Mot de passe modifié.';
  } catch (error) { passwordFeedback.textContent = error.message; }
  finally { button.disabled = false; }
});

document.getElementById('client-refresh').addEventListener('click', async () => {
  refreshStatus.textContent = 'Actualisation…';
  try { await refreshJob(); refreshStatus.textContent = 'Suivi actualisé.'; }
  catch (error) { refreshStatus.textContent = error.message; }
});

document.getElementById('client-logout').addEventListener('click', async () => {
  await fetch('/api/client/logout', { method: 'POST' }).catch(() => {});
  shell.classList.remove('authenticated'); feedback.textContent = 'Tu es déconnecté.';
});

resetRequestForm.addEventListener('submit', async event => {
  event.preventDefault(); const button = resetRequestForm.querySelector('button'); button.disabled = true; resetRequestFeedback.textContent = 'Envoi…';
  try {
    const response = await fetch('/api/client/password-reset/request', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ email: resetRequestForm.elements.email.value }) });
    const result = await response.json(); if (!response.ok) throw new Error(result.error || 'Impossible d’envoyer le lien.');
    resetRequestFeedback.textContent = result.message;
  } catch (error) { resetRequestFeedback.textContent = error.message; }
  finally { button.disabled = false; }
});
resetCompleteForm.addEventListener('submit', async event => {
  event.preventDefault(); if (!resetCompleteForm.reportValidity()) return;
  const button = resetCompleteForm.querySelector('button'); button.disabled = true; resetCompleteFeedback.textContent = 'Mise à jour…';
  try {
    const response = await fetch('/api/client/password-reset/complete', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ token: resetToken, password: resetCompleteForm.elements.password.value }) });
    const result = await response.json(); if (!response.ok) throw new Error(result.error || 'Lien invalide ou expiré.');
    resetCompleteFeedback.textContent = `${result.message} Redirection vers la connexion…`;
    window.history.replaceState(null, '', '/client'); window.setTimeout(() => { resetPanel.hidden = true; document.getElementById('client-login').hidden = false; shell.classList.remove('reset-mode'); }, 1400);
  } catch (error) { resetCompleteFeedback.textContent = error.message; }
  finally { button.disabled = false; }
});

refreshJob().catch(() => {});
window.setInterval(() => refreshJob().catch(() => {}), 60000);
