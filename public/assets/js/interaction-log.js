(function(){
    const queue=[];let sending=false,lastPage='',lastClick='',lastAt=0;
    const allowed=/^\/[a-z0-9/-]*$/;
    const clean=path=>String(path || '').split('?')[0].split('#')[0];
    function record(kind,path,control){
        path=clean(path);
        if(!window.VKApi?.token() || !allowed.test(path) || /audit-logs|interaction-logs|auth|password|chat/.test(path))return;
        if(queue.length>=100)queue.shift();
        queue.push({kind,path,control:control || null,at:new Date().toISOString()});
    }
    async function flush(){
        if(sending || !queue.length || !window.VKApi?.token())return;
        sending=true;const events=queue.splice(0,20);
        try { await fetch('/api/v1/interaction-logs',{method:'POST',keepalive:true,headers:{'Content-Type':'application/json','Accept':'application/json','Authorization':`Bearer ${VKApi.token()}`},body:JSON.stringify({events})}); }
        catch(_) { /* Telemetry must never interrupt business actions or expose errors. */ }
        finally { sending=false; }
    }
    document.addEventListener('vk:ready',()=>{const path=clean(location.pathname);if(path!==lastPage){lastPage=path;record('page',path);}});
    document.addEventListener('click',event=>{
        const node=event.target.closest?.('button,a[href],summary');if(!node || node.disabled)return;
        let control='';
        for(const attr of node.attributes || [])if(/^data-[a-z0-9-]+$/.test(attr.name) && !/modal-close|permission/.test(attr.name)){control=attr.name.slice(5);break;}
        if(!control && node.tagName==='A')control='navigate';
        if(!control && node.tagName==='SUMMARY')control='expand-options';
        if(!control && node.id==='modalSubmit')control='submit-form';
        if(!control)return;
        const key=location.pathname+control;if(key===lastClick && Date.now()-lastAt<350)return;
        lastClick=key;lastAt=Date.now();record('click',location.pathname,control);
    },true);
    window.VKInteractionLog={response:(path,ok)=>record(ok?'request_success':'request_error',path,'server-response')};
    setInterval(flush,4000);
    document.addEventListener('visibilitychange',()=>{if(document.hidden)flush();});
    window.addEventListener('pagehide',flush);
})();
