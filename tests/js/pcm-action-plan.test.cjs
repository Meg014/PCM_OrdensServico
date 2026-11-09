const test=require('node:test'),assert=require('node:assert/strict'),vm=require('node:vm'),fs=require('node:fs');
const source=fs.readFileSync('webroot/js/pcm-action-plan.js','utf8');
function setup(fetcher){let submit,calls=0,clicked=0,removed=0;const label={textContent:'Baixar Plano de Ação'},spinner={classList:{add(){},remove(){}}},button={disabled:false,querySelector:key=>key.includes('label')?label:spinner};const status={textContent:'',classList:{add(){},remove(){}}};const form={action:'http://localhost/pcm/plano-de-acao/excel',reportValidity:()=>true,querySelector:()=>button,addEventListener:(_name,fn)=>submit=fn,setAttribute(){},removeAttribute(){}};
class Url extends URL{static createObjectURL(){return 'blob:test';}static revokeObjectURL(){}}
vm.runInNewContext(source,{document:{querySelector:key=>key.includes('form')?form:status,createElement:()=>({click(){clicked++;},remove(){removed++;}}),body:{appendChild(){}}},location:{href:'http://localhost/pcm/plano-de-acao'},URL:Url,URLSearchParams,FormData:class{*[Symbol.iterator](){yield ['date_start','2026-09-01'];yield ['date_end','2026-09-30'];yield ['unidade','factory'];}},fetch:async(...args)=>{calls++;return fetcher(...args);},requestAnimationFrame:()=>{},setTimeout:()=>{}});
return{button,label,status,form,submit:()=>submit({preventDefault(){}}),get calls(){return calls;},get clicked(){return clicked;},get removed(){return removed;}};}
const response=blob=>({ok:true,status:200,headers:{get:key=>key==='content-type'?'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet':'attachment; filename="plano.xlsx"'},blob});
test('button stays locked through the complete Blob and prevents duplicate requests while preserving filters',async()=>{
let resolve;const pending=new Promise(r=>resolve=r);let query;
const h=setup(async url=>{query=url.searchParams;return response(()=>pending);});const running=h.submit();
assert(h.button.disabled);assert.equal(h.label.textContent,'Gerando relatório...');await h.submit();assert.equal(h.calls,1);
await Promise.resolve();assert(h.button.disabled);assert.equal(h.clicked,0);
resolve({size:512});await running;assert(!h.button.disabled);assert.equal(h.clicked,1);assert.equal(h.removed,1);assert.equal(query.get('unidade'),'factory');assert.equal(query.get('date_start'),'2026-09-01');assert.match(h.status.textContent,/download foi iniciado/);
});
test('connection errors restore the button and allow a retry',async()=>{const h=setup(async()=>{throw Error('Falha de conexão');});await h.submit();assert(!h.button.disabled);assert.equal(h.clicked,0);assert.match(h.status.textContent,/Falha de conexão/);await h.submit();assert.equal(h.calls,2);});
test('HTML login redirects, generation failures, concurrent requests and empty files never download',async()=>{
for(const r of [{...response(async()=>({size:1})),redirected:true},{ok:false,status:503,headers:{get:()=> 'text/html'}},{status:409,headers:{get:()=> 'text/html'}},response(async()=>({size:0}))]){const h=setup(async()=>r);await h.submit();assert(!h.button.disabled);assert.equal(h.clicked,0);assert(h.status.textContent.length>0);}
});
