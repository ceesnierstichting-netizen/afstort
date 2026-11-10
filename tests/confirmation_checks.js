const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');

const source = fs.readFileSync(require('node:path').join(__dirname, '../index.php'), 'utf8');
const validationStart = source.indexOf('    function validateRitConfirmationRow(');
const validationEnd = source.indexOf('    function updateStatusDropdown(', validationStart);
const notifications = [];
let focusedField;
const validationContext = vm.createContext({
  isEmpty: value => !String(value).trim(),
  showNotification: message => notifications.push(message)
});
vm.runInContext(source.slice(validationStart, validationEnd), validationContext);
const values = { chauffeur: 'Frans', afhaalmoment: '', afhaaltijd: '00:00', wijknaam: '', voorkeurAfhaalmoment: '2026-09-29' };
const validationRow = { querySelector: selector => {
  const field = selector.match(/data-field='([^']+)'/)[1];
  return { value: values[field], focus: () => { focusedField = field; } };
} };
assert.equal(validationContext.validateRitConfirmationRow(validationRow), false);
assert.match(notifications.pop(), /Vul de afhaaldatum in/);
assert.equal(focusedField, 'afhaalmoment');
values.afhaalmoment = '2026-09-30';
assert.equal(validationContext.validateRitConfirmationRow(validationRow), true, 'Een lege wijknaam blokkeert niet');
values.afhaaltijd = '';
assert.equal(validationContext.validateRitConfirmationRow(validationRow), false);
assert.equal(notifications.pop(), 'Vul de afhaaltijd in.');
values.afhaaltijd = '12:00';
for (const chauffeur of ['', 'Chauffeur kiezen']) {
  values.chauffeur = chauffeur;
  assert.equal(validationContext.validateRitConfirmationRow(validationRow), false);
  assert.equal(notifications.pop(), 'Kies een chauffeur.');
}
const start = source.indexOf('    async function sendRitConfirmation(');
const end = source.indexOf('    <?php if ($fullAccess): ?>', start);
const alerts = [];
const modal = { style: { display: 'flex' } };
const row = { querySelector: () => ({ value: 'test', selectedOptions: [{ getAttribute: () => 'driver@example.test' }] }) };
const context = vm.createContext({
  currentConfirmRow: row,
  validateRitConfirmationRow: () => true,
  ensureRowSaved: async () => 42,
  document: { getElementById: id => id === 'confirmRitModal' ? modal : { value: 'Bevestiging' } },
  formatFullDate: value => value,
  formatTime: value => value,
  alert: message => alerts.push(message)
});
vm.runInContext(source.slice(start, end), context);
const response = (status, result) => ({ status, ok: status === 200, json: async () => result });

(async () => {
  for (const [reply, expected] of [
    [response(401, {}), /sessie is verlopen/],
    [response(403, {}), /beveiligingstoken/],
    [{ ...response(200, {}), redirected: true }, /sessie is verlopen/],
    [{ ...response(500, {}), json: async () => { throw new SyntaxError('HTML'); } }, /HTTP 500/],
    [response(200, { status: 'error', message: 'Mail kon niet worden verstuurd.' }), /Mail kon niet/],
    [response(200, null), /Versturen mislukt/]
  ]) {
    context.apiFetch = async () => reply;
    await assert.rejects(context.sendRitConfirmation('test', {}), expected);
  }
  context.apiFetch = async () => { throw new Error('offline'); };
  await assert.rejects(context.sendRitConfirmation('test', {}), /verzendstatus is onbekend/);

  context.apiFetch = async url => response(200, url.includes('Contact')
    ? { status: 'error', message: 'Contactmail mislukt' }
    : { status: 'success' });
  await context.confirmRit();
  assert.equal(alerts.length, 1);
  assert.match(alerts[0], /Contactpersoon: Contactmail mislukt/);
  assert.match(alerts[0], /Chauffeur: verstuurd/);
  assert.equal(modal.style.display, 'flex');
  alerts.length = 0;

  context.apiFetch = async () => response(200, { status: 'success' });
  await context.confirmRit();
  assert.deepEqual(alerts, ['Bevestigingsmails verstuurd.']);
  assert.equal(modal.style.display, 'none');
  console.log('Confirmation checks passed.');
})().catch(error => { console.error(error); process.exitCode = 1; });
