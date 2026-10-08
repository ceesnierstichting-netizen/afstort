'use strict';
(() => {
  const $ = selector => document.querySelector(selector);
  const user = { id: document.body.dataset.userId, name: document.body.dataset.userName, office: document.body.dataset.office === '1', admin: document.body.dataset.admin === '1', reportAll: document.body.dataset.reportAll === '1' };
  const csrf = $('meta[name="csrf-token"]').content;
  const viewControl = $('#view')?.closest('label');
  const footerNote = $('#footer-note');
  if (viewControl && footerNote && !viewControl.closest('.footer-view')) {
    const footerView = document.createElement('div');
    footerView.className = 'footer-view';
    footerNote.insertAdjacentElement('afterend', footerView);
    footerView.append(viewControl);
  }
  const labels = { available: 'Beschikbaar', planning: 'Nog te plannen', planned: 'Gepland', done: 'Afgerond' };
  const filterLabels = { available: 'Chauffeur kiezen', planning: 'Afspraak maken', planned: 'Afronden', done: 'Afgerond' };
  const titles = { available: 'Beschikbaar; Chauffeur kiezen', planning: 'Nog te plannen; Afspraak maken', planned: 'Ritten afronden', done: 'Afgeronde ritten' };
  const hints = { available: 'Kies een rit die je wilt oppakken. Je naam wordt automatisch gekoppeld.', planning: 'Neem contact op en leg de afgesproken datum en tijd vast.', planned: 'Hier vind je je afspraken. Vul na de rit de kilometers, het gestorte bedrag aan munten en de bonnen in.', done: 'Deze ritten zijn afgerond. Bekijk de ritgegevens en de verzendstatus.' };
  let state = { trips: [], mails: [] }, filter = 'available', view = user.office ? 'office' : 'driver';
  let busy = false, dirty = false, lastFocus;
  let loadSequence = 0, previewSequence = 0, previewTimer;
  let collectionYear = '2026', currentYear;
  const escape = value => String(value ?? '').replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
  const euros = value => value === '' ? '—' : new Intl.NumberFormat('nl-NL', { style: 'currency', currency: 'EUR' }).format(Number(value));
  const date = value => value ? new Date(value + 'T12:00:00').toLocaleDateString('nl-NL', { day: 'numeric', month: 'long', year: 'numeric' }) : 'In overleg';
  const today = () => { const d = new Date(); return `${d.getFullYear()}-${String(d.getMonth()+1).padStart(2,'0')}-${String(d.getDate()).padStart(2,'0')}`; };
  const ownTrips = () => state.trips.filter(t => String(t.collectejaar || 2026) === collectionYear && (view === 'office' || t.status === 'available' || t.chauffeurId === user.id));
  const receiptLink = (trip, receipt) => `index2.php?action=receipt&id=${encodeURIComponent(trip.id)}&receipt=${encodeURIComponent(receipt.id)}`;
  const apiUrl = action => `index2.php?action=${action}`;

  function notice(message, error = false) {
    const el = $('#notice'); el.textContent = message; el.className = error ? 'error' : ''; el.hidden = false;
  }
  async function request(url, options = {}) {
    let response;
    try {
      response = await fetch(url, { credentials: 'same-origin', cache: 'no-store', ...options });
    } catch (cause) {
      const error = new Error('Geen verbinding met de server. Controleer je verbinding en probeer opnieuw.');
      error.networkError = true;
      throw error;
    }
    const text = await response.text();
    let result;
    try { result = JSON.parse(text); } catch { throw new Error(response.status === 403 ? 'Je sessie is gewijzigd. Vernieuw de pagina en probeer opnieuw.' : 'Geen geldig antwoord ontvangen. Log zo nodig opnieuw in en open index2.php.'); }
    if (!response.ok) throw new Error(result.error || result.message || 'Het verzoek is mislukt.');
    return result;
  }
  async function load() {
    if (busy || dirty || $('#editor').open) return;
    const sequence = ++loadSequence;
    try { const result = await request(apiUrl('state')); if (sequence === loadSequence && !busy && !dirty && !$('#editor').open) { state = result; render(); } }
    catch (error) { if (sequence === loadSequence && !error.networkError) notice(error.message, true); }
  }
  function render() {
    const years = state.preferences?.years || {'2026': {kilometervergoeding:'0.30'}};
    if (currentYear !== state.preferences?.currentYear || !years[collectionYear]) collectionYear = String(state.preferences?.currentYear || 2026);
    currentYear = state.preferences?.currentYear;
    $('#collection-year').disabled = !user.admin;
    if ($('#preferences')) $('#preferences').hidden = !user.admin;
    $('#collection-year').innerHTML = Object.keys(years).map(year => `<option value="${year}" ${year === collectionYear ? 'selected' : ''}>${year}</option>`).join('');
    $('#page-title').textContent = view === 'office' ? 'Rittenoverzicht' : 'Mijn ritten';
    if ($('#create')) $('#create').hidden = view !== 'office';
    if ($('#create')) $('#create').disabled = !state.meta?.importedAt;
    $('#footer-note').textContent = 'Het rittenoverzicht vernieuwt automatisch iedere 30 seconden wanneer je geen formulier open hebt. Klik op een gekleurde knop om direct de nieuwste ritten te bekijken.';
    const trips = ownTrips();
    $('#filters').innerHTML = Object.entries(filterLabels).map(([key, label]) => `<button class="filter" data-filter="${key}" aria-pressed="${key === filter}">${label}<span>${trips.filter(t => t.status === key).length}</span></button>`).join('');
    $('#list-title').textContent = titles[filter]; $('#list-hint').textContent = filter === 'available' && view === 'office' ? 'Kies een rit en wijs deze toe aan een chauffeur.' : hints[filter];
    if ($('#mail-count')) $('#mail-count').textContent = state.mails.filter(m => trips.some(t => t.id === m.tripId)).length;
    const filtered = trips.filter(t => t.status === filter).sort((a,b) => (a.afhaalmoment || '9999').localeCompare(b.afhaalmoment || '9999'));
    $('#trips').innerHTML = filtered.length ? filtered.map(t => {
      const action = { available: view === 'office' ? 'Chauffeur kiezen' : 'Ik pak deze rit op', planning: 'Afspraak maken', planned: 'Rit afronden', done: 'Bekijk rit' }[t.status];
      return `<article class="trip-card"><div class="card-top"><span class="pill ${t.status}">${labels[t.status]}</span><span class="trip-id">${escape(t.gebiedsnummer || 'Rit')}</span></div><h3>${escape(t.collectegebied)}</h3><p class="contact">${escape(t.contactpersoon)} · ${escape(t.postcodePlaats)}</p><dl><div><dt>${t.status === 'available' ? 'Voorkeur afhaaldag' : 'Afspraak'}</dt><dd>${escape(date(t.status === 'available' ? t.voorkeurAfhaalmoment : t.afhaalmoment))}${t.afhaaltijd ? '<br>' + escape(t.afhaaltijd) : ''}</dd></div><div><dt>${t.status === 'done' ? 'Gestort bedrag aan munten' : 'Verwacht bedrag'}</dt><dd>${euros(t.status === 'done' ? t.gestort : t.verwachtBedrag)}${t.status === 'done' ? adjustmentLabel(t) : ''}</dd></div><div><dt>Soort opbrengst</dt><dd>${escape(t.soort)}</dd></div><div><dt>${t.status === 'available' && t.aangebodenChauffeur ? 'Uitgezet bij' : 'Chauffeur'}</dt><dd>${escape(t.chauffeur || t.aangebodenChauffeur || 'Nog niet gekoppeld')}${t.status === 'available' && t.aangebodenChauffeur ? '<br><small>Wacht op reactie</small>' : ''}</dd></div></dl>${['available', 'planning'].includes(t.status) && t.contactOpmerking ? `<p class="mail-preview"><strong>Opmerking voor chauffeur:</strong><br>${escape(t.contactOpmerking)}</p>` : ''}${t.status === 'planned' ? contactDetails(t) : ''}${t.status === 'planned' && t.afhaalmoment < today() ? '<div class="overdue">Afspraakdatum voorbij · nog afronden</div>' : ''}${t.mailError ? `<p class="mail-error">${escape(t.mailError)}</p><button class="secondary" data-retry-mail="${escape(t.id)}">Verzending controleren / opnieuw proberen</button>` : ''}<button class="${t.status === 'done' ? 'secondary' : 'primary'} card-action" ${t.mailError ? 'disabled' : ''} data-trip="${escape(t.id)}">${action}</button>${t.status === 'available' ? (view === 'office' ? '<p class="helper">Kies wie deze rit gaat uitvoeren.</p>' : '<p class="helper">Je naam wordt automatisch gekoppeld.</p>') : ''}</article>`;
    }).join('') : '<div class="empty"><h3>Hier staan nog geen ritten</h3><p>Kies een ander onderdeel of maak op kantoor een rit aan.</p></div>';
  }
  function open(title, step, content) {
    clearTimeout(previewTimer); previewSequence++;
    lastFocus = document.activeElement; dirty = false;
    $('#dialog-title').textContent = title; $('#dialog-step').textContent = step;
    $('#dialog-content').innerHTML = `<div class="dialog-body">${content}</div>`;
    if (!$('#editor').open) $('#editor').showModal();
    $('#editor').scrollTop = 0;
  }
  function close(force = false) {
    if (busy) return;
    if (!force && dirty && !window.confirm('Je hebt wijzigingen die nog niet zijn bewaard. Toch sluiten?')) return;
    dirty = false; $('#editor').close();
    if (lastFocus?.isConnected) lastFocus.focus(); else $(`[data-filter="${filter}"]`)?.focus();
  }
  function field(label, name, value = '', type = 'text', required = true, extra = '') {
    if (type === 'time') {
      const quarters = Array.from({length:96}, (_,i) => `${String(Math.floor(i/4)).padStart(2,'0')}:${String((i%4)*15).padStart(2,'0')}`);
      const existing = value && !quarters.includes(value) ? `<option value="${escape(value)}" selected>${escape(value)} (bestaande afspraak)</option>` : '';
      return `<label class="field">${label}<select name="${name}" ${required ? 'required' : ''}><option value="">Kies een tijd</option>${existing}${quarters.map(time => `<option value="${time}" ${value === time ? 'selected' : ''}>${time}</option>`).join('')}</select><small>Keuze per kwartier${existing ? '; de bestaande tijd blijft behouden' : ''}.</small></label>`;
    }
    if (type === 'date') {
      return `<label class="field">${label}<span class="calendar-control"><span class="calendar-value" aria-hidden="true">${value ? escape(date(value)) : 'Kies een datum'} <span>▦</span></span><input name="${name}" type="date" value="${escape(value)}" ${required ? 'required' : ''} inputmode="none" data-calendar-only ${extra}></span><small>Kies de datum via de kalender.</small></label>`;
    }
    return `<label class="field">${label}<input name="${name}" type="${type}" value="${escape(value)}" ${required ? 'required' : ''} ${extra}></label>`;
  }
  function contactDetails(t) {
    const phone = String(t.telefoonnummer || '').trim();
    const dial = phone.replace(/[^+0-9]/g, '');
    const email = String(t.email || '').trim();
    const address = [t.adres, t.postcodePlaats].filter(Boolean).join(', ');
    const icon = kind => {
      const paths = {
        phone: '<path d="M22 16.9v3a2 2 0 0 1-2.2 2 19.8 19.8 0 0 1-8.6-3.1 19.5 19.5 0 0 1-6-6A19.8 19.8 0 0 1 2.1 4.2 2 2 0 0 1 4.1 2h3a2 2 0 0 1 2 1.7c.1 1 .4 2 .7 2.9a2 2 0 0 1-.5 2.1L8 10a16 16 0 0 0 6 6l1.3-1.3a2 2 0 0 1 2.1-.5c.9.3 1.9.6 2.9.7a2 2 0 0 1 1.7 2Z"/>',
        email: '<rect x="3" y="5" width="18" height="14" rx="2"/><path d="m3 6 9 7 9-7"/>',
        address: '<path d="M20 10c0 6-8 12-8 12S4 16 4 10a8 8 0 1 1 16 0Z"/><circle cx="12" cy="10" r="3"/>'
      };
      return `<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">${paths[kind]}</svg>`;
    };

    return `<div class="trip-contact">${phone ? `<a href="tel:${escape(dial)}" aria-label="${escape('Bel ' + t.contactpersoon + ': ' + phone)}">${icon('phone')}<span>${escape(phone)}</span></a>` : ''}${email ? `<a href="mailto:${escape(encodeURIComponent(email))}" aria-label="${escape('Mail ' + t.contactpersoon + ': ' + email)}">${icon('email')}<span>${escape(email)}</span></a>` : ''}${address ? `<a href="https://www.google.com/maps/search/?api=1&amp;query=${escape(encodeURIComponent(address))}" target="_blank" rel="noopener noreferrer" aria-label="${escape('Open adres op de kaart: ' + address)}">${icon('address')}<span>${escape(address)}</span></a>` : ''}</div>`;
  }
  function summary(t) {
    return `<div class="summary"><h3>${escape(t.collectegebied)}</h3><p>${escape(t.contactpersoon)} · ${escape(t.adres)}, ${escape(t.postcodePlaats)}</p><p>${escape(t.soort)} · verwacht ${euros(t.verwachtBedrag)}</p>${t.chauffeur ? `<p>Chauffeur: <strong>${escape(t.chauffeur)}</strong></p>` : ''}${t.contactOpmerking ? `<p class="mail-preview">Opmerking voor chauffeur: ${escape(t.contactOpmerking)}</p>` : ''}${t.wijknaam ? `<p>Wijk: ${escape(t.wijknaam)}</p>` : ''}</div>`;
  }
  function hidden(t) { return `<input type="hidden" name="id" value="${escape(t.id)}"><input type="hidden" name="revision" value="${t.revision}">`; }
  const errorBox = '<p class="form-error" role="alert" hidden></p>';
  const simulation = '';
  function create() {
    const requestKey = Array.from(crypto.getRandomValues(new Uint8Array(16)), b => b.toString(16).padStart(2, '0')).join('');
    open('Nieuwe rit', 'STAP 1 · KANTOOR', `<p class="muted">De aanvraag wordt opgeslagen in het huidige portaal. De bevestiging wordt echt verstuurd.</p><form data-kind="create"><input type="hidden" name="requestKey" value="${requestKey}">${errorBox}<div class="form-grid">${field('Collectegebied', 'collectegebied')}${field('Gebiedsnummer <small>(optioneel)</small>', 'gebiedsnummer', '', 'text', false)}${field('Wijknaam <small>(optioneel)</small>', 'wijknaam', '', 'text', false)}${field('Contactpersoon', 'contactpersoon')}${field('E-mailadres contactpersoon', 'email', '', 'email')}<label class="field wide">Opmerking voor chauffeur <small>(optioneel)</small><textarea name="contactOpmerking" maxlength="2000"></textarea></label>${field('Telefoonnummer', 'telefoonnummer', '', 'tel')}${field('Adres en huisnummer', 'adres')}${field('Postcode en plaats', 'postcodePlaats', '', 'text', true, 'placeholder="1234 AB Voorbeeldstad"')}${field('Voorkeur afhaaldag <small>(optioneel)</small>', 'voorkeurAfhaalmoment', '', 'date', false)}${field('Verwacht totaalbedrag (€)', 'verwachtBedrag', '', 'number', true, 'min="0" max="1000000" step="0.01" inputmode="decimal"')}<label class="field wide">Soort opbrengst<select name="soort"><option>Munten</option><option>Biljetten</option><option>Munten en biljetten</option></select></label></div><div class="form-actions"><button type="button" data-close>Annuleren</button><button class="primary" name="action" value="create">Rit aanmaken en mail versturen</button></div></form>`);
  }
  function expectationFields(t) {
    if (!user.office) return '';
    return field('Verwacht totaalbedrag (€)', 'verwachtBedrag', t.verwachtBedrag, 'number', true, 'min="0" max="1000000" step="0.01" inputmode="decimal"')
      + field('Voorkeur afhaaldag <small>(optioneel)</small>', 'voorkeurAfhaalmoment', t.voorkeurAfhaalmoment, 'date', false);
  }
  function editTrip(t, schedule = false) {
    if (t.status === 'available') return view === 'office' ? assign(t) : claim(t);
    if (t.status === 'done') return showDone(t);
    if (t.status === 'planning' || (schedule && user.office && t.status === 'planned')) {
      open('Afspraak maken', 'STAP 3 · CHAUFFEUR', `${summary(t)}<p class="muted">Voorkeur afhaaldag: ${escape(date(t.voorkeurAfhaalmoment))}</p><p class="call-link">☎ ${escape(t.telefoonnummer)} <small>· Neem contact op om een afspraak te maken</small></p><form data-kind="schedule">${hidden(t)}${errorBox}${t.mailError ? `<p class="form-error">${escape(t.mailError)}</p>` : ''}<div class="form-grid">${expectationFields(t)}${field('Afhaaldatum', 'afhaalmoment', t.afhaalmoment, 'date')}${field('Tijd', 'afhaaltijd', t.afhaaltijd, 'time')}</div><details><summary>Bekijk de klaargezette e-mail</summary><div class="mail-preview" id="preview"></div></details>${simulation}<p class="helper">De mail gaat naar ${escape(t.email)} en ${escape(t.chauffeurEmail || 'de chauffeur')}. Deze berichten worden echt verstuurd.</p><div class="form-actions"><button name="action" value="save_schedule" formnovalidate>Bewaren en later verder</button><button class="primary" name="action" value="confirm">Afspraak bevestigen en mails versturen</button></div></form>`);
    } else {
      open('Rit afronden', 'STAP 4 · CHAUFFEUR', `${summary(t)}${contactDetails(t)}${user.office ? `<button type="button" data-edit-schedule="${escape(t.id)}">Afspraak aanpassen</button>` : ''}<p class="muted">Afspraak: ${escape(date(t.afhaalmoment))} om ${escape(t.afhaaltijd)}</p><form data-kind="finish">${hidden(t)}${errorBox}${t.mailError ? `<p class="form-error">${escape(t.mailError)}</p>` : ''}<div class="form-grid">${field('Gereden kilometers <small>(verplicht)</small>', 'gereden', t.gereden, 'number', true, 'min="0" max="10000" step="1" inputmode="numeric"')}${field('Werkelijk gestort bedrag aan MUNTEN (€) <small>(verplicht)</small>', 'gestort', t.gestort, 'number', true, 'min="0" max="1000000" step="0.01" inputmode="decimal"')}<label class="field wide">Interne opmerking <small>(optioneel, uitsluitend voor intern gebruik)</small><textarea name="interneOpmerking" maxlength="2000">${escape(t.interneOpmerking || '')}</textarea></label><p class="helper wide">Vul alleen het bedrag aan munten in dat voor deze rit daadwerkelijk is gestort. Het bedrag in de sealbag is niet bekend bij de chauffeur en telt hier niet mee. Zijn er geen munten gestort? Vul dan 0 in. Het verwachte bedrag wordt niet automatisch overgenomen.</p>${receiptUpload()}</div>${receiptRows(t, true)}<label class="field">Opmerking <small>(optioneel, komt in de afrondingsmail)</small><textarea name="opmerking" maxlength="2000">${escape(t.opmerking)}</textarea></label><details><summary>Bekijk de klaargezette e-mail</summary><div class="mail-preview" id="preview"></div></details>${simulation}<p class="helper">De rit wordt pas afgerond als de mailserver de afrondingsmail heeft geaccepteerd.</p><div class="form-actions"><button name="action" value="save_finish" formnovalidate>Bewaren en later verder</button><button class="primary" name="action" value="finish">Afronden en mail versturen</button></div></form>`);
    }
    updatePreview();
  }
  async function prepareReceiptPhoto(file) {
    if (['image/jpeg', 'image/png', 'image/webp'].includes(file.type) && file.size <= 2 * 1024 * 1024) return file;
    let bitmap;
    try { bitmap = await createImageBitmap(file); }
    catch { throw new Error('Deze foto kan niet worden gelezen. Kies een JPG-, PNG- of WebP-foto.'); }
    try {
      const canvas = document.createElement('canvas');
      let scale = Math.min(1, 2000 / Math.max(bitmap.width, bitmap.height));
      for (let attempt = 0; attempt < 4; attempt++) {
        canvas.width = Math.max(1, Math.round(bitmap.width * scale));
        canvas.height = Math.max(1, Math.round(bitmap.height * scale));
        const ctx = canvas.getContext('2d');
        ctx.fillStyle = '#fff'; ctx.fillRect(0, 0, canvas.width, canvas.height);
        ctx.drawImage(bitmap, 0, 0, canvas.width, canvas.height);
        const blob = await new Promise(resolve => canvas.toBlob(resolve, 'image/jpeg', 0.85));
        if (blob && blob.size <= 2 * 1024 * 1024) return new File([blob], file.name.replace(/\.[^.]+$/, '') + '.jpg', {type:'image/jpeg'});
        scale *= 0.75;
      }
      throw new Error('De foto is te groot. Kies een kleinere foto.');
    } finally { bitmap.close(); }
  }
  function renderPendingReceipts(form) {
    form.querySelector('[data-pending-receipts]').innerHTML = (form.receiptFiles || []).map((file, index) =>
      `<div class="receipt-row"><span>${escape(file.name)}</span><button type="button" data-remove-pending-receipt="${index}">Verwijderen</button></div>`).join('');
  }
  function remainingStoredReceipts(form) {
    const trip = state.trips.find(t => t.id === form.elements.id.value);
    return (trip?.receipts.length || 0) - form.querySelectorAll('[name="removeReceipts[]"]:checked').length;
  }
  async function addReceiptPhotos(input) {
    const files = [...input.files], form = input.closest('form');
    input.value = '';
    if (!files.length || busy) return;
    const error = form.querySelector('.form-error[role=alert]');
    error.hidden = true;
    if (remainingStoredReceipts(form) + (form.receiptFiles || []).length + files.length > 3) {
      error.textContent = 'Voeg maximaal drie bonfoto’s toe. Verwijder eerst een foto om een andere toe te voegen.';
      error.hidden = false; return;
    }
    busy = true;
    form.querySelectorAll('button').forEach(button => button.disabled = true);
    try {
      const prepared = [];
      for (const file of files) prepared.push(await prepareReceiptPhoto(file));
      form.receiptFiles = [...(form.receiptFiles || []), ...prepared];
      dirty = true; renderPendingReceipts(form);
    } catch (e) { error.textContent = e.message; error.hidden = false; }
    finally { busy = false; form.querySelectorAll('button').forEach(button => button.disabled = false); }
  }
  function receiptUpload() {
    return `<div class="field wide upload"><span>＋ Foto’s van bonnen toevoegen</span><div class="receipt-actions"><button type="button" data-receipt-picker="camera">Foto maken</button><button type="button" data-receipt-picker="library">Foto’s kiezen</button></div><input type="file" data-receipt-input="camera" accept="image/*" capture="environment" hidden><input type="file" data-receipt-input="library" accept="image/jpeg,image/png,image/webp" multiple hidden><small>Maak één foto per keer of kies meerdere foto’s. Je kunt daarna meer toevoegen, tot maximaal 3 bonfoto’s. Grote foto’s worden automatisch verkleind tot maximaal 2 MB.</small><div data-pending-receipts class="receipt-list" aria-live="polite"></div></div>`;
  }
  function receiptRows(t, removable = false) {
    return `<div class="receipt-list">${t.receipts.map(r => `<div class="receipt-row"><a href="${receiptLink(t,r)}" target="_blank" rel="noopener">${escape(r.name)}</a>${removable ? `<label><input type="checkbox" name="removeReceipts[]" value="${escape(r.id)}"> Verwijderen</label>` : ''}</div>`).join('')}</div>`;
  }
  function assign(t) {
    const drivers = (state.drivers || []).filter(d => (d.availableYears || []).includes(Number(t.collectejaar)));
    open('Chauffeur kiezen', 'STAP 2 · KANTOOR', `${summary(t)}<form data-kind="assign">${hidden(t)}${errorBox}<div class="form-grid">${expectationFields(t)}${field('Verwachte afhaaldatum <small>(optioneel)</small>', 'afhaalmoment', t.afhaalmoment, 'date', false)}${field('Verwachte tijd <small>(optioneel)</small>', 'afhaaltijd', t.afhaaltijd, 'time', false)}</div><label class="field">Chauffeur<select name="chauffeurId" required><option value="">Kies een chauffeur</option>${drivers.map(d => `<option value="${escape(d.id)}">${escape(d.name)}</option>`).join('')}</select></label><p class="helper">De lijst bevat de kiesbare chauffeurs uit het huidige portaal.</p><p class="helper">Na toewijzen staat de rit bij ‘Nog te plannen’. Er wordt bij deze stap geen mail verstuurd.</p>${drivers.length ? '' : '<p class="form-error">Er zijn geen kiesbare chauffeurs. Vernieuw de gegevens en probeer opnieuw.</p>'}<div class="form-actions"><button type="button" data-close>Annuleren</button><button class="primary" name="action" value="assign" ${drivers.length ? '' : 'disabled'}>Rit toewijzen</button></div></form>`);
  }
  function updatePreview() {
    const form = $('#editor form'), el = $('#preview'); if (!form || !el) return;
    const t = state.trips.find(t => t.id === form.elements.id.value); if (!t) return;
    {
      clearTimeout(previewTimer);
      const sequence = ++previewSequence;
      el.textContent = 'Bestaande mailsjablonen invullen…';
      previewTimer = setTimeout(async () => {
        const data = new FormData();
        data.set('previewKind', form.dataset.kind);
        for (const key of ['id','verwachtBedrag','afhaalmoment','afhaaltijd','gestort','gereden','opmerking']) if (form.elements[key]) data.set(key, form.elements[key].value);
        try {
          const result = await request(apiUrl('preview'), {method:'POST', headers:{'X-CSRF-Token':csrf}, body:data});
          if (sequence !== previewSequence || !el.isConnected) return;
          el.textContent = result.mails.map(m => `Aan: ${m.to}${m.cc ? '\nCC: ' + m.cc : ''}${m.bcc ? '\nBCC: ' + m.bcc : ''}\nOnderwerp: ${m.subject}\n\n${m.body}`).join('\n\n────────────────────\n\n');
        } catch (error) { if (sequence === previewSequence && el.isConnected) el.textContent = error.networkError ? '' : error.message; }
      }, 250);
      return;
    }
  }
  function adjustmentLabel(t) {
    return t.bedragAangepastDoor ? ` <small class="amount-adjustment">Aangepast door ${escape(t.bedragAangepastDoor)}</small>` : '';
  }
  function showDone(t) {
    const latestMail = state.mails.filter(m => m.tripId === t.id && m.status === 'sent')
      .sort((a, b) => String(a.at).localeCompare(String(b.at))).pop();
    const mailSection = latestMail ? `<h3>Laatst verstuurde mail</h3>${mailItems([latestMail])}` : '';
    const correction = user.office ? `<form data-kind="correct_done">${hidden(t)}${errorBox}<div class="form-grid">${field('Gereden kilometers', 'gereden', t.gereden, 'number', true, 'min="0" max="10000" step="1" inputmode="numeric"')}${field('Gestort bedrag aan munten (€)', 'gestort', t.gestort, 'number', true, 'min="0" max="1000000" step="0.01" inputmode="decimal"')}${receiptUpload()}</div>${receiptRows(t, true)}<p class="helper">Nieuwe bonbijlagen worden bij de rit opgeslagen.</p><div class="form-actions"><button class="primary" name="action" value="correct_done">Aanpassing opslaan</button></div></form>` : '';
    open('Afgeronde rit', 'ALLE STAPPEN VOLTOOID', `${summary(t)}<div class="done-summary"><div><span>Gereden kilometers</span><strong>${escape(t.gereden)}</strong></div><div><span>Gestort bedrag aan munten</span><strong>${euros(t.gestort)}</strong>${adjustmentLabel(t)}</div></div>${user.office ? '' : receiptRows(t)}${t.opmerking ? `<p class="mail-preview">${escape(t.opmerking)}</p>` : ''}${t.interneOpmerking ? `<div class="mail-preview"><strong>Interne opmerking</strong><br>${escape(t.interneOpmerking)}</div>` : ''}${mailSection}${correction}<div class="form-actions"><button type="button" data-close>Sluiten</button></div>`);
  }
  function mailItems(mails) {
    return mails.length ? [...mails].reverse().map(m => `<article class="mail-item"><h3>${escape(m.subject)}</h3><div class="mail-meta">${escape(new Date(m.at).toLocaleString('nl-NL'))} · ${escape(({sent:'Door mailserver geaccepteerd',failed:'Verzending mislukt',pending:'Klaar voor verzending',sending:'Verzendstatus nog niet bevestigd',cancelled:'Geannuleerd na ritwijziging'})[m.status] || m.status)}${m.templateId ? ' · bestaand sjabloon ' + m.templateId : ''}<br>Aan: ${escape(m.to)}${m.copy ? '<br>Kopie: ' + escape(m.copy) : ''}${m.cc ? '<br>CC: ' + escape(m.cc) : ''}${m.bcc ? '<br>BCC: ' + escape(m.bcc) : ''}</div><details><summary>Bekijk bericht${m.attachments.length ? ` · ${m.attachments.length} bijlage(n)` : ''}</summary><div class="mail-preview">${escape(m.body)}${m.attachments.length ? '\n\nBijlagen: ' + escape(m.attachments.join(', ')) : ''}</div></details></article>`).join('') : '<p class="muted">Er zijn nog geen mails.</p>';
  }
  async function mutate(formData) {
    return request(apiUrl('mutate'), { method: 'POST', headers: { 'X-CSRF-Token': csrf }, body: formData });
  }
  function preferences() {
    $('#users-management')?.showModal();
  }
  function reportMenu() {
    open('Rapporten', `COLLECTEJAAR ${collectionYear}`, `<p>Kies het rapport dat je wilt bekijken.</p><div class="form-actions report-options"><button type="button" class="primary" data-collection-report>Collecterapport ${escape(collectionYear)}</button>${user.office ? `<a class="primary" href="chauffeursRapport.php?year=${encodeURIComponent(collectionYear)}" target="_blank" rel="noopener">Chauffeursrapport ${escape(collectionYear)}</a>` : ''}<a class="primary" href="emailRapport.php?return=index2.php" target="_blank" rel="noopener">Emailoverzicht</a></div><p class="muted">Het collecterapport toont afgeronde ritten, kilometers en vergoedingen.${user.office ? ' Het chauffeursrapport toont de gegevens van de actieve chauffeurs.' : ''}</p><div class="form-actions"><button type="button" data-close>Sluiten</button></div>`);
  }
  function report() {
    const allDrivers = user.admin || user.reportAll;
    const trips = state.trips.filter(t => String(t.collectejaar || 2026) === collectionYear && t.status === 'done' && (allDrivers || t.chauffeurId === user.id)).sort((a,b) => (a.chauffeur || '').localeCompare(b.chauffeur || '') || a.afhaalmoment.localeCompare(b.afhaalmoment));
    const rateCents = Math.round(Number(state.preferences?.years?.[collectionYear]?.kilometervergoeding ?? '0.30') * 100);
    const declaration = t => Math.round(Number(t.gereden || 0) * rateCents);
    const groups = new Map();
    trips.forEach(t => { const key = t.chauffeurId || t.chauffeur; if (!groups.has(key)) groups.set(key, []); groups.get(key).push(t); });
    const tables = [...groups.values()].map(rows => {
      const kilometers = rows.reduce((sum,t) => sum + Number(t.gereden || 0), 0);
      const amount = rows.reduce((sum,t) => sum + Math.round(Number(t.gestort || 0) * 100), 0);
      const compensation = rows.reduce((sum,t) => sum + declaration(t), 0);
      return `<h3>${escape(rows[0].chauffeur || 'Onbekende chauffeur')}</h3>${rows[0].chauffeurIban ? `<p class="muted">IBAN: ${escape(rows[0].chauffeurIban)}</p>` : ''}<div class="report-table"><table><thead><tr><th>Collectegebied</th><th>Afhaaldatum</th><th>Soort</th><th>Gestort aan munten</th><th>Kilometers</th><th>Declarabel</th></tr></thead><tbody>${rows.map(t => `<tr><td>${escape(t.collectegebied)}</td><td>${escape(date(t.afhaalmoment))}</td><td>${escape(t.soort)}</td><td>${euros(t.gestort)}${adjustmentLabel(t)}</td><td>${escape(t.gereden)}</td><td>${euros(declaration(t) / 100)}</td></tr>`).join('')}</tbody><tfoot><tr><th colspan="3">Totaal</th><td>${euros(amount / 100)}</td><td>${kilometers.toLocaleString('nl-NL', {maximumFractionDigits:2})}</td><td>${euros(compensation / 100)}</td></tr></tfoot></table></div>`;
    }).join('');
    open(`Rapport collecte ${collectionYear}`, 'AFGERONDE RITTEN', `<p class="muted">Kilometervergoeding: ${euros(rateCents / 100)} per km. ${allDrivers ? 'Alle afgeronde ritten, gegroepeerd per chauffeur.' : 'Alleen jouw afgeronde ritten.'}</p>${tables || '<p>Geen afgeronde ritten in dit collectejaar.</p>'}<div class="form-actions"><button type="button" data-report-menu>Terug naar rapporten</button><button type="button" data-print>Afdrukken / PDF</button><button type="button" data-close>Sluiten</button></div>`);
  }
  async function claim(t) {
    if (busy) return; busy = true;
    document.querySelectorAll('[data-trip]').forEach(b => b.disabled = true);
    try {
      const data = new FormData();
        data.set('previewKind', form.dataset.kind); data.set('testView',view); data.set('action','claim'); data.set('id',t.id); data.set('revision',t.revision);
      const result = await mutate(data); state = result; filter = 'planning'; render(); notice(result.message); editTrip(state.trips.find(x => x.id === t.id));
    } catch(error) { notice(error.message, true); await load(); }
    finally { busy = false; document.querySelectorAll('[data-trip]').forEach(b => b.disabled = false); }
  }
  $('#filters').addEventListener('click', async event => {
    const button = event.target.closest('[data-filter]');
    if (!button) return;
    filter = button.dataset.filter;
    render();
    $(`[data-filter="${filter}"]`).focus();
    await load();
    if (!$('#editor').open && document.activeElement === document.body) $(`[data-filter="${filter}"]`)?.focus();
  });
  $('#trips').addEventListener('click', async event => {
    const button = event.target.closest('[data-retry-mail]'); if (!button || busy) return;
    const t = state.trips.find(t => t.id === button.dataset.retryMail); if (!t) return;
    busy = true; button.disabled = true;
    try { const data = new FormData();
        data.set('previewKind', form.dataset.kind); data.set('action','retry_mail'); data.set('id',t.id); data.set('revision',t.revision); const result = await mutate(data); state = result; render(); notice(result.message, !!state.trips.find(x => x.id === t.id)?.mailError); }
    catch (error) { notice(error.message, true); } finally { busy = false; button.disabled = false; }
  });
  $('#trips').addEventListener('click', event => { const button = event.target.closest('[data-trip]'); if (button) editTrip(state.trips.find(t => t.id === button.dataset.trip)); });
  $('#collection-year').addEventListener('change', event => { if (user.admin) collectionYear = event.target.value; render(); });
  $('#report').addEventListener('click', reportMenu);
  $('#preferences')?.addEventListener('click', preferences);
  window.addEventListener('portal-settings-changed', load);
  $('#create')?.addEventListener('click', create);
  $('#view')?.addEventListener('change', event => { view = event.target.value; render(); });
  $('#close-dialog').addEventListener('click', () => close());
  $('#editor').addEventListener('cancel', event => { event.preventDefault(); close(); });
  $('#dialog-content').addEventListener('click', event => { if (event.target.closest('[data-close]')) close(); });

  $('#dialog-content').addEventListener('click', event => {
    if (event.target.closest('[data-collection-report]')) report();
    if (event.target.closest('[data-report-menu]')) reportMenu();
    if (event.target.closest('[data-print]')) window.print();
  });
  $('#dialog-content').addEventListener('click', event => {
    const scheduleButton = event.target.closest('[data-edit-schedule]');
    if (scheduleButton && !busy) editTrip(state.trips.find(t => t.id === scheduleButton.dataset.editSchedule), true);
    const picker = event.target.closest('[data-receipt-picker]');
    if (picker && !busy) picker.closest('form').querySelector(`[data-receipt-input="${picker.dataset.receiptPicker}"]`).click();
    const remove = event.target.closest('[data-remove-pending-receipt]');
    if (remove && !busy) {
      const form = remove.closest('form');
      form.receiptFiles.splice(Number(remove.dataset.removePendingReceipt), 1);
      dirty = true; renderPendingReceipts(form);
    }
    const input = event.target.closest('[data-calendar-only]');
    if (input && typeof input.showPicker === 'function') input.showPicker();
  });
  $('#dialog-content').addEventListener('keydown', event => {
    if (!event.target.matches('[data-calendar-only]') || ['Tab', 'Escape'].includes(event.key)) return;
    event.preventDefault();
    if (['Enter', ' '].includes(event.key) && typeof event.target.showPicker === 'function') event.target.showPicker();
  });
  ['beforeinput', 'paste', 'drop'].forEach(type => {
    $('#dialog-content').addEventListener(type, event => {
      if (event.target.matches('[data-calendar-only]')) event.preventDefault();
    });
  });
  $('#dialog-content').addEventListener('input', () => { dirty = true; updatePreview(); });
  $('#dialog-content').addEventListener('change', event => {
    if (event.target.matches('[data-receipt-input]')) { addReceiptPhotos(event.target); return; }

    if (event.target.matches('[data-calendar-only]')) {
      event.target.previousElementSibling.innerHTML = `${event.target.value ? escape(date(event.target.value)) : 'Kies een datum'} <span>▦</span>`;
    }
    dirty = true; updatePreview();
  });
  window.addEventListener('beforeunload', event => { if (dirty || busy) { event.preventDefault(); event.returnValue = ''; } });
  $('#dialog-content').addEventListener('submit', async event => {
    event.preventDefault(); if (busy) return;
    const form = event.target, action = event.submitter?.value || ({ create: 'create', assign: 'assign', schedule: 'save_schedule', finish: 'save_finish', correct_done: 'correct_done' }[form.dataset.kind]);
    if (action === 'finish' && !form.reportValidity()) return;
    const error = form.querySelector('.form-error[role=alert]'); error.hidden = true;
    const data = new FormData(form); data.set('action', action);
    if (action === 'create') data.set('collectejaar', collectionYear);
    if (action === 'correct_done') {
      if (!form.reportValidity()) return;
      const original = state.trips.find(t => t.id === data.get('id'));
      for (const [key, confirmation, question] of [
        ['gereden', 'confirmKilometers', value => `Weet je zeker dat het aantal kilometers ${value} klopt?`],
        ['gestort', 'confirmAmount', value => `Weet je zeker dat het bedrag ${euros(value)} klopt?`]
      ]) {
        const value = Number(String(data.get(key)).replace(',', '.'));
        if (value !== Number(original[key] || 0)) {
          if (!window.confirm(question(value))) return;
          data.set(confirmation, value.toFixed(2));
        }
      }
    }
    if (['finish', 'correct_done'].includes(form.dataset.kind)) {
      const files = form.receiptFiles || [];
      if (remainingStoredReceipts(form) + files.length > 3 || files.some(f => f.size > 2 * 1024 * 1024)) { error.textContent = 'Kies maximaal drie foto’s van elk maximaal 2 MB.'; error.hidden = false; return; }
      for (const file of files) data.append('receipts[]', file, file.name);
    }
    busy = true; form.querySelectorAll('button').forEach(b => b.disabled = true);
    try {
      const result = await mutate(data); state = result;

      const t = state.trips.find(t => t.id === data.get('id'));
      filter = action === 'create' ? 'available' : (t?.status || filter);
      render(); notice(result.message, !!t?.mailError); dirty = false; busy = false;
      if (t?.mailError && ['confirm','finish'].includes(action)) editTrip(t); else close(true);
    } catch (e) {
      error.textContent = e.message; error.hidden = false; error.scrollIntoView({ block: 'nearest' });
    } finally { busy = false; form.querySelectorAll('button').forEach(b => b.disabled = false); }
  });
  const refreshIfIdle = () => { if (!document.hidden && !busy && !dirty && !$('#editor').open) load(); };
  setInterval(refreshIfIdle, 30000);
  document.addEventListener('visibilitychange', refreshIfIdle);
  window.addEventListener('focus', refreshIfIdle);
  load();
})();
