'use strict';
const assert=require('node:assert/strict'),vm=require('node:vm'),fs=require('node:fs');
const elements=new Map();
function element(key) {if(!elements.has(key))elements.set(key,{value:'',addEventListener(){}});return elements.get(key);}
const window={location:{href:'https://example.test/afstort/index2.php'},addEventListener(){}};
const context={document:{body:{dataset:{admin:'1'}},querySelector:element},window,URL,queueMicrotask,console};
vm.createContext(context);
const source=fs.readFileSync('live2/templates.js','utf8').replace('})();', 'window.test={preview,synchronize,setEditor(value){editor=value;selected={text:"[opmerking]"};}};})();');
vm.runInContext(source,context);
element('#mail-template-text').value='[opmerking]';
context.window.test.setEditor({getContent(){return '<p><strong>[opmerking]</strong></p>';}});
context.window.test.preview();
assert.match(element('#mail-template-preview').srcdoc,/<strong>\[opmerking\]<\/strong>/);
assert.match(element('#mail-template-preview').srcdoc,/https:\/\/example.test\/afstort\/live2\/mail-content.css/);
context.window.test.synchronize();
assert.equal(element('#mail-template-text').value,'<p><strong>[opmerking]</strong></p>');
assert.match(source,/ExecCommand/);
assert.match(source,/content_css:'live2\/mail-content.css'/);
console.log('Template editor preview and HTML synchronization checks passed.');
