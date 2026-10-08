const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const path = require('node:path');

const source = fs.readFileSync(path.join(__dirname, '../index.php'), 'utf8');
const start = source.indexOf('    function updateRowBackground(');
const end = source.indexOf('    function autoSave(', start);
assert.ok(start >= 0 && end > start);

for (const canAdmin of [true, false]) {
  for (const value of ['', '0', '123']) {
    const fields = Object.fromEntries(['chauffeur', 'afhaalmoment', 'afhaaltijd', 'gestort', 'gereden'].map(name => [name, {value, disabled: true}]));
    const status = {value: 'Afgehandeld'};
    const row = {
      style: {},
      querySelector: selector => selector.includes("'status'") ? status : fields.chauffeur,
      querySelectorAll: () => Object.values(fields)
    };
    const context = vm.createContext({canAdmin, updateStatusDropdown() {}});
    vm.runInContext(source.slice(start, end), context);
    context.updateRowBackground(row);
    for (const field of Object.values(fields)) assert.equal(field.disabled, !canAdmin);
    assert.equal(status.value, 'Afgehandeld');
    assert.equal(row.style.backgroundColor, '#ccffcc');
  }
}

const reopenStart = source.indexOf('    async function reopenCompletedTrip(');
const reopenEnd = source.indexOf('    function markRowDirty(', reopenStart);
assert.ok(reopenStart >= 0 && reopenEnd > reopenStart);
(async () => {
  for (const [canAdmin, confirmed, failed] of [[false,true,false], [true,false,false], [true,true,false], [true,true,true]]) {
    const status = {value:'Afgehandeld'};
    const row = {querySelector:()=>status};
    const button = {disabled:false, removed:false, closest:()=>row, remove(){this.removed=true;}};
    let saves = 0, dirty = 0, notifications = 0;
    const context = vm.createContext({canAdmin, window:{confirm:()=>confirmed}, saveTimer:0, clearTimeout(){},
      markRowDirty(){dirty++;}, updateRowBackground(){}, showNotification(){notifications++;},
      async saveRitten(){saves++; if (failed) throw Error('Conflict');}});
    vm.runInContext(source.slice(reopenStart,reopenEnd),context);
    await context.reopenCompletedTrip(button);
    const allowed = canAdmin && confirmed;
    assert.equal(saves, allowed ? 1 : 0);
    assert.equal(dirty, allowed ? 1 : 0);
    assert.equal(status.value, allowed && !failed ? '-' : 'Afgehandeld');
    assert.equal(button.removed, allowed && !failed);
    assert.equal(button.disabled,false);
    assert.equal(notifications, allowed && !failed ? 1 : 0);
  }
  console.log('Completed trip checks passed.');
})().catch(error => {console.error(error); process.exitCode=1;});
