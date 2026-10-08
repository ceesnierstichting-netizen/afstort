'use strict';
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const elements = new Map();
const element = selector => {
  if (!elements.has(selector)) elements.set(selector, {content:'csrf', innerHTML:'', textContent:'', open:true, addEventListener() {}, scrollTop:0});
  return elements.get(selector);
};
const context = {
  document: {body:{dataset:{userId:'1',userName:'Kantoor',office:'1',admin:'1',reportAll:'1'}}, querySelector:element},
  window:{addEventListener() {}}, Intl, Date, clearTimeout, setTimeout,
};
vm.createContext(context);
const source = fs.readFileSync(require('node:path').join(__dirname, '../beta/beta.js'), 'utf8');
vm.runInContext(source.replace(/  load\(\);\s*\}\)\(\);\s*$/, `
  globalThis.ui = {
    setState(value) { state = value; },
    setView(value) { view = value; },
    setAdmin(value) { user.admin = value; },
    setYear(value) { collectionYear = value; }, render, report, preferences, editAccount
  };
})();`), context);
const base = {id:'old',revision:1,collectegebied:'Gebied',contactpersoon:'Contact',postcodePlaats:'1234 AB Stad',status:'available',chauffeurId:'',soort:'Munten',verwachtBedrag:'10.00',receipts:[]};
context.ui.setState({trips:[base],mails:[],drivers:[{id:'2',name:'Anna'}],preferences:{years:{2026:{kilometervergoeding:'0.30'},2027:{kilometervergoeding:'0.35'}}}});
context.ui.setView('driver');
context.ui.render();
assert.match(element('#trips').innerHTML, /Ik pak deze rit op/);
assert.doesNotMatch(element('#trips').innerHTML, /Chauffeur toewijzen/);
context.ui.setView('office');
context.ui.render();
assert.match(element('#trips').innerHTML, /Chauffeur toewijzen/);
context.ui.setYear('2027');
context.ui.render();
assert.match(element('#trips').innerHTML, /Hier staan nog geen ritten/);
context.ui.setState({trips:[{...base,status:'done',chauffeurId:'1',chauffeur:'Jan',afhaalmoment:'2026-10-01',gereden:'10.00',gestort:'123.45'}, {...base,id:'new',collectejaar:2027,status:'done',chauffeurId:'1',chauffeur:'Jan',afhaalmoment:'2027-10-01',gereden:'10.00',gestort:'45.00'}],mails:[],preferences:{years:{2026:{kilometervergoeding:'0.30'},2027:{kilometervergoeding:'0.35'}}}});
context.ui.report();
assert.match(element('#dialog-content').innerHTML, /3,50/);
assert.doesNotMatch(element('#dialog-content').innerHTML, /123,45/);
context.ui.setYear('2026');
context.ui.report();
assert.match(element('#dialog-content').innerHTML, /3,00/);
assert.match(element('#dialog-content').innerHTML, /123,45/);
context.ui.setState({trips:[],mails:[],accounts:[{id:'driver-1',name:'Jan <test>',email:'jan@example.test',role:'driver',active:true,revision:1},{id:'employee-1',name:'Medewerker',email:'kantoor@example.test',role:'employee',active:false,revision:1}]});
context.ui.preferences();
assert.match(element('#dialog-content').innerHTML, /Chauffeur toevoegen/);
assert.match(element('#dialog-content').innerHTML, /Medewerker toevoegen/);
assert.match(element('#dialog-content').innerHTML, /Jan &lt;test&gt;/);
assert.match(element('#dialog-content').innerHTML, /Inactief/);
context.ui.editAccount(null, 'employee');
assert.equal(element('#dialog-title').textContent, 'Medewerker toevoegen');
assert.match(element('#dialog-content').innerHTML, /value="employee" selected/);
assert.match(element('#dialog-content').innerHTML, /account-driver-fields" hidden/);
assert.equal(element('#collection-year').disabled, false);
assert.equal(element('#preferences').hidden, false);
context.ui.setAdmin(false);
for (const roleView of ['office', 'driver']) {
  context.ui.setView(roleView);
  context.ui.setYear('2027');
  context.ui.render();
  assert.equal(element('#collection-year').disabled, true);
  assert.equal(element('#preferences').hidden, true);
  assert.match(element('#collection-year').innerHTML, /value="2026" selected/);
  const previousDialog = element('#dialog-content').innerHTML;
  context.ui.preferences();
  context.ui.editAccount();
  assert.equal(element('#dialog-content').innerHTML, previousDialog);
}
console.log('Beta UI checks passed.');
