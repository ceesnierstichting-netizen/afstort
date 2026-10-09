'use strict';
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const elements = new Map();
const handlers = new Map();
const element = selector => {
  if (!elements.has(selector)) elements.set(selector, {
    content: 'csrf', innerHTML: '', textContent: '', open: false, disabled: false,
    addEventListener(type, fn) { handlers.set(selector + ':' + type, fn); }, closest() { return null; }, showModal() { this.open = true; }, close() { this.open = false; }, focus() {}
  });
  return elements.get(selector);
};
let fetchCount = 0, pendingFetch;
const intervals = [];
const context = {
  document: { body: { dataset: { userId: '1', userName: 'Kantoor', office: '1', admin: '1', reportAll: '1' } },
    querySelector: element, addEventListener() {}, hidden: false, activeElement: { isConnected: false } },
  window: { addEventListener() {}, confirm() { return true; } },
  crypto: require('node:crypto').webcrypto, Intl, Date, clearTimeout, setTimeout,
  setInterval(fn, ms) { intervals.push({ fn, ms }); },
  fetch(url, options) { fetchCount++; assert.match(url, /^index2\.php\?action=state$/); assert.equal(options.cache, 'no-store'); return new Promise(resolve => { pendingFetch = resolve; }); }
};
vm.createContext(context);
const source = fs.readFileSync(require('node:path').join(__dirname, '../live2/live.js'), 'utf8');
vm.runInContext(source.replace(/  load\(\);\s*\}\)\(\);\s*$/, `
  globalThis.ui = { render, create, report, preferences, showDone, load, refreshIfIdle,
    setState(value) { state = value; }, getState() { return state; },
    setView(value) { view = value; }, setUser(value) { Object.assign(user, value); },
    setBusy(value) { busy = value; }, setDirty(value) { dirty = value; }
  };
})();`), context);
const trip = { id: '12', revision: 'a'.repeat(64), collectegebied: '<Gebied>', contactpersoon: 'Contact',
  postcodePlaats: '1234 AB Stad', status: 'available', chauffeurId: '', soort: 'alleen muntgeld',
  verwachtBedrag: '10.00', receipts: [], afhaalmoment: '', afhaaltijd: '', mailError: '' };
const state = { trips: [trip], mails: [], drivers: [{ id: '2', name: 'Anna' }], meta: { importedAt: '2026-10-02' } };
const reportTrips = [
  { ...trip, status:'done', chauffeurId:'1', chauffeur:'Kantoor', gereden:'10', gestort:'100', collectejaar:2026 },
  { ...trip, id:'13', status:'done', chauffeurId:'2', chauffeur:'Anna', gereden:'20', gestort:'200', collectejaar:2026 },
  { ...trip, id:'14', status:'planned', chauffeurId:'3', chauffeur:'Nog te rijden', gereden:'30', gestort:'300', collectejaar:2026 },
  { ...trip, id:'15', status:'done', chauffeurId:'4', chauffeur:'Ander jaar', gereden:'40', gestort:'400', collectejaar:2025 }
];
context.ui.setState({...state,trips:reportTrips});
for (const view of ['office','driver']) {
  context.ui.setView(view);
  context.ui.setUser({admin:true,reportAll:false});
  context.ui.report();
  const html=element('#dialog-content').innerHTML;
  assert.match(html, /<h3>Kantoor<\/h3>/);
  assert.match(html, /<h3>Anna<\/h3>/);
  assert.doesNotMatch(html, /Nog te rijden|Ander jaar/);
  assert.match(html, /Alle afgeronde ritten, gegroepeerd per chauffeur/);
}
context.ui.setUser({admin:false,reportAll:false});
context.ui.report();
assert.match(element('#dialog-content').innerHTML, /<h3>Kantoor<\/h3>/);
assert.doesNotMatch(element('#dialog-content').innerHTML, /<h3>Anna<\/h3>/);
context.ui.setUser({admin:true,reportAll:true});
context.ui.setView('office');
context.ui.setState(state); context.ui.render();
assert.match(element('#trips').innerHTML, /Chauffeur kiezen/);
assert.match(element('#trips').innerHTML, /&lt;Gebied&gt;/);
context.ui.setState({ ...state, trips: [{ ...trip, aangebodenChauffeur: 'Anna <test>' }] });
context.ui.render();
assert.match(element('#trips').innerHTML, /Uitgezet bij/);
assert.match(element('#trips').innerHTML, /Anna &lt;test&gt;/);
assert.match(element('#trips').innerHTML, /Wacht op reactie/);
context.ui.setState(state);
assert.equal(element('#collection-year').disabled, false);
assert.doesNotMatch(element('#footer-note').textContent, /test|fictief|gesimuleerd/);
context.ui.create();
assert.match(element('#dialog-content').innerHTML, /requestKey" value="[a-f0-9]{32}"/);
assert.match(element('#dialog-content').innerHTML, /echt verstuurd/);
assert.doesNotMatch(element('#dialog-content').innerHTML, /testmail|fictief|gesimuleerd/);
assert.match(element('#dialog-content').innerHTML, /<option value="" selected>Kies dichtstbij wonende<\/option>/);
context.ui.setState({...state, drivers:[
  {id:'2', name:'Anna <test>', email:'anna@example.test', availableYears:[2026]},
  {id:'3', name:'Volgend jaar', email:'future@example.test', availableYears:[2027]},
  {id:'4', name:'Geen mail', email:'', availableYears:[2026]}
]});
context.ui.create();
const createHtml = element('#dialog-content').innerHTML;
assert.match(createHtml, /<option value="2">Anna &lt;test&gt;<\/option>/);
assert.doesNotMatch(createHtml, /Volgend jaar|Geen mail/);
assert.ok(createHtml.indexOf('name="email"') < createHtml.indexOf('name="preferredDriverId"'));
assert.ok(createHtml.indexOf('name="preferredDriverId"') < createHtml.indexOf('name="contactOpmerking"'));
context.ui.setState(state);
context.ui.preferences(); assert.equal(element('#users-management').open, true);
context.ui.setState({ ...state, trips: [{ ...trip, mailError: 'Niet alle mails zijn verstuurd.' }] });
context.ui.render();
assert.match(element('#trips').innerHTML, /data-retry-mail="12"/);
assert.match(element('#trips').innerHTML, /card-action" disabled/);
assert.equal(intervals[0].ms, 30000);
context.ui.setState(state);
context.ui.showDone({ ...trip, status: 'done', gereden: '40', gestort: '548.98' });
assert.match(element('#dialog-content').innerHTML, /data-kind="correct_done"/);
assert.doesNotMatch(element('#dialog-content').innerHTML, /Mails bij deze rit|Er zijn nog geen mails|Laatst verstuurde mail/);
const mail = { tripId: trip.id, status: 'sent', at: '2026-10-01T10:00:00', subject: 'Oude afspraakmail', body: 'Bericht', attachments: [] };
context.ui.setState({ ...state, mails: [
  { ...mail, at: '2026-10-02T10:00:00', subject: 'Afrondingsmail', attachments: ['bon.png'] },
  mail,
  { ...mail, at: '2026-10-03T10:00:00', subject: 'Mislukte mail', status: 'failed' },
  { ...mail, at: '2026-10-04T10:00:00', subject: 'Andere rit', tripId: '99' }
] });
context.ui.showDone({ ...trip, status: 'done', gereden: '40', gestort: '548.98' });
assert.match(element('#dialog-content').innerHTML, /Laatst verstuurde mail/);
assert.match(element('#dialog-content').innerHTML, /Afrondingsmail/);
assert.match(element('#dialog-content').innerHTML, /bon.png/);
assert.doesNotMatch(element('#dialog-content').innerHTML, /Oude afspraakmail|Mislukte mail|Andere rit/);
context.ui.setState({...state, trips:reportTrips, preferences:{currentYear:2026, years:{2025:{kilometervergoeding:'0.25'},2026:{kilometervergoeding:'0.30'}}}});
context.ui.render();
handlers.get('#collection-year:change')({target:{value:'2025'}});
assert.equal(element('#page-title').textContent, 'Rittenoverzicht 2025');
assert.match(element('#trips').innerHTML, /Ander jaar/);
assert.doesNotMatch(element('#trips').innerHTML, /Anna|Kantoor/);
context.ui.report();
assert.match(element('#dialog-content').innerHTML, /Ander jaar/);
assert.match(element('#dialog-content').innerHTML, /10,00/);
assert.match(element('#collection-year').innerHTML, /2026 \(huidig\)/);
handlers.get('#collection-year:change')({target:{value:'2026'}});
context.ui.setState(state);
(async () => {
  const completed = { ...trip, status: 'done', gereden: '40', gestort: '548.98', bedragAangepastDoor: 'Kantoor <test>' };
  context.ui.setState({ ...state, trips: [completed] });
  context.ui.showDone(completed);
  assert.match(element('#dialog-content').innerHTML, /Aangepast door Kantoor &lt;test&gt;/);
  context.FormData = class extends Map { constructor(form) { super(Object.entries(form.values)); } };
  const questions = [];
  context.window.confirm = question => { questions.push(question); return questions.length === 1; };
  const submit = handlers.get('#dialog-content:submit');
  const form = { dataset: {kind:'correct_done'}, values: {id:trip.id, gereden:'45', gestort:'600.00'}, reportValidity:()=>true,
    querySelector:()=>({hidden:true}) };
  await submit({preventDefault(){}, target:form, submitter:{value:'correct_done'}});
  assert.equal(questions.length, 2);
  assert.equal(questions[0], 'Weet je zeker dat het aantal kilometers 45 klopt?');
  assert.match(questions[1], /Weet je zeker dat het bedrag .*600,00 klopt\?/);
  assert.equal(fetchCount, 0, 'Cancelled amount confirmation must prevent saving');
  questions.length = 0;
  context.window.confirm = question => { questions.push(question); return false; };
  form.values.gereden = '40';
  await submit({preventDefault(){}, target:form, submitter:{value:'correct_done'}});
  assert.equal(questions.length, 1, 'Unchanged kilometers must not require confirmation');
  assert.equal(fetchCount, 0);
  element('#editor').open = true; await context.ui.load(); assert.equal(fetchCount, 0);
  element('#editor').open = false; context.ui.setDirty(true); await context.ui.load(); assert.equal(fetchCount, 0);
  context.ui.setDirty(false); context.ui.setBusy(true); await context.ui.load(); assert.equal(fetchCount, 0);
  context.ui.setBusy(false); context.ui.setState(state);
  const load = context.ui.load(); assert.equal(fetchCount, 1);
  context.ui.setDirty(true);
  pendingFetch({ ok: true, text: async () => JSON.stringify({ ...state, trips: [] }) });
  await load;
  assert.equal(context.ui.getState().trips.length, 1, 'Late refresh must preserve newly started edits');
  context.ui.setDirty(false);
  const fresh = context.ui.load();
  pendingFetch({ ok: true, text: async () => JSON.stringify({ ...state, trips: [] }) });
  await fresh;
  assert.equal(context.ui.getState().trips.length, 0);
  console.log('Live portal UI checks passed.');
})().catch(error => { console.error(error); process.exitCode = 1; });
