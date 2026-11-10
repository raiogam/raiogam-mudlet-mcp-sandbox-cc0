'use strict';
// Rendering and form-contract checks; no browser or third-party dependencies.
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
const assert = require('node:assert/strict');
const root = path.resolve(__dirname, '..');
const seed = JSON.parse(fs.readFileSync(path.join(root, 'config/seed.json'), 'utf8'));
const nodes = new Map();
function makeNode() {
  return {innerHTML:'', textContent:'', className:'', classList:{remove(){},toggle(){}},
    addEventListener(){}, focus(){}, showModal(){this.open=true;}, close(){this.open=false;}};
}
const context = {
  document:{querySelector(selector){if(!nodes.has(selector))nodes.set(selector,makeNode());return nodes.get(selector);},addEventListener(){}},
  window:{addEventListener(){},scrollTo(){}}, location:{search:'',pathname:'/'}, history:{replaceState(){}},
  URL, URLSearchParams, Intl, Date, console, setTimeout, clearTimeout, navigator:{},
  fetch:async()=>{throw new Error('Unexpected network request in rendering checks');}
};
vm.createContext(context);
const script = fs.readFileSync(path.join(root,'public/assets/app.js'),'utf8').replace('readLocation();load();','');
vm.runInContext(script + ';globalThis.qa={state,renderView,issueDetail,issueForm,runForm,integrationForm,availabilityForm,safeUrl,sourceMethodsDetail,localValidation};',context);
const qa=context.qa;
Object.assign(qa.state,JSON.parse(JSON.stringify(seed)));
for(const view of ['overview','issues','runs','integration','compare','availability','activity']){
  qa.state.view=view;
  const html=qa.renderView();
  assert(html.length>200,`${view} should render substantive content`);
  assert(!html.includes('undefined'),`${view} has an undefined value`);
}
qa.state.issues.push({id:99999,title:'<img src=x onerror=alert(1)>',summary:'<script>alert(1)</script>',risk:'medium',status:'open'});
qa.state.view='issues';
assert(!qa.renderView().includes('<img src=x'),'Issue title must be escaped');
assert(!qa.renderView().includes('<script>alert'),'Issue summary must be escaped');
qa.state.issues=Array.from({length:12000},(_,i)=>({id:i+1,title:'Issue '+(i+1),risk:'medium',status:'open'}));
qa.state.page=1;
const inventory=qa.renderView();
assert.equal((inventory.match(/<tr>/g)||[]).length,51,'Inventory must render only 50 issue rows');
assert(inventory.includes('Página 1 de 240'),'Inventory must expose all result pages');
qa.runForm();
const runForm=nodes.get('#modal-content').innerHTML;
assert((runForm.match(/<option value=/g)||[]).length<150,'Issue suggestions must remain bounded');
for(const field of ['issue_id','commit_sha','method_hash','source_method','os','mode','endpoint','protocol_version','tools','safe_lua','assertions_passed','assertions_failed','reproduction','e2e_coverage','build_url']){
  assert(runForm.includes(`name="${field}"`),`Missing run field: ${field}`);
}
qa.issueForm({id:42,title:'Unknown risk',risk:'unknown',fix_status:'closed_upstream',status:'closed'});
const issueForm=nodes.get('#modal-content').innerHTML;
assert(issueForm.includes('value="unknown" selected'),'Unassessed risk must remain unassessed when editing');
assert(issueForm.includes('value="closed_upstream" selected'),'Existing upstream fix state must be preserved');
qa.availabilityForm(seed.availability[0]);
for(const field of ['name','evidence_type','install_url','source_url','checked_at']){
  assert(nodes.get('#modal-content').innerHTML.includes(`name="${field}"`),`Missing availability evidence: ${field}`);
}
qa.integrationForm(seed.integration_tests[0].id);
assert(nodes.get('#modal-content').innerHTML.includes('name="run_ids"'),'Integration evidence needs linked run IDs');
const method=seed.issues.flatMap(i=>i.source_methods||[]).find(m=>m.method_sha256||m.hash);
if(method)assert(qa.sourceMethodsDetail([method]).includes(method.hash||method.method_sha256),'Source method hash must be inspectable');
// An old verified E2E result must never hide a newer commit's regression.
const oldPass={id:'old-pass',issue_id:1,commit_sha:'a'.repeat(40),scope:'e2e',mode:'live',status:'passed',verified:true,created_at:'2026-11-09T12:00:00Z'};
const newFailure={id:'new-failure',issue_id:1,commit_sha:'b'.repeat(40),scope:'parser',mode:'live',status:'failed',verified:false,created_at:'2026-11-10T12:00:00Z'};
qa.state.runs=[oldPass,newFailure];
assert(qa.localValidation({id:1}).includes('Falha neste commit'),'Newest commit regression must have priority');
assert(!qa.localValidation({id:1}).includes('pill green'),'Old E2E cannot mark a newer failing commit green');
qa.state.runs=[oldPass,{...newFailure,created_at:oldPass.created_at}];
assert(qa.localValidation({id:1}).includes('Falha neste commit'),'Insertion order must resolve equal timestamps');
qa.state.runs=[newFailure,{...oldPass,commit_sha:'c'.repeat(40),created_at:'2026-11-11T12:00:00Z'}];
assert(qa.localValidation({id:1}).includes('E2E neste commit'),'E2E badge must describe the newest commit');
assert.equal(qa.safeUrl('javascript:alert(1)'),'#');
assert.equal(qa.safeUrl('http://example.test'),'#');
assert.equal(qa.safeUrl('https://github.com/Mudlet/Mudlet'),'https://github.com/Mudlet/Mudlet');
console.log('Frontend OK: 7 views; escaped content; 12,000-issue pagination; bounded forms; evidence contracts; current-commit regression badges.');
