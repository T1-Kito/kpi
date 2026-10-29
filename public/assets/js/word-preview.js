(function () {
    const loading = new Map();
    async function ensureReady() {
        for (const [key,src] of [['JSZip','/assets/vendor/jszip.min.js'],['docx','/assets/vendor/docx-preview.min.js']]) {
            if (window[key]) continue;
            if (!loading.has(key)) {
                loading.set(key,new Promise((resolve,reject)=>{
                    const script=document.createElement('script');script.src=src;
                    script.onload=()=>window[key] ? resolve() : reject(new Error('Công cụ xem Word chưa sẵn sàng. Vui lòng thử lại.'));
                    script.onerror=()=>{script.remove();reject(new Error('Không tải được công cụ xem Word. Vui lòng thử lại.'));};
                    document.head.appendChild(script);
                }).catch(error=>{loading.delete(key);throw error;}));
            }
            await loading.get(key);
        }
    }
    async function alignPageImages(file, stage) {
        if (!window.JSZip || !stage?.isConnected) return;
        const zip = await window.JSZip.loadAsync(file);
        const part = zip.file('word/document.xml');
        if (!part) return;
        const xml = new DOMParser().parseFromString(await part.async('string'), 'application/xml');
        const wordNs = 'http://schemas.openxmlformats.org/wordprocessingml/2006/main';
        const drawingNs = 'http://schemas.openxmlformats.org/drawingml/2006/wordprocessingDrawing';
        const drawings = Array.from(xml.getElementsByTagNameNS(wordNs, 'drawing'));
        const images = Array.from(stage.querySelectorAll('section.docx article img'));
        if (drawings.length !== images.length) return;
        await Promise.all(images.map(img => img.decode?.().catch(() => {}) || Promise.resolve()));
        await stage.ownerDocument?.fonts?.ready;
        if (!stage.isConnected) return;
        drawings.forEach((drawing, index) => {
            const anchor = drawing.getElementsByTagNameNS(drawingNs, 'anchor')[0];
            if (!anchor) return;
            const image = images[index];
            const page = image.closest('section.docx');
            const box = image.parentElement;
            if (!page || !box || box.style.position !== 'relative' || box.style.width !== '0px') return;
            const scale = page.offsetWidth ? page.getBoundingClientRect().width / page.offsetWidth : 1;
            if (!(scale > 0)) return;
            for (const [axis, property, edge] of [['positionH', 'left', 'left'], ['positionV', 'top', 'top']]) {
                const position = anchor.getElementsByTagNameNS(drawingNs, axis)[0];
                const offset = position?.getElementsByTagNameNS(drawingNs, 'posOffset')[0];
                if (position?.getAttribute('relativeFrom') !== 'page' || !offset) continue;
                const emu = Number(offset.textContent);
                if (!Number.isFinite(emu)) continue;
                const desired = page.getBoundingClientRect()[edge] + emu / 914400 * 96 * scale;
                const shift = (desired - image.getBoundingClientRect()[edge]) / scale;
                if (Number.isFinite(shift)) box.style[property] = `calc(${box.style[property] || '0px'} + ${shift}px)`;
            }
        });
    }
    window.VKWordPreview = {alignPageImages,ensureReady};
})();
