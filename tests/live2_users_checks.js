const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const path = require('node:path');
const source = fs.readFileSync(path.join(__dirname, '../live2/users.js'), 'utf8');
const html = fs.readFileSync(path.join(__dirname, '../index2.php'), 'utf8');
assert.match(html, /id="mailbox" href="emailRapport.php"/);
assert.doesNotMatch(fs.readFileSync(path.join(__dirname, '../live2/live.js'), 'utf8'), /\$\('#mailbox'\)\.addEventListener/);
for (const admin of [false, true]) {
  const elements = new Map(), requests = [];
  const element = selector => {
    if (!elements.has(selector)) elements.set(selector, {
      content: selector.includes('medewerker') ? 'employee-token' : 'csrf-token',
      dataset: {}, innerHTML: '', textContent: '', hidden: false, events: {},
      addEventListener(type, fn) { this.events[type] = fn; },
      setAttribute() {}, getAttribute() { return 'users-drivers'; },
      showModal() { this.open = true; }, focus() {}
    });
    return elements.get(selector);
  };
  const context = vm.createContext({
    document: { body: { dataset: { admin: admin ? '1' : '0', userId: '1' } }, querySelector: element },
    window: { addEventListener() {}, confirm: () => true },
    fetch: async (url, options) => { requests.push({ url, options }); return { ok: true, text: async () => '[]' }; }
  });
  vm.runInContext(source.replace(/  loadUsers\(\);\s*\}\)\(\);\s*$/, '  globalThis.users = { renderList, addUser, request };\n})();'), context);
  context.users.renderList([{ id: 2, naam: '<Anna>', email: 'anna@example.test', postcode: '1234AB', woonplaats: '<Stad>', is_medewerker: 0 }], 'driver');
  const list = element('#users-driver-list').innerHTML;
  assert.match(list, /&lt;Anna&gt;/);
  assert.match(list, /1234AB &lt;Stad&gt; · anna@example.test/);
  assert.equal(list.includes('data-user-action="recovery"'), admin);
  assert.equal(list.includes('data-user-action="delete"'), admin);
  context.users.renderList([{ id: 1, naam: 'Ik', email: 'ik@example.test' }], 'employee');
  assert.doesNotMatch(element('#users-employee-list').innerHTML, /data-user-action="delete"/);
  context.users.addUser('employee');
  assert.equal(!!element('#user-editor').open, admin);
  if (admin) {
    assert.match(element('#live-user-form').innerHTML, /2FA in te stellen/);
    context.users.addUser('driver');
    assert.match(element('#live-user-form').innerHTML, /name="wachtwoord"/);
    assert.match(element('#live-user-form').innerHTML, /name="iban"/);
  }
  context.users.request('sendTwofaRecoveryMail', { id: 2 });
  assert.equal(requests[0].url, 'index.php?action=sendTwofaRecoveryMail');
  assert.equal(requests[0].options.headers['X-CSRF-Token'], 'csrf-token');
}
console.log('Live user management checks passed.');
