(function () {
    const escape = value => VKTable.escapeHtml(String(value ?? ''));
    function download(blob, name) {
        const url = URL.createObjectURL(blob);
        const link = document.createElement('a');
        link.href = url; link.download = name; document.body.appendChild(link); link.click(); link.remove();
        setTimeout(() => URL.revokeObjectURL(url), 1000);
    }
    async function open({module, endpoint, title, filename, archived = false}) {
        try {
            const response = archived ? {data: []} : await VKApi.request(`/print-templates/choices?module=${encodeURIComponent(module)}`, {cache: false});
            const templates = (response.data || []).filter(t => t.id && t.file_path && /\.docx$/i.test(t.file_name || t.file_path));
            if (!archived && !templates.length) {
                VKModal.notice({type:'warning', title:'Chưa có mẫu Word', message:`Chưa có mẫu ${title} đang dùng. Vào Quản trị → Mẫu in để tải file .docx lên.`});
                return;
            }
            const selected = templates.find(t => t.is_default) || templates[0];
            VKModal.open(`Xuất ${title}`, `<div style="display:flex;align-items:flex-end;gap:12px"><div class="field" style="flex:1;min-width:0;margin:0"><label>Mẫu xuất</label><select name="template_id" data-document-template ${archived ? 'disabled' : ''}>${archived ? '<option>Bản đã phát hành</option>' : templates.map(t => `<option value="${escape(t.id)}" ${t.id === selected.id ? 'selected' : ''}>${escape(t.name)}${t.is_default ? ' — Mặc định' : ''}</option>`).join('')}</select></div><button class="btn" style="flex-shrink:0;align-self:flex-end" type="button" data-document-preview>Xem trước</button></div><div data-document-preview-host style="margin-top:12px"></div>`, async form => {
                const blob = await fetchDocument(form);
                download(blob, filename);
                VKModal.toast('Đã tải file Word.', 'success');
            }, {submitText:'Tải Word', cancelText:'Đóng', className:'modal-wide'});
            const host = document.querySelector('[data-document-preview-host]');
            const form = host.closest('form');
            let cached = null;
            let generation = 0;
            async function fetchDocument(targetForm) {
                const id = archived ? '' : targetForm.querySelector('[data-document-template]').value;
                if (cached?.id === id) return cached.blob;
                const result = await fetch(endpoint + (id ? `?template_id=${encodeURIComponent(id)}` : ''), {headers:{Accept:'application/vnd.openxmlformats-officedocument.wordprocessingml.document', Authorization:`Bearer ${VKApi.token()}`}});
                if (!result.ok) throw new Error(await result.json().then(b => b.message).catch(() => 'Không thể xuất file Word.'));
                const blob = await result.blob(); cached = {id, blob}; return blob;
            }
            form.querySelector('[data-document-template]').addEventListener('change', () => {generation++; cached = null; host.replaceChildren();});
            form.querySelector('[data-document-preview]').addEventListener('click', async event => {
                const button = event.currentTarget; button.disabled = true;
                const ticket = ++generation;
                host.textContent = 'Đang tạo bản xem trước…';
                try {
                    const blob = await fetchDocument(form);
                    await window.VKWordPreview.ensureReady();
                    if (!host.isConnected || ticket !== generation) return;
                    const frame = document.createElement('iframe');
                    frame.title = `Xem trước ${title}`; frame.style.cssText = 'width:100%;height:65vh;border:1px solid #dce4ef;border-radius:8px;background:white';
                    host.replaceChildren(frame);
                    const doc = frame.contentDocument; doc.open(); doc.write('<!doctype html><html><head><meta charset="utf-8"></head><body></body></html>'); doc.close();
                    await window.docx.renderAsync(blob, doc.body, null, {inWrapper:true, breakPages:true});
                    await window.VKWordPreview.alignPageImages(blob, doc.body);
                } catch (error) {if (host.isConnected && ticket === generation) host.textContent = error.message || 'Không xem trước được file Word.';}
                finally {button.disabled = false;}
            });
        } catch (error) {VKModal.toast(error.message || 'Không tải được danh sách mẫu.', 'danger');}
    }
    window.VKDocumentExport = {open};
})();
