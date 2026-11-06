const test=require('node:test'),assert=require('node:assert/strict'),fs=require('node:fs'),vm=require('node:vm');
const source=fs.readFileSync('webroot/js/pcm-nonconformities.js','utf8');

test('inline suggestions fill the registered email and clear obsolete recipients when a new name is typed',()=>{
 const handlers={}; const id={value:''}; const email={value:'',setCustomValidity(message){this.message=message;}};
 const options=[{value:'José Ávila',dataset:{id:'7',email:'jose@example.test'}},{value:'Maria',dataset:{id:'8',email:'maria@example.test'}}];
 const picker={querySelectorAll:()=>options,querySelector:key=>({'[data-nc-contact-id]':id,'[data-nc-contact-email]':email}[key])};
 vm.runInNewContext(source,{document:{querySelector:()=>null,addEventListener:(key,fn)=>(handlers[key]??=[]).push(fn)}});
 const name={value:'  JOSE   AVILA ',matches:key=>key==='[data-nc-contact-name]',closest:()=>picker};
 handlers.input.forEach(fn=>fn({target:name})); assert.equal(id.value,'7');assert.equal(email.value,'jose@example.test');
 name.value='Maria';handlers.input.forEach(fn=>fn({target:name}));assert.equal(id.value,'8');assert.equal(email.value,'maria@example.test');
 name.value='Nova pessoa';handlers.input.forEach(fn=>fn({target:name}));assert.equal(id.value,'');assert.equal(email.value,'');assert.equal(email.message,'');
});
test('responsible email shows clear validation and clears the message when corrected',()=>{
 const handlers={};vm.runInNewContext(source,{document:{querySelector:()=>null,addEventListener:(key,fn)=>(handlers[key]??=[]).push(fn)}});
 const input={value:'',matches:s=>s==='[data-nc-required-email]',setCustomValidity(message){this.message=message;}};
 handlers.invalid.forEach(fn=>fn({target:input}));assert.match(input.message,/Informe o e-mail/);
 input.value='incorreto';handlers.invalid.forEach(fn=>fn({target:input}));assert.match(input.message,/endereço de e-mail válido/);
 input.value='nome@example.test';handlers.input.forEach(fn=>fn({target:input}));assert.equal(input.message,'');
});
test('responsible search ignores accents and keeps the current linked selection available',()=>{
 const handlers={}; const options=[{value:'',textContent:'Selecione'},{value:'1',textContent:'José Ávila',selected:false},{value:'2',textContent:'Maria',selected:false},{value:'3',textContent:'Outro atual',selected:true}];
 vm.runInNewContext(source,{document:{querySelector:()=>null,addEventListener:(key,fn)=>handlers[key]=fn}});
 const input={value:'JOSE',matches:()=>true,closest:()=>({querySelector:()=>({options})})};
 handlers.input({target:input}); assert.equal(options[1].hidden,false);assert.equal(options[2].hidden,true);assert.equal(options[3].hidden,false);
 input.value='';handlers.input({target:input});assert.equal(options[2].hidden,false);
});
function quickHarness(reply){const handlers={};const status={textContent:'',classList:{add(){},remove(){}}};const button={disabled:false};const badge={};const revision={value:'4'};const row={dataset:{},querySelector:key=>({'[data-nc-save-status]':status,'button[type=submit]':button,'[data-nc-deadline]':badge}[key])};const quick={dataset:{},action:'/save',closest:()=>row,querySelector:()=>revision};
 vm.runInNewContext(source,{document:{querySelector:()=>null,addEventListener:(event,fn)=>handlers[event]=fn},FormData:class{},fetch:reply,Error});
 return {status,button,badge,revision,row,quick,submit:()=>handlers.submit({target:{closest:()=>quick},preventDefault(){}})};
}
test('quick edit updates revision and deadline badge after confirmed server save',async()=>{const h=quickHarness(async()=>({ok:true,redirected:false,headers:{get:()=> 'application/json'},json:async()=>({revision:5,tone:'urgent',deadline:'Vence em 1 dia(s)'})}));await h.submit();assert.equal(h.revision.value,'5');assert.equal(h.status.textContent,'Alterações salvas.');assert.equal(h.row.dataset.deadlineTone,'urgent');assert.equal(h.button.disabled,false);});
test('validation and stale revision errors preserve the editing revision and release button',async()=>{const h=quickHarness(async()=>({ok:false,redirected:false,headers:{get:()=> 'application/json'},json:async()=>({message:'Dados inválidos. Recarregue.'})}));await h.submit();assert.equal(h.revision.value,'4');assert.equal(h.status.textContent,'Dados inválidos. Recarregue.');assert.equal(h.button.disabled,false);});
test('expired session cannot replace table content or claim successful save',async()=>{const h=quickHarness(async()=>({ok:true,redirected:true,headers:{get:()=> 'text/html'}}));await h.submit();assert.equal(h.revision.value,'4');assert.match(h.status.textContent,/sessão/);});
test('adding an item generates distinct field names without submitting the form',()=>{const handlers={};let html='',prevented=false,indexCount=1;const items={dataset:{nextIndex:'1'},querySelectorAll:()=>Array(indexCount),insertAdjacentHTML:(_where,value)=>{html=value;indexCount++;},lastElementChild:{querySelector:()=>({focus(){}})},addEventListener:(name,fn)=>handlers[name]=fn};const add={addEventListener:(_name,fn)=>handlers.add=fn};const form={querySelector:key=>({'[data-nc-items]':items,'[data-nc-item-template]':{innerHTML:'<input name="actions[__INDEX__][item]" id="item-__INDEX__">'},'[data-add-nc-item]':add}[key])};vm.runInNewContext(source,{document:{querySelector:()=>form,addEventListener(){}}});handlers.add({preventDefault(){prevented=true;}});assert(prevented);assert.match(html,/actions\[1\]/);handlers.add({preventDefault(){}});assert.match(html,/actions\[2\]/);assert.equal(items.dataset.nextIndex,'3');});
test('oversized complete forms stay on screen rather than reaching PHP partially',async()=>{const handlers={};const status={textContent:''};let prevented=false;const items={addEventListener(){}};const form={dataset:{maxFields:'12'},querySelector:key=>({'[data-nc-items]':items,'[data-nc-item-template]':{},'[data-add-nc-item]':{addEventListener(){}},'[data-nc-form-status]':status}[key])};vm.runInNewContext(source,{document:{querySelector:()=>form,addEventListener:(key,fn)=>handlers[key]=fn},FormData:class{keys(){return ['a','b','c'][Symbol.iterator]();}}});await handlers.submit({target:form,preventDefault(){prevented=true;}});assert(prevented);assert.match(status.textContent,/dados permanecem/);});
