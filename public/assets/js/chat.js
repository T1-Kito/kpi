(function(){
let rows=[],active=null,busy=false,version=0,lastTotal=null,pending=null;
let filter='all';
function renderInfo(c){if(!root())return;root().querySelector('[data-chat-info-name]').textContent=c?.title||'Không gian nội bộ';root().querySelector('[data-chat-info-avatar]').textContent=c?.title?.slice(0,2).toUpperCase()||'CRM';root().querySelector('[data-chat-info-description]').textContent=c?(c.direct_key?'Hội thoại cá nhân':`Nhóm · ${c.members.length} thành viên`):'Nhắn riêng hoặc trao đổi nhóm';root().querySelector('[data-chat-members]').innerHTML=c?c.members.map(m=>`<div class="chat-member-row"><span>${esc(m.name.slice(0,2).toUpperCase())}</span><div><b>${esc(m.name)}</b><small>${m.id===window.VKUser.id?'Bạn':(m.is_active?'Tài khoản hoạt động':'Tài khoản đã khóa')}</small></div></div>`).join(''):'<p class="chat-info-empty">Chọn hội thoại để xem thành viên.</p>';}
const drafts=new Map();
const root=()=>document.getElementById('chatRoot'),esc=v=>VKTable.escapeHtml(String(v??''));
const status=s=>{const n=root()?.querySelector('[data-chat-status]');if(n)n.textContent=s;};
function updateChatHeader(){const c=rows.find(c=>c.id===active),page=root();if(!page)return;const avatar=page.querySelector('.chat-header-dot');avatar.textContent=c?c.title.slice(0,2).toUpperCase():'';if(c){renderInfo(c);page.querySelector('#chatBody').placeholder=`Tin nhắn tới ${c.title}…`;}}
function list(){
    if(!root())return;
    const term=root().querySelector('[data-chat-search]').value.toLowerCase();
    root().querySelector('[data-chat-count]').textContent=rows.length;
    const visible=rows.filter(c=>c.title.toLowerCase().includes(term)&&(filter!=='unread'||c.unread>0)&&(filter!=='groups'||!c.direct_key)&&(filter!=='direct'||Boolean(c.direct_key)));
    root().querySelector('[data-chat-list]').innerHTML=visible.map(c=>`<button class="chat-conversation ${active===c.id?'selected':''}" data-chat-open="${c.id}"><span class="chat-avatar ${c.direct_key?'':'group'}">${esc(c.title.trim().slice(0,2).toUpperCase())}</span><span class="chat-preview"><span class="chat-preview-head"><b>${esc(c.title)}</b><time>${esc(new Date(c.updated_at).toLocaleTimeString('vi-VN',{hour:'2-digit',minute:'2-digit'}))}</time></span><small>${esc(c.last_message||'Bắt đầu trò chuyện')}</small></span>${c.unread?`<em>${c.unread}</em>`:''}</button>`).join('')||'<div class="chat-list-empty">Chưa có hội thoại</div>';
}
async function messages(){const id=active,page=root(),turn=++version;if(!page||!id)return;const result=await VKApi.request(`/chat/${id}/messages`,{cache:false});if(page!==root()||id!==active||turn!==version)return;const h=page.querySelector('[data-chat-messages]'),bottom=h.scrollHeight-h.scrollTop-h.clientHeight<100;const html=(result.meta.has_more?'<p class="chat-empty">Đang hiển thị 50 tin nhắn mới nhất.</p>':'')+result.data.map(m=>`<article class="chat-message ${m.sender_id===window.VKUser.id?'mine':''}"><small>${esc(m.sender_name)} · ${esc(new Date(m.created_at).toLocaleString('vi-VN'))}</small><div>${esc(m.body)}</div></article>`).join('');if(h.dataset.content!==html){h.innerHTML=html||'<p class="chat-empty">Gửi lời chào đầu tiên nhé.</p>';h.dataset.content=html;if(bottom)h.scrollTop=h.scrollHeight;}if(result.data.length&&bottom&&!document.hidden)await VKApi.request(`/chat/${id}/read`,{method:'POST',body:JSON.stringify({message_id:result.data.at(-1).id})});status('Nội bộ doanh nghiệp · Tự cập nhật mỗi 5 giây');}
async function refresh(){if(busy||document.hidden||!window.VKUser)return;busy=true;try{rows=(await VKApi.request('/chat',{cache:false})).data;const total=rows.reduce((n,c)=>n+c.unread,0);document.querySelectorAll('[data-chat-badge]').forEach(n=>{n.textContent=total;n.hidden=!total;});if(lastTotal!==null&&total>lastTotal&&!root())VKModal.toast('Có tin nhắn nội bộ mới. Mở Tin nhắn để xem.');lastTotal=total;list();if(root()&&active)await messages();}catch(e){status('Chưa cập nhật được. Đang giữ nội dung gần nhất.');}finally{busy=false;}}
async function open(id){const page=root();if(active)drafts.set(active,page.querySelector('#chatBody').value);active=id;version++;const c=rows.find(c=>c.id===id);page.querySelector('[data-chat-title]').textContent=c?.title||'Hội thoại';page.querySelector('[data-chat-subtitle]').textContent=c?.direct_key?'Trao đổi riêng · Nội bộ':`${c?.members?.length||0} thành viên · Nhóm nội bộ`;const h=page.querySelector('[data-chat-messages]');h.innerHTML='';h.dataset.content='';page.querySelector('[data-chat-compose]').hidden=false;page.querySelector('#chatBody').value=drafts.get(id)||'';list();await messages();}
async function create(){
    try{
        const users=(await VKApi.request('/chat/users',{cache:false})).data;
        if(!users.length)return VKModal.toast('Chưa có đồng nghiệp hoạt động. Tạo tài khoản nhân sự trước.','warning');
        let mode='direct',creating=false;
        const selected=new Set();
        VKModal.open('Tin nhắn mới',`<div class="chat-create-tabs"><button type="button" data-create-mode="direct" aria-pressed="true">Nhắn riêng</button><button type="button" data-create-mode="group" aria-pressed="false">Tạo nhóm</button></div><div data-group-name hidden>${VKModal.field('chat_name','Tên nhóm')}</div><input class="chat-user-search" type="search" placeholder="Tìm đồng nghiệp…" aria-label="Tìm đồng nghiệp"><div class="chat-user-list"></div>`,async form=>{
            if(mode!=='group'||creating)return;
            if(selected.size<2)throw new Error('Chọn ít nhất hai đồng nghiệp để tạo nhóm.');
            await start([...selected],form.elements.chat_name.value);
        },{submitText:'Tạo nhóm',hideSubmit:true,className:'chat-create-modal'});
        const form=document.getElementById('modalForm');
        async function start(ids,name=''){
            if(creating)return;creating=true;
            try{const response=await VKApi.request('/chat',{method:'POST',body:JSON.stringify({name,user_ids:ids})});VKModal.close();await refresh();await open(response.data.id);renderInfo(rows.find(c=>c.id===active));}
            finally{creating=false;}
        }
        function render(){
            const term=form.querySelector('.chat-user-search').value.trim().toLocaleLowerCase('vi');
            form.querySelector('.chat-user-list').innerHTML=users.filter(u=>u.name.toLocaleLowerCase('vi').includes(term)).map(u=>`<button type="button" class="chat-user-choice ${selected.has(u.id)?'chosen':''}" data-chat-user="${u.id}"><span class="chat-avatar">${esc(u.name.slice(0,2).toUpperCase())}</span><b>${esc(u.name)}</b>${mode==='group'?`<span class="chat-choice-check">${selected.has(u.id)?'✓':''}</span>`:''}</button>`).join('')||'<p class="chat-empty">Không tìm thấy đồng nghiệp.</p>';
        }
        form.querySelector('.chat-user-search').addEventListener('input',render);
        form.addEventListener('click',async event=>{
            const tab=event.target.closest('[data-create-mode]');
            if(tab){mode=tab.dataset.createMode;form.querySelectorAll('[data-create-mode]').forEach(n=>n.setAttribute('aria-pressed',String(n===tab)));form.querySelector('[data-group-name]').hidden=mode!=='group';document.getElementById('modalSubmit').hidden=mode!=='group';render();}
            const user=event.target.closest('[data-chat-user]');if(!user)return;const id=Number(user.dataset.chatUser);
            if(mode==='group'){selected.has(id)?selected.delete(id):selected.add(id);render();}
            else{try{user.disabled=true;await start([id]);}catch(error){VKModal.toast(error.message,'danger');user.disabled=false;}}
        });render();
    }catch(e){VKModal.toast(e.message,'danger');}
}
document.addEventListener('click',e=>{if(!root())return;if(e.target.closest('[data-chat-new]'))create();const f=e.target.closest('[data-chat-filter]');if(f){filter=f.dataset.chatFilter;root().querySelectorAll('[data-chat-filter]').forEach(n=>n.setAttribute('aria-pressed',String(n===f)));list();}const b=e.target.closest('[data-chat-open]');if(b)open(Number(b.dataset.chatOpen)).catch(e=>status(e.message));});
document.addEventListener('input',e=>{if(e.target.matches('[data-chat-search]'))list();});
let chatComposing=false;
document.addEventListener('compositionstart',e=>{if(e.target.matches('#chatBody'))chatComposing=true;});
document.addEventListener('compositionend',e=>{if(e.target.matches('#chatBody'))chatComposing=false;});
document.addEventListener('keydown',e=>{
    if(!e.target.matches('#chatBody')||e.key!=='Enter'||e.shiftKey||e.isComposing||chatComposing||e.keyCode===229)return;
    e.preventDefault();
    if(e.repeat)return;
    const form=e.target.closest('[data-chat-compose]');
    if(!form||form.hidden||form.querySelector('button[type="submit"]').disabled||!e.target.value.trim())return;
    form.requestSubmit();
});
document.addEventListener('click',e=>{if(!root())return;if(e.target.closest('[data-chat-info-toggle]')){const closed=root().classList.toggle('info-closed');root().querySelector('[data-chat-info-toggle]').setAttribute('aria-expanded',String(!closed));}if(e.target.closest('[data-chat-open]'))updateChatHeader();});
document.addEventListener('submit',async e=>{if(!e.target.matches('[data-chat-compose]'))return;e.preventDefault();const form=e.target,body=form.querySelector('textarea').value.trim(),id=active,b=form.querySelector('button');if(!body||b.disabled)return;b.disabled=true;if(!pending||pending.id!==id||pending.body!==body)pending={id,body,client_id:crypto.randomUUID()};try{await VKApi.request(`/chat/${id}/messages`,{method:'POST',body:JSON.stringify(pending)});pending=null;if(root()&&active===id){form.querySelector('textarea').value='';await messages();const h=root().querySelector('[data-chat-messages]');h.scrollTop=h.scrollHeight;}await refresh();}catch(e){status('Gửi chưa thành công: '+e.message+'. Nội dung được giữ để thử lại.');}finally{b.disabled=false;}});
document.addEventListener('vk:ready',refresh);document.addEventListener('vk:page:changed',()=>{active=null;version++;refresh();});document.addEventListener('visibilitychange',()=>{if(!document.hidden)refresh();});setInterval(refresh,5000);
})();
