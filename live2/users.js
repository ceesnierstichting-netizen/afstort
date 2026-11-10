'use strict';
(() => {
  const $ = selector => document.querySelector(selector);
  const section = $('#users-section');
  if (!section) return;
  const admin = document.body.dataset.admin === '1';
  const csrf = $('meta[name="csrf-token"]').content;
  const employeeCsrf = $('meta[name="medewerker-csrf"]').content;
  const escape = value => String(value ?? '').replace(/[&<>"']/g, c => ({ '&':'&amp;', '<':'&lt;', '>':'&gt;', '"':'&quot;', "'":'&#39;' }[c]));
  let busy = false, dirty = false;
  let driversById = new Map();
  $('#users-management-close')?.addEventListener('click', () => {
    if (!busy && window.dispatchEvent(new Event('management-before-close',{cancelable:true}))) $('#users-management').close();
  });
  $('#users-management')?.addEventListener('cancel', event => {
    if (busy || !window.dispatchEvent(new Event('management-before-close',{cancelable:true}))) event.preventDefault();
  });
  const url = action => `index.php?action=${encodeURIComponent(action)}`;
  function notice(text, error = false) {
    const el = $('#users-notice'); el.hidden = false; el.textContent = text; el.className = error ? 'error' : '';
  }
  async function request(action, data, endpoint) {
    const response = await fetch(endpoint || url(action), {
      credentials: 'same-origin', cache: 'no-store',
      ...(data === undefined ? {} : { method: 'POST', headers: { 'Content-Type':'application/json', 'X-CSRF-Token':csrf }, body:JSON.stringify(data) })
    });
    const text = await response.text();
    let result;
    try { result = JSON.parse(text); } catch { result = text.trim(); }
    if (!response.ok || response.redirected || result?.status === 'error' || result?.error) throw new Error(result?.error || result?.message || (typeof result === 'string' && !result.includes('<') ? result : 'Het verzoek is mislukt. Log zo nodig opnieuw in.'));
    return result;
  }
  function renderList(rows, role) {
    if (!Array.isArray(rows)) throw new Error('Geen geldig gebruikersoverzicht ontvangen.');
    if (role === 'driver') driversById = new Map(rows.map(person => [String(person.id), person]));
    const target = $(role === 'driver' ? '#users-driver-list' : role === 'admin' ? '#users-admin-list' : '#users-employee-list');
    target.innerHTML = rows.length ? rows.map(person => {
      const canDelete = role !== 'admin' && admin && (role === 'employee' ? String(person.id) !== document.body.dataset.userId : !Number(person.is_medewerker));
      const location = [person.postcode, person.woonplaats].map(value => String(value || '').trim()).filter(Boolean).join(' ');
      return `<li><div><strong>${escape(person.naam)}</strong><span>${escape([location, person.email, person.mobiel].filter(Boolean).join(' · '))}</span>${role === 'driver' ? `<span>${person.actief ? 'Dit jaar actief' : 'Dit jaar niet actief'} (${escape(person.actief_jaar)})</span>` : ''}</div>${admin ? `<div class="users-row-actions">${role === 'driver' ? `<button type="button" data-user-action="profile" data-id="${escape(person.id)}">06-nummer / actieve jaren</button>` : ''}<button type="button" data-user-action="recovery" data-id="${escape(person.id)}" data-name="${escape(person.naam)}">Stuur 2FA-herstelmail</button>${canDelete ? `<button type="button" class="users-delete" data-user-action="delete" data-role="${role}" data-id="${escape(person.id)}" data-name="${escape(person.naam)}">Verwijder</button>` : ''}</div>` : ''}</li>`;
    }).join('') : `<li>Geen ${role === 'driver' ? 'chauffeurs' : 'medewerkers'} gevonden.</li>`;
  }
  async function loadUsers() {
    const results = await Promise.allSettled([
      request('loadChauffeurs').then(rows => renderList(rows, 'driver')),
      request('loadMedewerkers').then(rows => renderList(rows, 'employee'))
    ]);
    results.forEach((result, i) => {
      if (result.status !== 'rejected') return;
      $(i === 0 ? '#users-driver-list' : '#users-employee-list').innerHTML = '<li>Het overzicht kon niet worden geladen.</li>';
      notice(result.reason.message, true);
    });
  }
  let settings;
  function renderYears() {
    $('#portal-current-year').textContent = 'Huidig collectejaar: ' + settings.currentYear;
    $('#collection-years-list').innerHTML = Object.keys(settings.years).sort((a, b) => b - a).map(year => {
      const current = Number(year) === settings.currentYear;
      const status = current ? 'Huidig jaar' : Number(year) < settings.currentYear ? 'Eerder jaar' : 'Voorbereid';
      return `<tr${current ? ' class="current-collection-year"' : ''}><th scope="row">${escape(year)}</th><td>${status}</td><td>€ ${escape(settings.years[year].kilometervergoeding.replace('.', ','))}</td><td><div class="users-row-actions"><button type="button" data-year-edit="${escape(year)}">Vergoeding wijzigen</button>${current ? '' : `<button type="button" data-year-current="${escape(year)}">Maak huidig</button>`}</div></td></tr>`;
    }).join('');
  }
  function newYear() {
    const form = $('#portal-settings-form');
    form.elements.year.value = Math.min(2099, Math.max(...Object.keys(settings.years).map(Number)) + 1);
    form.elements.rate.value = settings.years[settings.currentYear].kilometervergoeding.replace('.', ',');
    $('#collection-year-form-title').textContent = 'Collectejaar toevoegen';
  }
  $('#collection-year-add')?.addEventListener('click', () => { if (settings && !busy) newYear(); });
  $('#collection-years-list')?.addEventListener('click', async event => {
    const edit = event.target.closest('[data-year-edit]');
    const current = event.target.closest('[data-year-current]');
    if (!admin || busy || !settings) return;
    if (edit) {
      const form = $('#portal-settings-form');
      form.elements.year.value = edit.dataset.yearEdit;
      form.elements.rate.value = settings.years[edit.dataset.yearEdit].kilometervergoeding.replace('.', ',');
      $('#collection-year-form-title').textContent = 'Vergoeding wijzigen voor ' + edit.dataset.yearEdit;
      form.elements.rate.focus();
    } else if (current) {
      busy = true; current.disabled = true;
      try {
        const result = await request('', {operation:'setCurrent', year:current.dataset.yearCurrent, revision:settings.revision}, 'index2.php?action=settings');
        settings = result.preferences; renderYears();
        notice('Huidig collectejaar: ' + settings.currentYear + '. Alle andere jaren blijven bewaard.');
        window.dispatchEvent(new Event('portal-settings-changed'));
        await loadUsers();
      } catch (error) { notice(error.message, true); }
      finally { busy = false; current.disabled = false; }
    }
  });
  async function loadManagement() {
    if (!admin) return;
    try {
      const result=await request('',undefined,'index2.php?action=management');
      renderList(result.administrators,'admin'); settings=result.preferences;
      renderYears(); newYear();
      $('#portal-settings-form').querySelector('button[type="submit"]').disabled=false;
    } catch(error) { notice(error.message,true); }
  }
  $('#preferences')?.addEventListener('click', () => { loadManagement(); loadUsers(); });
  $('#portal-settings-form')?.addEventListener('change',event=>{
    if(event.target.name==='year' && settings) event.currentTarget.elements.rate.value=settings.years[event.target.value]?.kilometervergoeding?.replace('.',',') || '0,30';
  });
  $('#portal-settings-form')?.addEventListener('submit',async event=>{
    event.preventDefault(); if(busy || !admin || !settings) return;
    const form=event.currentTarget; busy=true; form.querySelector('button[type="submit"]').disabled=true;
    try {
      const result=await request('',{operation:'saveYear',year:form.elements.year.value,rate:form.elements.rate.value,revision:settings.revision},'index2.php?action=settings');
      settings=result.preferences; renderYears(); notice('Collectejaar ' + form.elements.year.value + ' opgeslagen. Huidig jaar: ' + settings.currentYear + '.');
      window.dispatchEvent(new Event('portal-settings-changed'));
      await loadUsers();
    } catch(error) { notice(error.message,true); }
    finally {busy=false;form.querySelector('button[type="submit"]').disabled=false;}
  });
  const tabs = [$('#users-drivers-tab'), $('#users-employees-tab'),$('#users-admins-tab'),$('#users-settings-tab'),$('#users-templates-tab')].filter(Boolean);
  function editProfile(person) {
    const form = $('#live-user-form'); form.dataset.role = 'profile'; form.dataset.id = person.id; form.dataset.year = person.actief_jaar;
    $('#user-editor-title').textContent = 'Chauffeur: ' + person.naam;
    const availableYears = JSON.parse(person.beschikbare_jaren || '[]');
    const years = (person.collectejaren || [person.actief_jaar]).map(Number).sort((a, b) => a - b);
    form.innerHTML = `<label class="field">06-nummer (optioneel)<input name="mobiel" type="tel" maxlength="20" autocomplete="tel" value="${escape(person.mobiel)}" placeholder="06-12345678"></label><fieldset><legend>Actieve collectejaren</legend>${years.map(year => `<label><input name="years" type="checkbox" value="${escape(year)}" ${availableYears.includes(year) ? 'checked' : ''}> Actief in ${escape(year)}</label>`).join('')}</fieldset><p>Vink een jaar uit om nieuwe toewijzingen voor dat jaar te stoppen. Bestaande ritten blijven behouden. Nieuwe jaren maak je aan bij Collectejaren.</p><p class="form-error" role="status" hidden></p><div class="form-actions"><button type="button" data-user-close>Sluiten</button><button type="submit" class="primary">Opslaan</button></div>`;
    dirty = false; $('#user-editor').showModal();
  }
  function activate(tab) {
    tabs.forEach(item => { const selected = item === tab; item.setAttribute('aria-selected', String(selected)); item.tabIndex = selected ? 0 : -1; $('#' + item.getAttribute('aria-controls')).hidden = !selected; });
  }
  tabs.forEach((tab, i) => {
    tab.addEventListener('click', () => activate(tab));
    tab.addEventListener('keydown', event => {
      if (!['ArrowLeft','ArrowRight','Home','End'].includes(event.key)) return;
      event.preventDefault(); const next = event.key === 'Home' ? tabs[0] : event.key === 'End' ? tabs[tabs.length-1] : tabs[(i+(event.key==='ArrowRight'?1:tabs.length-1))%tabs.length]; activate(next); next.focus();
    });
  });
  function addUser(role) {
    if (!admin || busy) return;
    const form = $('#live-user-form'); form.dataset.role = role;
    $('#user-editor-title').textContent = role === 'driver' ? 'Voeg chauffeur toe' : 'Voeg medewerker toe';
    const field = (label, name, type = 'text', required = false, extra = '') => `<label class="field">${label}<input name="${name}" type="${type}" ${required ? 'required' : ''} maxlength="255" ${extra}></label>`;
    form.innerHTML = `<p>${role === 'employee' ? 'De medewerker ontvangt een uitnodiging om een wachtwoord aan te maken en 2FA in te stellen.' : 'Vul de gegevens van de nieuwe chauffeur in.'}</p><div class="form-grid">${field('Naam','naam','text',true)}${field('E-mail','email','email',role === 'employee')}${role === 'driver' ? `${field('06-nummer (optioneel)','mobiel','tel',false,'autocomplete="tel" placeholder="06-12345678"')}${field('Postcode (optioneel)','postcode')}${field('IBAN (optioneel)','iban')}${field('Wachtwoord (minimaal 8 tekens, 1 cijfer en 1 leesteken)','wachtwoord','password',true,'autocomplete="new-password"')}` : ''}</div><p class="form-error" role="status" hidden></p><div class="form-actions"><button type="button" data-user-close>Sluiten</button><button type="submit" class="primary">Voeg ${role === 'driver' ? 'chauffeur' : 'medewerker'} toe</button></div>`;
    dirty = false; $('#user-editor').showModal();
    if (role === 'driver') form.querySelector('.form-grid').insertAdjacentHTML('beforeend', '<label><input name="active" type="checkbox" checked> Dit jaar actief</label>');
  }
  function closeEditor() { if (busy || (dirty && !window.confirm('Je hebt nog niet opgeslagen gegevens. Toch sluiten?'))) return; dirty = false; $('#user-editor').close(); }
  $('#user-editor-close')?.addEventListener('click', closeEditor);
  $('#user-editor')?.addEventListener('cancel', event => { event.preventDefault(); closeEditor(); });
  $('#live-user-form')?.addEventListener('click', event => { if (event.target.closest('[data-user-close]')) closeEditor(); });
  $('#live-user-form')?.addEventListener('input', () => { dirty = true; });
  window.addEventListener('beforeunload', event => { if (busy || dirty) { event.preventDefault(); event.returnValue = ''; } });
  section.addEventListener('click', async event => {
    const add = event.target.closest('[data-add-user]'); if (add) { addUser(add.dataset.addUser); return; }
    const button = event.target.closest('[data-user-action], #users-rebuild'); if (!button || busy || !admin) return;
    const action = button.dataset.userAction;
    if (action === 'profile') { const person = driversById.get(String(button.dataset.id)); if (person) editProfile(person); return; }
    const prompt = action === 'recovery' ? `Stuur een 2FA-herstelmail naar het geregistreerde e-mailadres van ${button.dataset.name}?` : action === 'delete' ? `Weet je zeker dat je ${button.dataset.name} wilt verwijderen?` : 'Weet je zeker dat je alle lat/lon opnieuw wilt laten berekenen?';
    if (!window.confirm(prompt)) return;
    busy = true; button.disabled = true;
    try {
      let result;
      if (action === 'recovery') result = await request('sendTwofaRecoveryMail', { id:button.dataset.id });
      else if (action === 'delete') result = button.dataset.role === 'employee'
        ? await request('deleteMedewerker', { id:button.dataset.id, csrf:employeeCsrf })
        : await request('deleteChauffeur', { chauffeur:button.dataset.name });
      else result = await request('rebuildAllGeocodes', {});
      if (typeof result === 'string' && result !== 'Chauffeur verwijderd.') throw new Error(result);
      notice(typeof result === 'string' ? result : result.message || `Lat/lon herberekend. Ritten bijgewerkt: ${result.ritten?.updated ?? 0}. Chauffeurs bijgewerkt: ${result.chauffeurs?.updated ?? 0}.`);
      if (action === 'delete') await loadUsers();
    } catch (error) { notice(error.message, true); }
    finally { busy = false; button.disabled = false; }
  });
  $('#live-user-form')?.addEventListener('submit', async event => {
    event.preventDefault(); if (busy || !admin) return;
    const form = event.currentTarget, role = form.dataset.role, status = form.querySelector('[role="status"]');
    busy = true; form.querySelectorAll('button').forEach(button => { button.disabled = true; });
    status.hidden = false; status.textContent = role === 'profile' ? 'Chauffeurgegevens worden opgeslagen…' : 'Gebruiker wordt aangemaakt…';
    try {
      if (role === 'profile') {
        const result = await request('', { id:form.dataset.id, year:form.dataset.year, mobiel:form.elements.mobiel.value.trim(), years:Array.from(form.querySelectorAll('input[name="years"]:checked'), input => Number(input.value)) }, 'index2.php?action=driverProfile');
        dirty = false; await loadUsers(); notice(result.message); $('#user-editor').close();
        window.dispatchEvent(new Event('portal-settings-changed')); return;
      }
      const data = { naam:form.elements.naam.value.trim(), email:form.elements.email.value.trim() };
      const result = role === 'employee' ? await request('', { ...data, csrf:employeeCsrf }, 'addMedewerker.php')
        : await request('addChauffeur', { chauffeur:data.naam, email:data.email, postcode:form.elements.postcode.value.trim(), mobiel:form.elements.mobiel.value.trim(), active:form.elements.active.checked, IBAN:form.elements.iban.value.trim(), wachtwoord:form.elements.wachtwoord.value });
      const created = role === 'employee' ? !!result.created : result === 'Chauffeur toegevoegd.';
      status.textContent = typeof result === 'string' ? result : result.message;
      if (created) { form.reset(); dirty = false; await loadUsers(); }
    } catch (error) { status.textContent = error.message + ' Controleer bij een verbindingsfout of het account bestaat voordat je opnieuw probeert.'; }
    finally { busy = false; form.querySelectorAll('button').forEach(button => { button.disabled = false; }); }
  });
  loadUsers();
})();
