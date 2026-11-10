'use strict';
(() => {
  const $=selector=>document.querySelector(selector), form=$('#mail-template-form');
  if (!form || document.body.dataset.admin!=='1') return;
  const select=$('#mail-template-select'), text=$('#mail-template-text'), save=$('#mail-template-save'), reload=$('#mail-template-reload');
  let templates=[], selected, dirty=false, busy=false, editor, editorLoading, settingContent=false;
  async function ensureEditor() {
    if(editor || !window.tinymce) return;
    if(editorLoading) return editorLoading;
    editorLoading=window.tinymce.init({
      target:text, base_url:'assets/tinymce', suffix:'.min', license_key:'gpl',
      promotion:false, branding:false, ui_mode:'split', menubar:false, height:400,
      plugins:'link lists code table',
      content_css:'live2/mail-content.css',
      formats:{bold:{inline:'strong'},italic:{inline:'em'},underline:{inline:'u'}},
      toolbar:'undo redo | fontsize | bold italic underline | bullist numlist | link table | removeformat | code',
      font_size_formats:'10px 12px 14px 16px 18px 20px 24px 28px 32px 36px',
      convert_urls:false, entity_encoding:'raw', browser_spellcheck:true,
      setup(instance) {
        instance.on('init',()=>{
          editor=instance; settingContent=true; editor.setContent(text.value); settingContent=false;
          editor.mode.set(busy || !selected ? 'readonly' : 'design');
          // Keep floating menus and dialogs inside the native modal's top layer.
          document.querySelectorAll('.tox-tinymce-aux').forEach(auxiliary=>$('#users-management').appendChild(auxiliary));
          text.required=false; editor.setDirty(false); preview();
        });
        instance.on('input change Undo Redo ExecCommand',()=>{
          if(settingContent || !selected || busy) return;
          queueMicrotask(()=>{if(!settingContent && !busy) synchronize();});
        });
      }
    }).catch(()=>{status('De opmaakeditor kon niet worden geladen. Je kunt de HTML hieronder blijven bewerken.');});
    return editorLoading;
  }
  const status=message=>{ $('#mail-template-status').textContent=message; };
  function discard() { return !busy && (!dirty || window.confirm('Je hebt een gewijzigde e-mailtekst die nog niet is opgeslagen. Toch doorgaan?')); }
  window.addEventListener('management-before-close',event=>{ if (!discard()) event.preventDefault(); });
  window.addEventListener('beforeunload',event=>{ if (dirty || busy) {event.preventDefault();event.returnValue='';} });
  async function request(data) {
    const response=await fetch('index2.php?action=templates',{credentials:'same-origin',cache:'no-store',...(data ? {method:'POST',headers:{'Content-Type':'application/json','X-CSRF-Token':$('meta[name="csrf-token"]').content},body:JSON.stringify(data)} : {})});
    const result=await response.json(); if(!response.ok || result.error) throw new Error(result.error || 'De e-mailteksten konden niet worden geladen.'); return result;
  }
  function synchronize() {
    if(!editor || !selected) return;
    text.value=editor.getContent(); dirty=text.value!==selected.text; preview();
  }
  function preview() {
    const html=editor ? editor.getContent() : text.value;
    const stylesheet=new URL('live2/mail-content.css',window.location.href).href;
    $('#mail-template-preview').srcdoc='<!doctype html><html><head><meta charset="utf-8"><link rel="stylesheet" href="'+stylesheet+'"></head><body>'+html+'</body></html>';
    $('#mail-template-tokens').textContent='Invulvelden: '+([...new Set(text.value.match(/\[[a-z][a-z0-9_]*\]/gi) || [])].join(', ') || 'geen');
  }
  function show(id) {
    selected=templates.find(item=>String(item.id)===String(id));
    select.value=String(selected.id); text.value=selected.text;
    if(editor) {settingContent=true;editor.setContent(text.value);editor.undoManager.clear();editor.setDirty(false);settingContent=false;} dirty=false; preview(); status(selected.missing ? 'Dit sjabloon ontbreekt in de database. Vul de tekst in en sla op om het aan te maken.' : '');
  }
  function disabled(value) { busy=value; select.disabled=value || !templates.length; text.disabled=value || !templates.length; save.disabled=value || !templates.length; reload.disabled=value; if(editor) editor.mode.set(value || !templates.length ? 'readonly' : 'design'); }
  async function load() {
    if(!discard()) return; disabled(true);
    try {
      const result=await request(); templates=result.templates;
      select.replaceChildren(...templates.map(item=>{const option=document.createElement('option');option.value=String(item.id);option.textContent=item.name+(item.missing ? ' (ontbreekt)' : '');return option;}));
      if(templates.length) show(selected && templates.some(item=>item.id===selected.id) ? selected.id : templates[0].id);
      else status('Er zijn geen e-mailsjablonen gevonden in de database.');
    } catch(error) {status(error.message);} finally {disabled(false);}
  }
  $('#users-templates-tab').addEventListener('click',async ()=>{if(!templates.length && !busy) await load();await ensureEditor();});
  reload.addEventListener('click',load);
  select.addEventListener('change',()=>{if(!discard()) {select.value=String(selected.id);return;} show(select.value);});
  text.addEventListener('input',()=>{dirty=text.value!==selected.text;preview();});
  form.addEventListener('submit',async event=>{
    event.preventDefault(); if(busy || !selected) return;
    if(editor && editor.isDirty()) synchronize();
    if(!text.value.trim()) {status('Vul eerst een e-mailtekst in.');return;} disabled(true); status('Tekst wordt opgeslagen…');
    try {
      const result=await request({id:selected.id,text:text.value,revision:selected.revision});
      Object.assign(selected,result.template);dirty=false;if(editor) editor.setDirty(false);status('E-mailtekst opgeslagen. Nieuwe mails in beide portalen gebruiken deze tekst.');
    } catch(error) {status(error.message);} finally {disabled(false);}
  });
})();
