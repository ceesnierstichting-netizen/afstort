'use strict';
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const source = fs.readFileSync(require('node:path').join(__dirname, '../live2/live.js'), 'utf8');
const start = source.indexOf('  async function prepareReceiptPhoto(');
const end = source.indexOf('  function receiptRows(', start);
assert.ok(start > 0 && end > start);
assert.match(source, /data-receipt-input="camera" accept="image\/\*" capture="environment"/);
assert.match(source, /data-receipt-input="library"[^>]*multiple/);
assert.match(source, /data\.append\('receipts\[\]', file, file\.name\)/);
const error = {hidden:true}, list = {innerHTML:''}, buttons = [{disabled:false}];
let removed = 0;
const form = {
  elements:{id:{value:'1'}},
  querySelector(selector) { return selector === '[data-pending-receipts]' ? list : error; },
  querySelectorAll(selector) { return selector === 'button' ? buttons : Array(removed).fill({}); }
};
const context = vm.createContext({busy:false, dirty:false, state:{trips:[{id:'1',receipts:[]}]},
  escape:s=>s.replaceAll('<','&lt;'), File:require('node:buffer').File});
vm.runInContext(source.slice(start,end),context);
const photo = name=>({name, type:'image/jpeg', size:1024});
const add = async files=> {
  const input={files, value:'selection', closest:()=>form};
  await context.addReceiptPhotos(input);
  assert.equal(input.value,'', 'Picker must reset for another photo');
};
(async()=>{
  await add([photo('eerste.jpg')]);
  await add([photo('tweede.jpg')]);
  assert.equal(form.receiptFiles.length,2,'Taking another photo replaced the first');
  assert.match(list.innerHTML,/eerste.jpg/); assert.match(list.innerHTML,/tweede.jpg/);
  await add([photo('derde.jpg')]);
  await add([photo('vierde.jpg')]);
  assert.equal(form.receiptFiles.length,3); assert.equal(error.hidden,false);
  form.receiptFiles.splice(0,1); context.renderPendingReceipts(form);
  await add([photo('vervanging.jpg')]);
  assert.equal(form.receiptFiles.length,3); assert.equal(error.hidden,true);
  form.receiptFiles=[];
  context.state.trips[0].receipts=[{id:'saved'}];
  await add([photo('a.jpg'),photo('b.jpg'),photo('c.jpg')]);
  assert.equal(form.receiptFiles.length,0,'Stored receipt must count towards limit');
  removed=1;
  await add([photo('a.jpg'),photo('b.jpg'),photo('c.jpg')]);
  assert.equal(form.receiptFiles.length,3,'Removing stored receipt must free a slot');
  assert.equal(context.busy,false); assert.equal(buttons[0].disabled,false);
  let closed=false;
  context.createImageBitmap=async()=>({width:4000,height:3000,close(){closed=true;}});
  const canvas={getContext:()=>({fillRect(){},drawImage(){}}),toBlob:fn=>fn(new Blob(['small'],{type:'image/jpeg'}))};
  context.document={createElement:()=>canvas};
  const resized=await context.prepareReceiptPhoto({name:'camera.png',type:'image/png',size:5000000});
  assert.equal(resized.type,'image/jpeg'); assert.equal(resized.name,'camera.jpg');
  assert.equal(canvas.width,2000); assert.equal(canvas.height,1500); assert.equal(closed,true);
  context.createImageBitmap=async()=>{throw Error('Invalid image');};
  await assert.rejects(context.prepareReceiptPhoto({name:'bad.heic',type:'image/heic',size:5000}),/niet worden gelezen/);
  console.log('Live receipt camera and upload checks passed.');
})().catch(e=>{console.error(e);process.exitCode=1;});
