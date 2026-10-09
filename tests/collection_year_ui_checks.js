'use strict';
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const path = require('node:path');
const elements = new Map(), requests = [];
const element = key => {
  if (!elements.has(key)) elements.set(key, {
    dataset:{}, content:'token', events:{}, innerHTML:'',
    elements:{year:{value:''},rate:{value:'',focus(){}}},
    addEventListener(type, handler){this.events[type]=handler;},
    querySelector(selector){return element(key + ' ' + selector);}
  });
  return elements.get(key);
};
let preferences = {currentYear:2026, revision:1, years:{2023:{kilometervergoeding:'0.25'},2026:{kilometervergoeding:'0.30'},2027:{kilometervergoeding:'0.35'}}};
const context = vm.createContext({
  document:{body:{dataset:{admin:'1'}},querySelector:element},
  window:{addEventListener(){},dispatchEvent(){}}, Event:class {},
  fetch:async (url, options) => {
    if (options?.body) {
      const body = JSON.parse(options.body); requests.push(body);
      if (body.operation === 'setCurrent') preferences.currentYear = Number(body.year);
      else preferences.years[body.year] = {kilometervergoeding:body.rate.replace(',', '.')};
      preferences.revision++;
    }
    return {ok:true,text:async()=>JSON.stringify(url.includes('action=settings') ? {preferences} : [])};
  }
});
const source = fs.readFileSync(path.join(__dirname, '../live2/users.js'), 'utf8');
vm.runInContext(source.replace(/  loadUsers\(\);\s*\}\)\(\);\s*$/, '  globalThis.years = {renderYears, newYear, setSettings(value){settings=value;}};\n})();'), context);
(async () => {
  context.years.setSettings(preferences); context.years.renderYears(); context.years.newYear();
  const table = element('#collection-years-list');
  assert.match(table.innerHTML, /2023<\/th><td>Eerder jaar/);
  assert.match(table.innerHTML, /2026<\/th><td>Huidig jaar/);
  assert.match(table.innerHTML, /2027<\/th><td>Voorbereid/);
  assert.doesNotMatch(table.innerHTML, /data-year-current="2026"/);
  const form = element('#portal-settings-form');
  assert.equal(form.elements.year.value, 2028);
  form.elements.rate.value = '0,40';
  await form.events.submit({preventDefault(){},currentTarget:form});
  assert.equal(requests[0].operation, 'saveYear');
  assert.equal(preferences.currentYear, 2026);
  assert.match(table.innerHTML, /2028<\/th><td>Voorbereid/);
  const button = {dataset:{yearCurrent:'2027'}};
  await table.events.click({target:{closest(selector){return selector === '[data-year-current]' ? button : null;}}});
  assert.equal(requests[1].operation, 'setCurrent');
  assert.equal(preferences.currentYear, 2027);
  assert.match(table.innerHTML, /2027<\/th><td>Huidig jaar/);
  assert.match(table.innerHTML, /2023<\/th><td>Eerder jaar/);
  const edit = {dataset:{yearEdit:'2023'}};
  await table.events.click({target:{closest(selector){return selector === '[data-year-edit]' ? edit : null;}}});
  assert.equal(form.elements.year.value, '2023');
  assert.equal(form.elements.rate.value, '0,25');
  console.log('Collection year management UI checks passed.');
})().catch(error => {console.error(error);process.exitCode=1;});
