const {test}=require('node:test');
const assert=require('node:assert/strict');
const fs=require('node:fs');
const vm=require('node:vm');
const source=fs.readFileSync('public/assets/js/layout.js','utf8');
function setup(){
    let cleared=0,reloaded=0,clicked;
    const content={innerHTML:'',querySelector:()=>({addEventListener:(_event,fn)=>clicked=fn})};
    const window={VKUser:{id:1},location:{href:'/dashboard',reload:()=>reloaded++}};
    const context=vm.createContext({window,clearUserCache(){},VKApi:{clearToken:()=>cleared++},document:{querySelector:()=>content}});
    vm.runInContext(source.slice(source.indexOf('function handleSessionFailure('),source.indexOf('function hydrateUser(')),context);
    return {context,window,content,cleared:()=>cleared,reloaded:()=>reloaded,retry:()=>clicked()};
}
test('only confirmed unauthorized session redirects and removes token',()=>{
    const state=setup();state.context.handleSessionFailure({status:401});
    assert.equal(state.cleared(),1);assert.equal(state.window.location.href,'/');
    assert.equal(state.window.VKUser,null);
});
for(const status of [undefined,403,500,503]) test(`session error ${status} keeps token and offers safe retry`,()=>{
    const state=setup();state.context.handleSessionFailure({status,message:'<script>raw secret error</script>'});
    assert.equal(state.cleared(),0);assert.equal(state.window.location.href,'/dashboard');
    assert.equal(state.window.VKUser,null);
    assert.match(state.content.innerHTML,/data-session-retry/);
    assert.doesNotMatch(state.content.innerHTML,/raw secret|<script>/);
    state.retry();assert.equal(state.reloaded(),1);
});
