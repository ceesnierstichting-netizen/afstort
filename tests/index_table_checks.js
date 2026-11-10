const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const source = fs.readFileSync(require('node:path').join(__dirname, '../index.php'), 'utf8');
const start = source.indexOf('    function buildRitRow(');
const end = source.indexOf('    function ensureRowSaved(', start);
const head = source.slice(source.indexOf('<thead>'), source.indexOf('</thead>'));
const columns = [...head.matchAll(/<th data-column="([^"]+)"/g)].map(match => match[1]);
assert.equal(columns.length, 10);
for (const fullAccess of [false, true]) {
  const context = vm.createContext({
    fullAccess, canAdmin: fullAccess,
    normalizeChauffeurValue: value => value || 'Chauffeur kiezen',
    escapeHtmlAttribute: value => String(value ?? '').replace(/</g, '&lt;').replace(/>/g, '&gt;'),
    addAutoSaveListeners() {},
    document: { createElement(tag) { return {
      tag, dataset: {}, children: [], innerHTML: '', setAttribute() {},
      appendChild(cell) { this.children.push(cell); }, querySelectorAll() { return []; }
    }; } }
  });
  vm.runInContext(source.slice(start, end), context);
  const row = context.buildRitRow({ id: 1, contactpersoon: 'Contact', opmerking: '</td><td>Extra kolom' });
  assert.equal(row.children.length, 10);
  row.children.forEach((cell, i) => {
    assert.equal(cell.tag, 'td');
    assert.equal(cell.dataset.column, columns[i]);
    const field = i === 0 ? 'contactpersoon' : columns[i];
    assert.ok(cell.innerHTML.includes(`data-field="${field}"`), `Verkeerd veld onder ${columns[i]}`);
  });
  assert.match(row.children[0].innerHTML, /&lt;\/td&gt;&lt;td&gt;Extra kolom/);
}
console.log('Index table column checks passed.');
