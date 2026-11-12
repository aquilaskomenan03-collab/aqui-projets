'use strict';
const state = document.getElementById('state');
const tableWrap = document.getElementById('table-wrap');
const leadBody = document.getElementById('leads');
const exportButton = document.getElementById('export');
const adminWrap = document.querySelector('.admin-wrap');
const loginPanel = document.getElementById('login-panel');
const loginForm = document.getElementById('login-form');
const loginStatus = document.getElementById('login-status');
let currentRows = [];
let currentView = 'active';
const statusOptions = ['Nouvelle', 'Devis envoyé', 'Devis accepté', 'Devis refusé', 'Contactée', 'En cours', 'Terminée', 'Annulée'];

function showLogin(message = '') {
  adminWrap.classList.add('auth-required');
  loginPanel.hidden = false;
  loginStatus.textContent = message;
}
async function showAdmin() {
  loginPanel.hidden = true;
  adminWrap.classList.remove('auth-required');
  await Promise.all([loadLeads(), loadPhotos(), loadSiteInfo(), loadProgressLeads()]);
}
loginForm.addEventListener('submit', async event => {
  event.preventDefault();
  const submit = loginForm.querySelector('button');
  submit.disabled = true; loginStatus.textContent = 'Connexion…';
  try {
    const response = await fetch('/api/login', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ password: loginForm.elements.password.value }) });
    const result = await response.json();
    if (!response.ok) throw new Error(result.error || 'Connexion impossible.');
    loginForm.reset();
    await showAdmin();
  } catch (error) { loginStatus.textContent = error.message; }
  finally { submit.disabled = false; }
});
document.getElementById('logout').addEventListener('click', async () => {
  await fetch('/api/logout', { method: 'POST' }).catch(() => {});
  showLogin('Tu es déconnecté.');
});

function cell(row, value) {
  const td = document.createElement('td');
  td.textContent = value;
  row.append(td);
  return td;
}
function formatUtc(value) { return value ? new Date(`${value.replace(' ', 'T')}Z`).toLocaleString('fr-FR', { timeZone: 'UTC' }) : '—'; }

function setAdminView(view) {
  currentView = view;
  const archiveView = view === 'archives';
  document.getElementById('panel-title').textContent = archiveView ? 'Archives des demandes terminées' : 'Demandes actives';
  document.getElementById('panel-note').textContent = archiveView
    ? 'Ces demandes restent enregistrées. Tu peux les restaurer dans la liste active.'
    : 'Prépare le devis dans la section dédiée. Le client doit l’accepter avant le démarrage des travaux.';
  document.getElementById('total-label').textContent = archiveView ? 'DEMANDES ARCHIVÉES' : 'DEMANDES ACTIVES';
  document.getElementById('latest-label').textContent = archiveView ? 'DERNIÈRE ARCHIVE' : 'DERNIÈRE DEMANDE';
  document.getElementById('show-active').classList.toggle('view-selected', !archiveView);
  document.getElementById('show-archives').classList.toggle('view-selected', archiveView);
  loadLeads();
}

async function loadLeads() {
  state.hidden = false;
  state.textContent = currentView === 'archives' ? 'Chargement des archives…' : 'Chargement des demandes…';
  tableWrap.hidden = true;
  try {
    const archiveView = currentView === 'archives';
    const response = await fetch(archiveView ? '/api/admin/archives' : '/api/leads');
    if (!response.ok) throw new Error(archiveView ? 'Impossible de charger les archives.' : 'Impossible de charger les demandes. Actualise la page.');
    currentRows = await response.json();
    await loadDashboard();
    document.getElementById('total').textContent = currentRows.length;
    const latestDate = currentRows.length ? (archiveView ? currentRows[0].archived_at : currentRows[0].created_at) : '';
    document.getElementById('latest').textContent = latestDate ? new Date(`${latestDate.replace(' ', 'T')}Z`).toLocaleDateString('fr-FR') : '—';
    exportButton.disabled = !currentRows.length;
    const head = document.getElementById('table-head');
    const columns = archiveView
      ? ['DATE', 'ARCHIVÉE LE', 'NOM & CONTACT', 'SERVICE', 'DESCRIPTION DU PROJET', 'RÉFÉRENCE CLIENT', 'SUIVI', 'ACCÈS CLIENT', 'ACTION']
      : ['DATE', 'NOM & CONTACT', 'SERVICE', 'DESCRIPTION DU PROJET', 'RÉFÉRENCE CLIENT', 'SUIVI', 'ACCÈS CLIENT', 'ACTION'];
    head.replaceChildren(...columns.map(label => { const th = document.createElement('th'); th.textContent = label; return th; }));
    leadBody.replaceChildren();
    if (!currentRows.length) { state.textContent = archiveView ? 'Aucune demande archivée pour le moment.' : 'Aucune demande active pour le moment.'; return; }
    for (const item of currentRows) {
      const row = document.createElement('tr');
      row.style.setProperty('--row-order', String(leadBody.children.length));
      cell(row, new Date(`${item.created_at}Z`).toLocaleString('fr-FR'));
      if (archiveView) cell(row, item.archived_at ? new Date(`${item.archived_at.replace(' ', 'T')}Z`).toLocaleString('fr-FR') : '—');
      cell(row, [item.name, item.phone, item.email || ''].filter(Boolean).join('\n'));
      cell(row, item.service);
      cell(row, item.message);
      cell(row, item.tracking_ref || 'Ancienne demande');
      const statusCell = cell(row, '');
      if (archiveView) {
        cell(row, item.status || 'Terminée');
      } else {
        const select = document.createElement('select');
        select.className = 'lead-status';
        for (const option of statusOptions) {
          const element = document.createElement('option'); element.value = option; element.textContent = option; select.append(element);
        }
        select.value = item.status || 'Nouvelle';
        select.setAttribute('aria-label', `Statut de la demande de ${item.name}`);
        select.addEventListener('change', async () => {
          const previous = item.status || 'Nouvelle';
          select.disabled = true;
          try {
            const result = await fetch(`/api/leads/${item.id}`, { method: 'PATCH', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ status: select.value }) });
            if (!result.ok) throw new Error();
            item.status = select.value;
            updateArchiveButton();
          } catch { select.value = previous; state.hidden = false; state.textContent = 'Le statut n’a pas pu être enregistré. Réessaie.'; }
          finally { select.disabled = false; }
        });
        statusCell.append(select);
      }
      if (!archiveView && item.quote_accepted_at) {
        const acceptedProof = document.createElement('span'); acceptedProof.className = 'payment-review'; acceptedProof.textContent = `Accepté le ${formatUtc(item.quote_accepted_at)} UTC`;
        statusCell.append(acceptedProof);
      }
      if (!archiveView && Number(item.quote_deposit || 0) > 0) {
        const paymentReview = document.createElement('span'); paymentReview.className = 'payment-review';
        if (item.payment_confirmed_at) {
          const paidDate = formatUtc(item.payment_confirmed_at);
          paymentReview.textContent = `Avance confirmée · ${formatCfa(Number(item.quote_deposit))} · ${paidDate}`;
        } else if (item.payment_reference) {
          paymentReview.textContent = `Avance à vérifier · ${item.payment_method || 'Mobile money'} · ${item.payment_reference}`;
          const confirm = document.createElement('button'); confirm.type = 'button'; confirm.className = 'payment-confirm'; confirm.textContent = 'Confirmer la réception';
          confirm.addEventListener('click', async () => {
            if (!window.confirm(`Confirmer que l’avance de ${formatCfa(Number(item.quote_deposit))} a bien été reçue sur le compte ${item.payment_method} ?`)) return;
            confirm.disabled = true;
            try {
              const response = await fetch(`/api/admin/leads/${item.id}/confirm-advance`, { method: 'POST' });
              const result = await response.json(); if (!response.ok) throw new Error(result.error || 'Confirmation impossible.');
              await loadLeads();
            } catch (error) { confirm.disabled = false; state.hidden = false; state.textContent = error.message; }
          });
          statusCell.append(paymentReview, confirm);
        } else paymentReview.textContent = `Avance attendue après acceptation · ${formatCfa(Number(item.quote_deposit))}`;
        if (!item.payment_reference) statusCell.append(paymentReview);
        else if (item.payment_confirmed_at) statusCell.append(paymentReview);
      }
      const accessCell = cell(row, '');
      const resetCode = document.createElement('button');
      resetCode.type = 'button';
      resetCode.className = 'tracking-code-button';
      resetCode.textContent = 'Générer un code';
      resetCode.addEventListener('click', async () => {
        resetCode.disabled = true;
        try {
          const response = await fetch(`/api/admin/leads/${item.id}/access-code`, { method: 'POST' });
          const result = await response.json();
          if (!response.ok) throw new Error(result.error || 'Code indisponible.');
          accessCell.replaceChildren();
          const code = document.createElement('code');
          code.className = 'tracking-code-value';
          code.textContent = result.access_code;
          const copy = document.createElement('button');
          copy.type = 'button'; copy.className = 'tracking-code-button'; copy.textContent = 'Copier';
          copy.addEventListener('click', async () => {
            try { await navigator.clipboard.writeText(result.access_code); copy.textContent = 'Copié'; }
            catch { copy.textContent = 'Sélectionne le code'; }
          });
          accessCell.append(code, copy);
        } catch (error) { state.hidden = false; state.textContent = error.message; }
        finally { resetCode.disabled = false; }
      });
      accessCell.append(resetCode);
      if (item.client_id) {
        const resetPassword = document.createElement('button');
        resetPassword.type = 'button'; resetPassword.className = 'tracking-code-button'; resetPassword.textContent = 'Réinitialiser mot de passe';
        resetPassword.addEventListener('click', async () => {
          resetPassword.disabled = true;
          try {
            const response = await fetch(`/api/admin/leads/${item.id}/account-password`, { method: 'POST' });
            const result = await response.json(); if (!response.ok) throw new Error(result.error || 'Réinitialisation impossible.');
            accessCell.replaceChildren();
            const label = document.createElement('span'); label.textContent = 'Mot de passe temporaire :';
            const password = document.createElement('code'); password.className = 'tracking-code-value'; password.textContent = result.temporary_password;
            accessCell.append(label, password);
          } catch (error) { state.hidden = false; state.textContent = error.message; resetPassword.disabled = false; }
        });
        accessCell.append(resetPassword);
      }
      const actionCell = cell(row, '');
      function updateArchiveButton() {
        actionCell.replaceChildren();
        if (archiveView) {
          const restore = document.createElement('button');
          restore.type = 'button'; restore.className = 'archive-action'; restore.textContent = 'Restaurer';
          restore.addEventListener('click', async () => {
            restore.disabled = true;
            try {
              const result = await fetch(`/api/leads/${item.id}/restore`, { method: 'POST' });
              const body = await result.json();
              if (!result.ok) throw new Error(body.error || 'Restauration impossible.');
              await loadLeads();
            } catch (error) { state.hidden = false; state.textContent = error.message; restore.disabled = false; }
          });
          actionCell.append(restore);
        } else if (item.status === 'Terminée') {
          const archive = document.createElement('button');
          archive.type = 'button'; archive.className = 'archive-action'; archive.textContent = 'Archiver';
          archive.addEventListener('click', async () => {
            archive.disabled = true;
            try {
              const result = await fetch(`/api/leads/${item.id}/archive`, { method: 'POST' });
              const body = await result.json();
              if (!result.ok) throw new Error(body.error || 'Archivage impossible.');
              await loadLeads();
            } catch (error) { state.hidden = false; state.textContent = error.message; archive.disabled = false; }
          });
          actionCell.append(archive);
        }
      }
      updateArchiveButton();
      leadBody.append(row);
    }
    state.hidden = true;
    tableWrap.hidden = false;
  } catch (error) { state.textContent = error.message; }
}

document.getElementById('show-active').addEventListener('click', () => setAdminView('active'));
document.getElementById('show-archives').addEventListener('click', () => setAdminView('archives'));

function csvCell(value) {
  let safe = String(value ?? '').replace(/[\r\n]+/g, ' ');
  if (/^[\s]*[=+@-]/.test(safe)) safe = `'${safe}`;
  return `"${safe.replace(/"/g, '""')}"`;
}
exportButton.addEventListener('click', () => {
  if (!currentRows.length) return;
  const keys = ['created_at', 'archived_at', 'tracking_ref', 'name', 'phone', 'email', 'service', 'status', 'message'];
  const lines = [keys.map(csvCell).join(';'), ...currentRows.map(row => keys.map(key => csvCell(row[key])).join(';'))];
  const blob = new Blob(['\uFEFF' + lines.join('\r\n')], { type: 'text/csv;charset=utf-8' });
  const link = document.createElement('a'); link.href = URL.createObjectURL(blob); link.download = 'demandes-mg-multiservice.csv'; link.click();
  setTimeout(() => URL.revokeObjectURL(link.href), 1000);
});
const infoForm = document.getElementById('info-form');
const infoStatus = document.getElementById('info-status');
async function loadSiteInfo() {
  try {
    const response = await fetch('/api/admin/site-info'); if (!response.ok) throw new Error();
    const info = await response.json();
    for (const [key, value] of Object.entries(info)) if (infoForm.elements[key]) infoForm.elements[key].value = value;
  } catch { infoStatus.textContent = 'Informations indisponibles pour le moment.'; }
}
infoForm.addEventListener('submit', async event => {
  event.preventDefault();
  const submit = infoForm.querySelector('button'); submit.disabled = true; infoStatus.textContent = 'Enregistrement…';
  try {
    const values = Object.fromEntries(new FormData(infoForm).entries());
    const response = await fetch('/api/admin/site-info', { method: 'PATCH', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(values) });
    const result = await response.json(); if (!response.ok) throw new Error(result.error || 'Enregistrement impossible.');
    const publicEmail = result.publicEmail || 'non renseignée';
    const notificationEmail = result.notificationEmail || 'non renseignée';
    infoStatus.textContent = `Informations enregistrées. Expéditeur des e-mails : ${publicEmail}. Les alertes de nouvelles demandes sont destinées à : ${notificationEmail}. Leur réception dépend de la configuration e-mail de l’hébergeur.`;
  } catch (error) { infoStatus.textContent = error.message; }
  finally { submit.disabled = false; }
});

const photoForm = document.getElementById('photo-form');
const photoStatus = document.getElementById('photo-status');
const photoList = document.getElementById('photo-list');
async function loadPhotos() {
  try {
    const response = await fetch('/api/photos'); if (!response.ok) throw new Error();
    const photos = await response.json(); photoList.replaceChildren();
    if (!photos.length) { photoList.textContent = 'Aucune photo publiée pour le moment.'; return; }
    for (const photo of photos) {
      const card = document.createElement('article'); card.className = 'admin-photo';
      const image = document.createElement('img'); image.src = photo.url; image.alt = photo.title;
      const detail = document.createElement('div'); const title = document.createElement('strong'); title.textContent = photo.title;
      const category = document.createElement('span'); category.textContent = photo.category; detail.append(title, category);
      const remove = document.createElement('button'); remove.type = 'button'; remove.textContent = 'Supprimer';
      remove.addEventListener('click', async () => {
        if (!window.confirm(`Supprimer « ${photo.title} » de la galerie ?`)) return;
        remove.disabled = true;
        try { const result = await fetch(`/api/admin/photos/${photo.id}`, { method: 'DELETE' }); if (!result.ok) throw new Error(); await loadPhotos(); }
        catch { remove.disabled = false; photoStatus.textContent = 'La suppression a échoué. Réessaie.'; }
      });
      card.append(image, detail, remove); photoList.append(card);
    }
  } catch { photoList.textContent = 'Impossible de charger les photos.'; }
}
photoForm.addEventListener('submit', async event => {
  event.preventDefault();
  const file = photoForm.elements.image.files[0];
  if (!file) return;
  if (file.size > 30 * 1024 * 1024) { photoStatus.textContent = 'Le fichier original doit faire 30 Mo maximum.'; return; }
  const submit = photoForm.querySelector('button'); submit.disabled = true; photoStatus.textContent = 'Ajout de la photo…';
  try {
    photoStatus.textContent = 'Optimisation et envoi de la photo…';
    const image = await optimizedImageDataUrl(file);
    const response = await fetch('/api/admin/photos', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ category: photoForm.elements.category.value, title: photoForm.elements.title.value, image }) });
    const result = await response.json(); if (!response.ok) throw new Error(result.error || 'Ajout impossible.');
    photoForm.reset(); photoStatus.textContent = 'Photo ajoutée à la galerie du site.'; await loadPhotos();
  } catch (error) { photoStatus.textContent = error.message || 'La photo n’a pas pu être envoyée.'; }
  finally { submit.disabled = false; }
});

const progressPhotoForm = document.getElementById('progress-photo-form');
const progressPhotoStatus = document.getElementById('progress-photo-status');
const progressLeadSelect = document.getElementById('progress-lead-select');
const quoteForm = document.getElementById('quote-form-admin');
const quoteLeadSelect = document.getElementById('quote-lead-select');
const quoteItemsWrap = document.getElementById('quote-items');
const quoteStatus = document.getElementById('quote-admin-status');
function addQuoteLine(item = {}) {
  const line = document.createElement('div'); line.className = 'quote-line';
  const makeField = (labelText, className, type, value, step) => {
    const label = document.createElement('label'); label.textContent = labelText;
    const input = document.createElement('input'); input.className = className; input.type = type;
    if (type === 'number') { input.min = type === 'number' && className === 'quote-quantity' ? '0.01' : '0'; input.step = step || '1'; }
    input.value = value ?? ''; label.append(input); return label;
  };
  line.append(
    makeField('Matériau / prestation', 'quote-item-name', 'text', item.name || '', ''),
    makeField('Quantité', 'quote-quantity', 'number', item.quantity ?? 1, '0.01'),
    makeField('Prix unitaire (FCFA)', 'quote-unit-price', 'number', item.unit_price ?? 0, '1')
  );
  const remove = document.createElement('button'); remove.type = 'button'; remove.className = 'quote-line-remove'; remove.textContent = 'Retirer';
  remove.addEventListener('click', () => { line.remove(); updateQuoteTotal(); });
  line.append(remove); line.querySelectorAll('input').forEach(input => input.addEventListener('input', updateQuoteTotal)); quoteItemsWrap.append(line);
}
function formatCfa(amount) { return `${Math.round(amount).toLocaleString('fr-FR')} FCFA`; }
async function loadDashboard() {
  const list = document.getElementById('reminder-list');
  try {
    const response = await fetch('/api/admin/dashboard');
    if (!response.ok) throw new Error();
    const { stats, pending_quotes: pendingQuotes } = await response.json();
    document.getElementById('projects-in-progress').textContent = stats.projects_in_progress ?? '0';
    document.getElementById('accepted-quotes-month').textContent = formatCfa(stats.accepted_quote_month);
    document.getElementById('advances-collected-month').textContent = formatCfa(stats.advances_collected_month);
    document.getElementById('acceptance-rate').textContent = stats.acceptance_rate === null ? '—' : `${stats.acceptance_rate} %`;
    list.replaceChildren();
    if (!pendingQuotes.length) { list.textContent = 'Aucun devis à relancer pour le moment.'; return; }
    for (const item of pendingQuotes) {
      const row = document.createElement('article'); row.className = 'reminder-item';
      const detail = document.createElement('p');
      const strong = document.createElement('strong'); strong.textContent = `${item.tracking_ref || 'Sans référence'} — ${item.name}`;
      const sent = new Date(`${item.quote_sent_at.replace(' ', 'T')}Z`);
      const days = Math.max(7, Math.floor((Date.now() - sent.getTime()) / 86400000));
      const meta = document.createElement('span'); meta.textContent = `\n${item.phone} · devis envoyé le ${formatUtc(item.quote_sent_at)} UTC · ${days} jours sans réponse`;
      detail.append(strong, meta); row.append(detail);
      const actions = document.createElement('div'); actions.className = 'reminder-actions';
      let phone = String(item.phone || '').replace(/\D/g, '');
      if (phone.startsWith('00')) phone = phone.slice(2);
      if (phone.startsWith('0')) phone = `225${phone.slice(1)}`;
      else if (phone.length === 10) phone = `225${phone}`;
      if (phone) {
        const whatsapp = document.createElement('a'); whatsapp.href = `https://wa.me/${phone}?text=${encodeURIComponent(`Bonjour ${item.name}, nous revenons vers vous au sujet du devis ${item.tracking_ref || ''}. Avez-vous pu le consulter ?`)}`; whatsapp.target = '_blank'; whatsapp.rel = 'noopener noreferrer'; whatsapp.textContent = 'Relancer sur WhatsApp'; actions.append(whatsapp);
      }
      if (item.email) { const email = document.createElement('a'); email.href = `mailto:${encodeURIComponent(item.email)}?subject=${encodeURIComponent(`Votre devis ${item.tracking_ref || ''}`)}`; email.textContent = 'Préparer un e-mail'; actions.append(email); }
      row.append(actions); list.append(row);
    }
  } catch { list.textContent = 'Les indicateurs ne sont pas disponibles. Actualise la page.'; }
}
async function optimizedImageDataUrl(file) {
  const bitmap = await createImageBitmap(file);
  const scale = Math.min(1, 1920 / bitmap.width, 1920 / bitmap.height);
  if (scale === 1 && file.size <= 250 * 1024) {
    bitmap.close();
    return new Promise((resolve, reject) => { const reader = new FileReader(); reader.onload = () => resolve(reader.result); reader.onerror = reject; reader.readAsDataURL(file); });
  }
  const canvas = document.createElement('canvas');
  canvas.width = Math.max(1, Math.round(bitmap.width * scale));
  canvas.height = Math.max(1, Math.round(bitmap.height * scale));
  const context = canvas.getContext('2d', { alpha: false });
  if (!context) { bitmap.close(); throw new Error('La compression d’image n’est pas disponible dans ce navigateur.'); }
  context.fillStyle = '#ffffff'; context.fillRect(0, 0, canvas.width, canvas.height); context.drawImage(bitmap, 0, 0, canvas.width, canvas.height); bitmap.close();
  const preferredType = 'image/webp';
  let blob = await new Promise(resolve => canvas.toBlob(resolve, preferredType, 0.82));
  if (!blob || blob.type !== preferredType) blob = await new Promise(resolve => canvas.toBlob(resolve, 'image/jpeg', 0.82));
  if (blob && blob.size > 4 * 1024 * 1024) blob = await new Promise(resolve => canvas.toBlob(resolve, blob.type, 0.65));
  if (!blob || blob.size > 5 * 1024 * 1024) throw new Error('La photo reste trop volumineuse après compression. Choisis une autre image.');
  return new Promise((resolve, reject) => { const reader = new FileReader(); reader.onload = () => resolve(reader.result); reader.onerror = reject; reader.readAsDataURL(blob); });
}
function updateQuoteTotal() {
  let total = Number(quoteForm.elements.labor.value || 0) + Number(quoteForm.elements.extra.value || 0);
  quoteItemsWrap.querySelectorAll('.quote-line').forEach(line => { total += Number(line.querySelector('.quote-quantity').value || 0) * Number(line.querySelector('.quote-unit-price').value || 0); });
  document.getElementById('quote-total').textContent = formatCfa(total);
}
function fillQuoteForm(item) {
  quoteItemsWrap.replaceChildren();
  let items = [];
  try { items = typeof item?.quote_items === 'string' ? JSON.parse(item.quote_items || '[]') : (item?.quote_items || []); } catch {}
  if (items.length) items.forEach(value => addQuoteLine(value)); else addQuoteLine();
  quoteForm.elements.labor.value = item?.quote_labor || 0;
  quoteForm.elements.extra.value = item?.quote_extra || 0;
  quoteForm.elements.note.value = item?.quote_note || '';
  quoteForm.elements.deposit.value = item?.quote_deposit || 0;
  let paymentInfo = {};
  try { paymentInfo = typeof item?.quote_payment_info === 'string' ? JSON.parse(item.quote_payment_info || '{}') : (item?.quote_payment_info || {}); } catch {}
  quoteForm.elements.pay_wave.value = paymentInfo['Wave'] || '';
  quoteForm.elements.pay_orange.value = paymentInfo['Orange Money'] || '';
  quoteForm.elements.pay_mtn.value = paymentInfo['MTN MoMo'] || '';
  quoteForm.elements.pay_moov.value = paymentInfo['Moov Money'] || '';
  const accepted = item?.quote_accepted_at ? ` · accepté le ${formatUtc(item.quote_accepted_at)} UTC` : '';
  const deposit = Number(item?.quote_deposit || 0) > 0 ? ` · avance ${formatCfa(Number(item.quote_deposit))}${item.payment_confirmed_at ? ' reçue' : item.payment_reference ? ' déclarée par le client, à vérifier' : ' attendue'}` : '';
  quoteStatus.textContent = item?.quote_sent_at ? `Devis envoyé : ${formatCfa(Number(item.quote_total || 0))} · réponse client : ${item.client_decision || 'En attente'}${accepted}${deposit}. Un devis refusé peut être révisé puis renvoyé; le statut Annulée classe un dossier sans suite.` : '';
  document.getElementById('send-quote-button').textContent = item?.client_decision === 'Refusé' ? 'Envoyer le devis révisé' : 'Envoyer le devis au client';
  updateQuoteTotal();
}
quoteForm.elements.labor.addEventListener('input', updateQuoteTotal);
quoteForm.elements.extra.addEventListener('input', updateQuoteTotal);
quoteLeadSelect.addEventListener('change', () => {
  const selected = quoteLeadSelect.selectedOptions[0];
  fillQuoteForm(selected?.dataset.lead ? JSON.parse(selected.dataset.lead) : null);
});
document.getElementById('add-quote-item').addEventListener('click', () => addQuoteLine());
addQuoteLine();
async function loadProgressLeads() {
  try {
    const response = await fetch('/api/admin/lead-options'); if (!response.ok) throw new Error();
    const leads = await response.json(); progressLeadSelect.replaceChildren(); quoteLeadSelect.replaceChildren();
    const empty = document.createElement('option'); empty.value = ''; empty.textContent = leads.length ? 'Choisir une demande' : 'Aucune demande disponible'; progressLeadSelect.append(empty);
    const quoteEmpty = new Option(leads.length ? 'Choisir une demande' : 'Aucune demande disponible', ''); quoteLeadSelect.append(quoteEmpty);
    for (const item of leads) {
      const option = document.createElement('option'); option.value = item.id;
      option.textContent = `${item.tracking_ref || 'Sans référence'} — ${item.name} · ${item.service}`; progressLeadSelect.append(option);
      const quoteOption = new Option(`${item.tracking_ref || 'Sans référence'} — ${item.name} · ${item.service} · ${item.status}`, item.id);
      quoteOption.dataset.lead = JSON.stringify(item); quoteLeadSelect.append(quoteOption);
    }
  } catch { progressLeadSelect.replaceChildren(new Option('Connexion admin requise', '')); }
}
quoteForm.addEventListener('submit', async event => {
  event.preventDefault(); if (!quoteForm.reportValidity()) return;
  const items = [...quoteItemsWrap.querySelectorAll('.quote-line')].map(line => ({ name: line.querySelector('.quote-item-name').value, quantity: line.querySelector('.quote-quantity').value, unit_price: line.querySelector('.quote-unit-price').value })).filter(item => item.name.trim());
  const body = { items, labor: quoteForm.elements.labor.value, extra: quoteForm.elements.extra.value, note: quoteForm.elements.note.value, deposit: quoteForm.elements.deposit.value, payment_info: { 'Wave': quoteForm.elements.pay_wave.value, 'Orange Money': quoteForm.elements.pay_orange.value, 'MTN MoMo': quoteForm.elements.pay_mtn.value, 'Moov Money': quoteForm.elements.pay_moov.value } };
  const button = quoteForm.querySelector('button[type="submit"]'); button.disabled = true; quoteStatus.textContent = 'Envoi du devis…';
  try {
    const response = await fetch(`/api/admin/leads/${quoteLeadSelect.value}/quote`, { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(body) });
    const result = await response.json(); if (!response.ok) throw new Error(result.error || 'Le devis n’a pas pu être envoyé.');
    quoteStatus.textContent = `Devis envoyé au client. Total : ${formatCfa(Number(result.total))}${Number(result.deposit) > 0 ? ` · avance prévue ${formatCfa(Number(result.deposit))}` : ''}. Sa réponse apparaîtra dans le statut de la demande.`;
    await Promise.all([loadLeads(), loadProgressLeads()]);
  } catch (error) { quoteStatus.textContent = error.message; }
  finally { button.disabled = false; }
});
progressPhotoForm.addEventListener('submit', async event => {
  event.preventDefault();
  const file = progressPhotoForm.elements.image.files[0]; if (!file) return;
  if (file.size > 30 * 1024 * 1024) { progressPhotoStatus.textContent = 'Le fichier original doit faire 30 Mo maximum.'; return; }
  const submit = progressPhotoForm.querySelector('button'); submit.disabled = true; progressPhotoStatus.textContent = 'Envoi de la photo…';
  try {
    progressPhotoStatus.textContent = 'Optimisation et envoi de la photo…';
    const image = await optimizedImageDataUrl(file);
    const values = { lead_id: progressPhotoForm.elements.lead_id.value, title: progressPhotoForm.elements.title.value, image };
    const response = await fetch('/api/admin/progress-photos', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(values) });
    const result = await response.json(); if (!response.ok) throw new Error(result.error || 'Envoi impossible.');
    progressPhotoForm.elements.title.value = ''; progressPhotoForm.elements.image.value = '';
    progressPhotoStatus.textContent = 'Photo ajoutée au suivi privé de ce chantier. Le client la verra dans son espace.';
  } catch (error) { progressPhotoStatus.textContent = error.message || 'La photo n’a pas pu être envoyée.'; }
  finally { submit.disabled = false; }
});

document.getElementById('refresh').addEventListener('click', () => { loadLeads(); loadPhotos(); loadProgressLeads(); });
fetch('/api/session').then(response => response.json()).then(session => session.authenticated ? showAdmin() : showLogin()).catch(() => showLogin('Serveur indisponible. Réessaie dans un instant.'));
