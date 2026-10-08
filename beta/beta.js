'use strict';
(() => {
  const $ = selector => document.querySelector(selector);
  const user = { id: document.body.dataset.userId, name: document.body.dataset.userName, office: document.body.dataset.office === '1', admin: document.body.dataset.admin === '1', reportAll: document.body.dataset.reportAll === '1' };
  const csrf = $('meta[name="csrf-token"]').content;
  const labels = { available: 'Beschikbaar', planning: 'Nog te plannen', planned: 'Gepland', done: 'Afgerond' };
  const titles = { available: 'Beschikbare ritten', planning: 'Nog te plannen', planned: 'Geplande ritten', done: 'Afgeronde ritten' };
  const hints = { available: 'Kies een rit die je wilt oppakken. Je naam wordt automatisch gekoppeld.', planning: 'Neem contact op en leg de afgesproken datum en tijd vast.', planned: 'Hier vind je je afspraken. Vul na de rit de kilometers, het gestorte bedrag aan munten en de bonnen in.', done: 'Deze testritten zijn afgerond. De gesimuleerde mails staan in de testmailbox.' };
  let state = { trips: [], mails: [] }, filter = 'available', view = user.office ? 'office' : 'driver';
  let busy = false, dirty = false, lastFocus;
  let dataset = 'practice', loadSequence = 0, previewSequence = 0, previewTimer;
  let collectionYear = '2026';
  const escape = value => String(value ?? '').replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
  const euros = value => value === '' ? '—' : new Intl.NumberFormat('nl-NL', { style: 'currency', currency: 'EUR' }).format(Number(value));
  const date = value => value ? new Date(value + 'T12:00:00').toLocaleDateString('nl-NL', { day: 'numeric', month: 'long', year: 'numeric' }) : 'In overleg';
  const today = () => { const d = new Date(); return `${d.getFullYear()}-${String(d.getMonth()+1).padStart(2,'0')}-${String(d.getDate()).padStart(2,'0')}`; };
  const ownTrips = () => state.trips.filter(t => String(t.collectejaar || 2026) === collectionYear && (view === 'office' || t.status === 'available' || t.chauffeurId === user.id));
  const receiptLink = (trip, receipt) => `beta.php?action=receipt&dataset=${dataset}&id=${encodeURIComponent(trip.id)}&receipt=${encodeURIComponent(receipt.id)}`;
  const apiUrl = action => `beta.php?action=${action}&dataset=${dataset}`;

  function notice(message, error = false) {
    const el = $('#notice'); el.textContent = message; el.className = error ? 'error' : ''; el.hidden = false;
  }
  async function request(url, options = {}) {
    const response = await fetch(url, { credentials: 'same-origin', ...options });
    const text = await response.text();
    let result;
    try { result = JSON.parse(text); } catch { throw new Error(response.status === 403 ? 'Je sessie is gewijzigd. Vernieuw de pagina en probeer opnieuw.' : 'Geen geldig antwoord ontvangen. Log zo nodig opnieuw in en open beta.php.'); }
    if (!response.ok) throw new Error(result.error || result.message || 'Het verzoek is mislukt.');
    return result;
  }
  async function load() {
    const sequence = ++loadSequence;
    $('#refresh').disabled = true;
    try { const result = await request(apiUrl('state')); if (sequence === loadSequence) { state = result; render(); } }
    catch (error) { if (sequence === loadSequence) notice(error.message, true); }
    finally { if (sequence === loadSequence) $('#refresh').disabled = false; }
  }
  function render() {
    const years = state.preferences?.years || {'2026': {kilometervergoeding:'0.30'}};
    if (!user.admin || !years[collectionYear]) collectionYear = '2026';
    $('#collection-year').disabled = !user.admin;
    if ($('#preferences')) $('#preferences').hidden = !user.admin;
    $('#collection-year').innerHTML = Object.keys(years).map(year => `<option value="${year}" ${year === collectionYear ? 'selected' : ''}>${year}</option>`).join('');
    if ($('#import')) $('#import').disabled = collectionYear !== '2026';
    $('#page-title').textContent = view === 'office' ? 'Rittenoverzicht' : 'Mijn ritten';
    if ($('#create')) $('#create').hidden = view !== 'office';
    if ($('#create')) $('#create').disabled = dataset === 'snapshot' && !state.meta?.importedAt;
    if ($('#import')) $('#import').hidden = dataset !== 'snapshot';
    if ($('#source-status')) $('#source-status').textContent = dataset === 'practice' ? 'Gedeelde oefenritten met voorbeeldmails.' : state.meta?.importedAt ? `Persoonlijke testkopie van ${new Date(state.meta.importedAt).toLocaleString('nl-NL')}. Inclusief de bestaande mailsjablonen.` : 'Nog geen testkopie geladen. De echte ritten worden uitsluitend gelezen.';
    $('#footer-note').textContent = dataset === 'snapshot' ? 'Deze persoonlijke testkopie bevat echte gegevens en is alleen toegankelijk voor jouw kantooraccount. Alle wijzigingen en testmails blijven in de bèta. Documentlinks in mails zijn niet actief.' : 'Gebruik fictieve gegevens en testbonnen. Oefenritten zijn gedeeld met andere testers en blijven na uitloggen bewaard.';
    const trips = ownTrips();
    $('#filters').innerHTML = Object.entries(labels).map(([key, label]) => `<button class="filter" data-filter="${key}" aria-pressed="${key === filter}">${label}<span>${trips.filter(t => t.status === key).length}</span></button>`).join('');
    $('#list-title').textContent = titles[filter]; $('#list-hint').textContent = filter === 'available' && view === 'office' ? 'Kies een rit en wijs deze toe aan een chauffeur.' : hints[filter];
    $('#mail-count').textContent = state.mails.filter(m => trips.some(t => t.id === m.tripId)).length;
    const filtered = trips.filter(t => t.status === filter).sort((a,b) => (a.afhaalmoment || '9999').localeCompare(b.afhaalmoment || '9999'));
    $('#trips').innerHTML = filtered.length ? filtered.map(t => {
      const action = { available: view === 'office' ? 'Chauffeur toewijzen' : 'Ik pak deze rit op', planning: 'Afspraak vastleggen', planned: 'Rit afronden', done: 'Bekijk rit' }[t.status];
      return `<article class="trip-card"><div class="card-top"><span class="pill ${t.status}">${labels[t.status]}</span><span class="trip-id">${escape(t.gebiedsnummer || 'Testrit')}</span></div><h3>${escape(t.collectegebied)}</h3><p class="contact">${escape(t.contactpersoon)} · ${escape(t.postcodePlaats)}</p><dl><div><dt>${t.status === 'available' ? 'Voorkeur afhaaldag' : 'Afspraak'}</dt><dd>${escape(date(t.status === 'available' ? t.voorkeurAfhaalmoment : t.afhaalmoment))}${t.afhaaltijd ? '<br>' + escape(t.afhaaltijd) : ''}</dd></div><div><dt>${t.status === 'done' ? 'Gestort bedrag aan munten' : 'Verwacht bedrag'}</dt><dd>${euros(t.status === 'done' ? t.gestort : t.verwachtBedrag)}</dd></div><div><dt>Soort opbrengst</dt><dd>${escape(t.soort)}</dd></div><div><dt>Chauffeur</dt><dd>${escape(t.chauffeur || 'Nog niet gekoppeld')}</dd></div></dl>${['available', 'planning'].includes(t.status) && t.contactOpmerking ? `<p class="mail-preview"><strong>Opmerking voor chauffeur:</strong><br>${escape(t.contactOpmerking)}</p>` : ''}${t.status === 'planned' ? contactDetails(t) : ''}${t.status === 'planned' && t.afhaalmoment < today() ? '<div class="overdue">Afspraakdatum voorbij · nog afronden</div>' : ''}${t.mailError ? '<p class="mail-error">Mail nog niet gelukt. Open de rit om opnieuw te proberen.</p>' : ''}<button class="${t.status === 'done' ? 'secondary' : 'primary'} card-action" data-trip="${escape(t.id)}">${action}</button>${t.status === 'available' ? (view === 'office' ? '<p class="helper">Kies wie deze rit gaat uitvoeren.</p>' : '<p class="helper">Je naam wordt automatisch gekoppeld.</p>') : ''}</article>`;
    }).join('') : '<div class="empty"><h3>Hier staan nog geen ritten</h3><p>Kies een ander onderdeel of maak op kantoor een testrit aan.</p></div>';
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
    if (lastFocus?.isConnected) lastFocus.focus(); else $('#refresh').focus();
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
  const simulation = '<details class="test-options"><summary>Testopties</summary><label><input type="checkbox" name="simulateFailure" value="1">Simuleer dat mailen mislukt. De rit blijft open en de invoer wordt bewaard.</label></details>';
  function create() {
    open('Nieuwe testrit', 'STAP 1 · KANTOOR', `<p class="muted">Gebruik fictieve gegevens. De bevestiging komt uitsluitend in de testmailbox.</p><form data-kind="create">${errorBox}<div class="form-grid">${field('Collectegebied', 'collectegebied')}${field('Gebiedsnummer <small>(optioneel)</small>', 'gebiedsnummer', '', 'text', false)}${field('Wijknaam <small>(optioneel)</small>', 'wijknaam', '', 'text', false)}${field('Contactpersoon', 'contactpersoon')}${field('E-mailadres contactpersoon', 'email', '', 'email')}<label class="field wide">Opmerking voor chauffeur <small>(optioneel, beschikbaar als [opmerking] in mails)</small><textarea name="contactOpmerking" maxlength="2000"></textarea></label>${field('Telefoonnummer', 'telefoonnummer', '', 'tel')}${field('Adres en huisnummer', 'adres')}${field('Postcode en plaats', 'postcodePlaats', '', 'text', true, 'placeholder="1234 AB Voorbeeldstad"')}${field('Voorkeur afhaaldag <small>(optioneel)</small>', 'voorkeurAfhaalmoment', '', 'date', false)}${field('Verwacht totaalbedrag (€)', 'verwachtBedrag', '', 'number', true, 'min="0" max="1000000" step="0.01" inputmode="decimal"')}<label class="field wide">Soort opbrengst<select name="soort"><option>Munten</option><option>Biljetten</option><option>Munten en biljetten</option></select></label></div><div class="form-actions"><button type="button" data-close>Annuleren</button><button class="primary" name="action" value="create">Rit aanmaken en testmail versturen</button></div></form>`);
  }
  function editTrip(t) {
    if (t.status === 'available') return view === 'office' ? assign(t) : claim(t);
    if (t.status === 'done') return showDone(t);
    if (t.status === 'planning') {
      open('Afspraak vastleggen', 'STAP 3 · CHAUFFEUR', `${summary(t)}<p class="muted">Voorkeur afhaaldag: ${escape(date(t.voorkeurAfhaalmoment))}</p><p class="call-link">☎ ${escape(t.telefoonnummer)} <small>· ${dataset === 'snapshot' ? 'alleen bekijken in deze test' : 'fictief testnummer'}</small></p><form data-kind="schedule">${hidden(t)}${errorBox}${t.mailError ? `<p class="form-error">${escape(t.mailError)}</p>` : ''}<div class="form-grid">${field('Afhaaldatum', 'afhaalmoment', t.afhaalmoment, 'date')}${field('Tijd', 'afhaaltijd', t.afhaaltijd, 'time')}</div><details><summary>Bekijk de klaargezette e-mail</summary><div class="mail-preview" id="preview"></div></details>${simulation}<p class="helper">De testmail gaat naar ${escape(t.email)} en ${escape(t.chauffeurEmail || 'de chauffeur')}. Er wordt niets echt verzonden.</p><div class="form-actions"><button name="action" value="save_schedule" formnovalidate>Bewaren en later verder</button><button class="primary" name="action" value="confirm">Afspraak bevestigen en testmails versturen</button></div></form>`);
    } else {
      open('Rit afronden', 'STAP 4 · CHAUFFEUR', `${summary(t)}${contactDetails(t)}<p class="muted">Afspraak: ${escape(date(t.afhaalmoment))} om ${escape(t.afhaaltijd)}</p><form data-kind="finish">${hidden(t)}${errorBox}${t.mailError ? `<p class="form-error">${escape(t.mailError)}</p>` : ''}<div class="form-grid">${field('Gereden kilometers', 'gereden', t.gereden, 'number', true, 'min="0" max="10000" step="0.01" inputmode="decimal"')}${field('Werkelijk gestort bedrag aan MUNTEN (€)', 'gestort', t.gestort, 'number', true, 'min="0" max="1000000" step="0.01" inputmode="decimal"')}<p class="helper wide">Vul alleen het bedrag aan munten in dat voor deze rit daadwerkelijk is gestort. Het bedrag in de sealbag is niet bekend bij de chauffeur en telt hier niet mee. Zijn er geen munten gestort? Vul dan 0 in. Het verwachte bedrag wordt niet automatisch overgenomen.</p><label class="field wide upload">＋ Foto’s van testbonnen toevoegen<input type="file" name="receipts[]" accept="image/jpeg,image/png,image/webp" multiple><small>Maximaal 3 foto’s van elk 2 MB. Alleen JPG, PNG of WebP. Gebruik fictieve bonnen.</small></label></div>${receiptRows(t, true)}<label class="field">Opmerking <small>(optioneel, komt in de testmail)</small><textarea name="opmerking" maxlength="2000">${escape(t.opmerking)}</textarea></label><details><summary>Bekijk de klaargezette e-mail</summary><div class="mail-preview" id="preview"></div></details>${simulation}<p class="helper">De rit wordt pas afgerond als de gesimuleerde verzending slaagt.</p><div class="form-actions"><button name="action" value="save_finish" formnovalidate>Bewaren en later verder</button><button class="primary" name="action" value="finish">Afronden en testmail versturen</button></div></form>`);
    }
    updatePreview();
  }
  function receiptRows(t, removable = false) {
    return `<div class="receipt-list">${t.receipts.map(r => `<div class="receipt-row"><a href="${receiptLink(t,r)}" target="_blank" rel="noopener">${escape(r.name)}</a>${removable ? `<label><input type="checkbox" name="removeReceipts[]" value="${escape(r.id)}"> Verwijderen</label>` : ''}</div>`).join('')}</div>`;
  }
  function assign(t) {
    const drivers = state.drivers || [];
    open('Chauffeur toewijzen', 'STAP 2 · KANTOOR', `${summary(t)}<form data-kind="assign">${hidden(t)}${errorBox}<label class="field">Chauffeur<select name="chauffeurId" required><option value="">Kies een chauffeur</option>${drivers.map(d => `<option value="${escape(d.id)}">${escape(d.name)}</option>`).join('')}</select></label><p class="helper">${dataset === 'snapshot' ? 'De lijst bevat de kiesbare chauffeurs uit het huidige portaal. Alleen deze testkopie wordt gewijzigd.' : 'Deze oefenomgeving gebruikt fictieve testchauffeurs.'}</p><p class="helper">Na toewijzen staat de rit bij ‘Nog te plannen’. Er wordt bij deze stap geen mail verstuurd.</p>${drivers.length ? '' : '<p class="form-error">Er zijn geen kiesbare chauffeurs. Vernieuw de gegevens en probeer opnieuw.</p>'}<div class="form-actions"><button type="button" data-close>Annuleren</button><button class="primary" name="action" value="assign" ${drivers.length ? '' : 'disabled'}>Rit toewijzen</button></div></form>`);
  }
  function updatePreview() {
    const form = $('#editor form'), el = $('#preview'); if (!form || !el) return;
    const t = state.trips.find(t => t.id === form.elements.id.value); if (!t) return;
    if (dataset === 'snapshot') {
      clearTimeout(previewTimer);
      const sequence = ++previewSequence;
      el.textContent = 'Bestaande mailsjablonen invullen…';
      previewTimer = setTimeout(async () => {
        const data = new FormData();
        for (const key of ['id','afhaalmoment','afhaaltijd','gestort','gereden','opmerking']) if (form.elements[key]) data.set(key, form.elements[key].value);
        try {
          const result = await request(apiUrl('preview'), {method:'POST', headers:{'X-CSRF-Token':csrf}, body:data});
          if (sequence !== previewSequence || !el.isConnected) return;
          el.textContent = result.mails.map(m => `Aan: ${m.to}${m.cc ? '\nCC: ' + m.cc : ''}${m.bcc ? '\nBCC: ' + m.bcc : ''}\nOnderwerp: ${m.subject}\n\n${m.body}`).join('\n\n────────────────────\n\n');
        } catch (error) { if (sequence === previewSequence && el.isConnected) el.textContent = error.message; }
      }, 250);
      return;
    }
    const schedule = form.dataset.kind === 'schedule';
    let body = `Aan: ${t.email}\nKopie: ${t.chauffeurEmail || 'Chauffeur'}\nOnderwerp: ${schedule ? 'Afspraak' : 'Afronding'} — ${t.collectegebied}\n\nBeste ${t.contactpersoon},\n\n`;
    if (schedule) body += `${t.chauffeur} komt de opbrengst ophalen op ${form.elements.afhaalmoment.value || '[datum]'} om ${form.elements.afhaaltijd.value || '[tijd]'}.\nAdres: ${t.adres}, ${t.postcodePlaats}`;
    else {
      body += `De rit voor ${t.collectegebied} is afgerond. Gestort bedrag aan munten: € ${form.elements.gestort.value === '' ? '[bedrag]' : Number(form.elements.gestort.value).toFixed(2)}.`;
      if (form.elements.opmerking.value.trim()) body += '\n\n' + form.elements.opmerking.value.trim();
      const removed = [...form.querySelectorAll('[name="removeReceipts[]"]:checked')].map(el => el.value);
      const names = [...t.receipts.filter(r => !removed.includes(r.id)).map(r => r.name), ...[...form.querySelector('[type=file]').files].map(f => f.name)];
      if (names.length) body += '\n\nBijlagen: ' + names.join(', ');
    }
    if (t.contactOpmerking) body += '\n\nOpmerking: ' + t.contactOpmerking;
    el.textContent = body;
  }
  function showDone(t) {
    open('Afgeronde testrit', 'ALLE STAPPEN VOLTOOID', `${summary(t)}<div class="done-summary"><div><span>Gereden kilometers</span><strong>${escape(t.gereden)}</strong></div><div><span>Gestort bedrag aan munten</span><strong>${euros(t.gestort)}</strong></div></div>${receiptRows(t)}${t.opmerking ? `<p class="mail-preview">${escape(t.opmerking)}</p>` : ''}<h3>Testmails bij deze rit</h3>${mailItems(state.mails.filter(m => m.tripId === t.id))}<div class="form-actions"><button type="button" data-close>Sluiten</button></div>`);
  }
  function mailItems(mails) {
    return mails.length ? [...mails].reverse().map(m => `<article class="mail-item"><h3>${escape(m.subject)}</h3><div class="mail-meta">${escape(new Date(m.at).toLocaleString('nl-NL'))} · Alleen gesimuleerd${m.templateId ? ' · bestaand sjabloon ' + m.templateId : ''}<br>Aan: ${escape(m.to)}${m.copy ? '<br>Kopie: ' + escape(m.copy) : ''}${m.cc ? '<br>CC: ' + escape(m.cc) : ''}${m.bcc ? '<br>BCC: ' + escape(m.bcc) : ''}</div><details><summary>Bekijk bericht${m.attachments.length ? ` · ${m.attachments.length} bijlage(n)` : ''}</summary><div class="mail-preview">${escape(m.body)}${m.attachments.length ? '\n\nBijlagen: ' + escape(m.attachments.join(', ')) : ''}</div></details></article>`).join('') : '<p class="muted">Er zijn nog geen testmails.</p>';
  }
  async function mutate(formData) {
    return request(apiUrl('mutate'), { method: 'POST', headers: { 'X-CSRF-Token': csrf }, body: formData });
  }
  function preferences() {
    if (!user.admin) return;
    const accounts = state.accounts || [];
    const list = (role, title) => `<section class="account-section"><div class="account-heading"><h3>${title}</h3><button type="button" data-account-new="${role}">＋ ${role === 'driver' ? 'Chauffeur' : 'Medewerker'} toevoegen</button></div><div class="account-list">${accounts.filter(a => a.role === role).map(a => `<div class="account-row"><div><strong>${escape(a.name)}</strong><span>${escape(a.email)}</span><small>${a.active ? 'Actief' : 'Inactief'}${a.id.startsWith('beta-account-') ? ' · toegevoegd in bèta' : ''}</small></div><button type="button" data-account-edit="${escape(a.id)}">Bewerken</button></div>`).join('') || '<p class="muted">Nog geen gebruikers in deze lijst.</p>'}</div></section>`;
    open('Beheer', 'ALLEEN ADMIN', `<p class="muted">Beheer chauffeurs, medewerkers en instellingen. Wijzigingen blijven in de gekozen testomgeving. Testgebruikers kunnen niet inloggen en ontvangen geen echte uitnodiging.</p>${errorBox}${list('driver', 'Chauffeurs')}${list('employee', 'Medewerkers')}<section class="account-section"><h3>Collectejaren en instellingen</h3><button type="button" data-year-settings>Collectejaar en kilometervergoeding</button></section><div class="form-actions"><button type="button" data-close>Sluiten</button></div>`);
  }
  function editAccount(account = null, role = 'driver') {
    if (!user.admin) return;
    role = account?.role || role;
    open(account ? 'Gebruiker bewerken' : (role === 'driver' ? 'Chauffeur toevoegen' : 'Medewerker toevoegen'), 'ALLEEN ADMIN', `<form data-kind="account"><input type="hidden" name="accountId" value="${escape(account?.id || '')}"><input type="hidden" name="accountRevision" value="${account?.revision || ''}">${errorBox}<div class="form-grid">${field('Naam', 'name', account?.name || '')}${field('E-mailadres', 'email', account?.email || '', 'email')}<label class="field">Rol<select name="role"><option value="driver" ${role === 'driver' ? 'selected' : ''}>Chauffeur</option><option value="employee" ${role === 'employee' ? 'selected' : ''}>Medewerker</option></select></label><label class="field">Status<select name="active"><option value="1" ${account?.active !== false ? 'selected' : ''}>Actief</option><option value="0" ${account?.active === false ? 'selected' : ''}>Inactief</option></select></label><div class="wide account-driver-fields" ${role === 'employee' ? 'hidden' : ''}><div class="form-grid">${field('Postcode <small>(optioneel)</small>', 'postcode', account?.postcode || '', 'text', false, 'placeholder="1234 AB"')}${field('IBAN <small>(optioneel)</small>', 'iban', account?.iban || '', 'text', false)}</div></div></div><p class="helper">Actieve chauffeurs verschijnen in de toewijslijst. Medewerkers krijgen geen ritten toegewezen. Inactief maken behoudt bestaande ritten en rapporten.</p><div class="form-actions"><button type="button" data-account-back>Terug naar Beheer</button><button class="primary" name="action" value="save_account">Gebruiker opslaan</button></div></form>`);
  }
  function yearPreferences() {
    if (!user.admin) return;
    const rate = state.preferences?.years?.[collectionYear]?.kilometervergoeding || '0.30';
    open('Beheer collectejaren', 'ADMIN', `<p class="muted">Voeg een nieuw jaar toe om met een leeg overzicht te starten. Eerdere jaren blijven beschikbaar. Instellingen gelden voor de gekozen testomgeving.</p><form data-kind="preferences">${errorBox}<div class="form-grid">${field('Collectejaar', 'collectejaar', collectionYear, 'number', true, 'min="2000" max="2099" step="1"')}${field('Kilometervergoeding (€ per km)', 'kilometervergoeding', rate, 'number', true, 'min="0" max="10" step="0.01"')}</div><p class="helper">De vergoeding wordt per collectejaar bewaard. Een wijziging rekent de rapporten van dat jaar opnieuw uit.</p><div class="form-actions"><button type="button" data-close>Annuleren</button><button class="primary" name="action" value="preferences">Jaar en vergoeding opslaan</button></div></form>`);
  }
  function report() {
    const trips = ownTrips().filter(t => t.status === 'done' && (view === 'office' && user.reportAll || t.chauffeurId === user.id)).sort((a,b) => (a.chauffeur || '').localeCompare(b.chauffeur || '') || a.afhaalmoment.localeCompare(b.afhaalmoment));
    const rateCents = Math.round(Number(state.preferences?.years?.[collectionYear]?.kilometervergoeding || '0.30') * 100);
    const declaration = t => Math.round(Number(t.gereden || 0) * rateCents);
    const groups = new Map();
    trips.forEach(t => { const key = t.chauffeurId || t.chauffeur; if (!groups.has(key)) groups.set(key, []); groups.get(key).push(t); });
    const tables = [...groups.values()].map(rows => {
      const kilometers = rows.reduce((sum,t) => sum + Number(t.gereden || 0), 0);
      const amount = rows.reduce((sum,t) => sum + Math.round(Number(t.gestort || 0) * 100), 0);
      const compensation = rows.reduce((sum,t) => sum + declaration(t), 0);
      return `<h3>${escape(rows[0].chauffeur || 'Onbekende chauffeur')}</h3>${rows[0].chauffeurIban ? `<p class="muted">IBAN: ${escape(rows[0].chauffeurIban)}</p>` : ''}<div class="report-table"><table><thead><tr><th>Collectegebied</th><th>Afhaaldatum</th><th>Soort</th><th>Gestort aan munten</th><th>Kilometers</th><th>Declarabel</th></tr></thead><tbody>${rows.map(t => `<tr><td>${escape(t.collectegebied)}</td><td>${escape(date(t.afhaalmoment))}</td><td>${escape(t.soort)}</td><td>${euros(t.gestort)}</td><td>${escape(t.gereden)}</td><td>${euros(declaration(t) / 100)}</td></tr>`).join('')}</tbody><tfoot><tr><th colspan="3">Totaal</th><td>${euros(amount / 100)}</td><td>${kilometers.toLocaleString('nl-NL', {maximumFractionDigits:2})}</td><td>${euros(compensation / 100)}</td></tr></tfoot></table></div>`;
    }).join('');
    open(`Rapport collecte ${collectionYear}`, 'AFGERONDE RITTEN', `<p class="muted">Kilometervergoeding: ${euros(rateCents / 100)} per km. ${view === 'office' && user.reportAll ? 'Gegroepeerd per chauffeur.' : 'Alleen jouw afgeronde ritten.'}</p>${tables || '<p>Geen afgeronde ritten in dit collectejaar.</p>'}<div class="form-actions"><button type="button" data-print>Afdrukken / PDF</button><button type="button" data-close>Sluiten</button></div>`);
  }
  async function claim(t) {
    if (busy) return; busy = true;
    document.querySelectorAll('[data-trip]').forEach(b => b.disabled = true);
    try {
      const data = new FormData(); data.set('testView',view); data.set('action','claim'); data.set('id',t.id); data.set('revision',t.revision);
      const result = await mutate(data); state = result; filter = 'planning'; render(); notice(result.message); editTrip(state.trips.find(x => x.id === t.id));
    } catch(error) { notice(error.message, true); await load(); }
    finally { busy = false; document.querySelectorAll('[data-trip]').forEach(b => b.disabled = false); }
  }
  $('#filters').addEventListener('click', event => { const button = event.target.closest('[data-filter]'); if (button) { filter = button.dataset.filter; render(); $(`[data-filter="${filter}"]`).focus(); } });
  $('#trips').addEventListener('click', event => { const button = event.target.closest('[data-trip]'); if (button) editTrip(state.trips.find(t => t.id === button.dataset.trip)); });
  $('#refresh').addEventListener('click', load);
  $('#collection-year').addEventListener('change', event => { if (user.admin) collectionYear = event.target.value; render(); });
  $('#report').addEventListener('click', report);
  $('#preferences')?.addEventListener('click', preferences);
  $('#create')?.addEventListener('click', create);
  $('#view')?.addEventListener('change', event => { view = event.target.value; render(); });
  $('#mailbox').addEventListener('click', () => { const ids = new Set(ownTrips().map(t => t.id)); open('Testmailbox', 'GEEN ECHTE VERZENDING', `<p class="muted">Hier controleer je de inhoud en ontvangers van de gesimuleerde mails. ${dataset === 'snapshot' ? 'De teksten komen uit de meegekopieerde bestaande mailsjablonen. De weergave is tekstueel, zonder actieve links of externe afbeeldingen.' : 'Dit zijn aparte voorbeeldteksten.'}</p>${mailItems(state.mails.filter(m => ids.has(m.tripId)))}`); });
  $('#dataset')?.addEventListener('change', async event => {
    if (busy) { event.target.value = dataset; return; }
    dataset = event.target.value; state = {trips:[],mails:[]}; filter = 'available'; $('#notice').hidden = true; render(); await load();
  });
  $('#import')?.addEventListener('click', async () => {
    if (busy) return;
    if (state.meta?.importedAt && !window.confirm('Een nieuwe kopie vervangt jouw eerdere testwijzigingen, testbonnen en testmails in deze kopie. De echte gegevens en fictieve oefenritten blijven intact. Nieuwe kopie laden?')) return;
    busy = true; $('#import').disabled = true; $('#dataset').disabled = true;
    try {
      const data = new FormData(); data.set('action', 'import');
      const result = await request(apiUrl('import'), {method:'POST', headers:{'X-CSRF-Token':csrf}, body:data});
      state = result; filter = 'available'; render(); notice(result.message);
    } catch (error) { notice(error.message, true); }
    finally { busy = false; $('#import').disabled = false; $('#dataset').disabled = false; }
  });
  $('#close-dialog').addEventListener('click', () => close());
  $('#editor').addEventListener('cancel', event => { event.preventDefault(); close(); });
  $('#dialog-content').addEventListener('click', event => { if (event.target.closest('[data-close]')) close(); });
  $('#dialog-content').addEventListener('click', event => {
    const add = event.target.closest('[data-account-new]');
    const edit = event.target.closest('[data-account-edit]');
    const back = event.target.closest('[data-account-back]');
    if (back && dirty && !window.confirm('Je hebt wijzigingen die nog niet zijn bewaard. Toch terug naar Beheer?')) return;
    if (add) editAccount(null, add.dataset.accountNew);
    if (edit) editAccount((state.accounts || []).find(a => a.id === edit.dataset.accountEdit));
    if (back) preferences();
    if (event.target.closest('[data-year-settings]')) yearPreferences();
  });
  $('#dialog-content').addEventListener('click', event => { if (event.target.closest('[data-print]')) window.print(); });
  $('#dialog-content').addEventListener('click', event => {
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
    if (event.target.name === 'role' && event.target.form?.dataset.kind === 'account') {
      event.target.form.querySelector('.account-driver-fields').hidden = event.target.value !== 'driver';
    }
    if (event.target.matches('[data-calendar-only]')) {
      event.target.previousElementSibling.innerHTML = `${event.target.value ? escape(date(event.target.value)) : 'Kies een datum'} <span>▦</span>`;
    }
    dirty = true; updatePreview();
  });
  window.addEventListener('beforeunload', event => { if (dirty || busy) { event.preventDefault(); event.returnValue = ''; } });
  $('#dialog-content').addEventListener('submit', async event => {
    event.preventDefault(); if (busy) return;
    const form = event.target, action = event.submitter?.value || ({ create: 'create', assign: 'assign', schedule: 'save_schedule', finish: 'save_finish', account: 'save_account', preferences: 'preferences' }[form.dataset.kind]);
    const error = form.querySelector('.form-error[role=alert]'); error.hidden = true;
    const data = new FormData(form); data.set('action', action);
    if (action === 'create') data.set('collectejaar', collectionYear);
    if (data.has('receipts[]')) {
      const files = [...form.querySelector('[type=file]').files];
      if (files.length > 3 || files.some(f => f.size > 2 * 1024 * 1024)) { error.textContent = 'Kies maximaal drie foto’s van elk maximaal 2 MB.'; error.hidden = false; return; }
    }
    busy = true; form.querySelectorAll('button').forEach(b => b.disabled = true);
    try {
      const result = await mutate(data); state = result;
      if (action === 'preferences') collectionYear = String(data.get('collectejaar'));
      const t = state.trips.find(t => t.id === data.get('id'));
      filter = action === 'create' ? 'available' : (t?.status || filter);
      render(); notice(result.message, !!t?.mailError); dirty = false; busy = false;
      if (action === 'save_account' || action === 'preferences') preferences();
      else if (t?.mailError && ['confirm','finish'].includes(action)) editTrip(t); else close(true);
    } catch (e) {
      error.textContent = e.message; error.hidden = false; error.scrollIntoView({ block: 'nearest' });
    } finally { busy = false; form.querySelectorAll('button').forEach(b => b.disabled = false); }
  });
  load();
})();
