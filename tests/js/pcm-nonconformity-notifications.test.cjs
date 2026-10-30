const test=require('node:test');const assert=require('node:assert/strict');const fs=require('node:fs');const vm=require('node:vm');
const source=fs.readFileSync('webroot/js/pcm-nonconformity-notifications.js','utf8');
function fixture(response={ok:true,redirected:false,headers:{get:()=> 'text/html'},text:async()=>'<details>Atualizado</details>'}){
 let timer,calls=0,opened=false;const events={};
 const container={dataset:{url:'/pcm/nao-conformidades/notificacoes'},innerHTML:'Original',querySelector:selector=>selector==='details[open]'?(opened?{}:null):{removeAttribute:()=>{opened=false;}},contains:()=>false};
 const document={hidden:false,querySelector:()=>container,addEventListener:(type,fn)=>events[type]=fn};
 vm.runInNewContext(source,{document,setInterval:(fn)=>timer=fn,fetch:async()=>{calls++;if(response instanceof Error)throw response;return response;},AbortSignal:{timeout:()=>({})}});
 return {container,document,events,refresh:()=>timer(),calls:()=>calls,setOpened:v=>opened=v};
}
test('bell refreshes same-origin markup and keeps open menu stable',async()=>{const f=fixture();await f.refresh();assert.equal(f.calls(),1);assert.equal(f.container.innerHTML,'<details>Atualizado</details>');f.setOpened(true);await f.refresh();assert.equal(f.calls(),1);f.events.keydown({key:'Escape'});await f.refresh();assert.equal(f.calls(),2);});
test('bell preserves content on login redirects and network errors',async()=>{for(const response of [new Error('offline'),{ok:true,redirected:true,headers:{get:()=> 'text/html'}}]){const f=fixture(response);await f.refresh();assert.equal(f.container.innerHTML,'Original');}});
test('hidden pages do not poll',async()=>{const f=fixture();f.document.hidden=true;await f.refresh();assert.equal(f.calls(),0);});
