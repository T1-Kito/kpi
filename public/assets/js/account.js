(function () {
    let avatar = null;
    const escape = value => VKTable.escapeHtml(value || '');
    function paintAvatar(value) {
        avatar = value;
        const box = document.querySelector('#userLine .user-avatar');
        if (box && value) box.innerHTML = `<img src="${escape(value)}" alt="">`;
    }
    document.addEventListener('vk:ready', async () => {
        try { const result = await VKApi.request('/me/avatar'); paintAvatar(result.data.avatar); } catch (_) {}
    });
    document.addEventListener('vk:user-hydrated', () => { if (avatar) paintAvatar(avatar); });
    document.addEventListener('click', async event => {
        const button = event.target.closest('[data-account-open]');
        if (!button || button.disabled) return;
        button.disabled = true;
        try {
            const {data:user} = await VKApi.request('/me', {cache:false});
            VKModal.open('Tài khoản cá nhân', `
                <section class="account-identity"><div class="account-photo">${avatar ? `<img src="${escape(avatar)}" alt="Ảnh đại diện">` : escape(user.name?.trim().charAt(0).toUpperCase())}</div><div><h3>${escape(user.name)}</h3><p>${escape(user.email)}</p><label class="btn secondary small account-photo-picker">Đổi ảnh đại diện<input type="file" data-account-avatar accept="image/jpeg,image/png,image/webp"></label><small>JPG, PNG, WebP · Tối đa 1 MB</small></div></section>
                <section class="account-password"><h3>Đổi mật khẩu</h3><p>Dùng mật khẩu riêng để bảo vệ tài khoản của bạn.</p>
                <label class="field"><span>Mật khẩu hiện tại</span><input name="current_password" type="password" autocomplete="current-password" required></label>
                <div class="account-password-grid"><label class="field"><span>Mật khẩu mới</span><input name="password" type="password" minlength="8" maxlength="128" autocomplete="new-password" required placeholder="Tối thiểu 8 ký tự"></label><label class="field"><span>Nhập lại mật khẩu mới</span><input name="password_confirmation" type="password" minlength="8" maxlength="128" autocomplete="new-password" required></label></div>
                <p class="account-session-note">Sau khi đổi mật khẩu, bạn cần đăng nhập lại trên các thiết bị.</p></section>`, async form => {
                    const values = Object.fromEntries(new FormData(form));
                    if (values.password !== values.password_confirmation) throw new Error('Hai lần nhập mật khẩu mới chưa khớp.');
                    await VKApi.request('/me/password', {method:'POST',body:JSON.stringify(values)});
                    VKApi.clearToken();
                    window.location.href = '/';
                }, {className:'account-modal',submitText:'Đổi mật khẩu',cancelText:'Đóng'});
        } catch(error) { VKModal.toast(error.message,'danger'); }
        finally { button.disabled = false; }
    });
    document.addEventListener('change', async event => {
        if (!event.target.matches('[data-account-avatar]')) return;
        const file = event.target.files?.[0]; if (!file) return;
        if (!['image/jpeg','image/png','image/webp'].includes(file.type) || file.size > 1024*1024) {
            VKModal.toast('Chọn ảnh JPG, PNG hoặc WebP, tối đa 1 MB.','danger');event.target.value='';return;
        }
        const input = event.target; input.disabled = true;
        try {
            const body = new FormData();body.append('avatar',file);
            const {data} = await VKApi.request('/me/avatar',{method:'POST',body});
            paintAvatar(data.avatar);
            const photo = document.querySelector('.account-photo');
            if(photo) photo.innerHTML = `<img src="${escape(data.avatar)}" alt="Ảnh đại diện">`;
            VKModal.toast('Đã cập nhật ảnh đại diện.');
        } catch(error) { VKModal.toast(error.message,'danger'); }
        finally { input.disabled=false;input.value=''; }
    });
})();
