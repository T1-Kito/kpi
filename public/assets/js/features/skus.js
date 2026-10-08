(function () {
let skuState = { rows: [], categories: [], brands: [], q: '', status: '' };

document.addEventListener('vk:ready', () => loadSkus());
document.addEventListener('input', (event) => {
    if (!document.getElementById('skusRoot')) return;
    if (event.target.matches('[data-list-search]')) {
        skuState.q = event.target.value.toLowerCase();
        renderSkus();
    }
    if (event.target.matches('[data-money-input]')) {
        formatSkuMoneyInput(event.target);
    }
});
document.addEventListener('change', (event) => {
    if (!document.getElementById('skusRoot')) return;
    if (event.target.matches('[data-list-status]')) {
        skuState.status = event.target.value;
        renderSkus();
    }
});
document.addEventListener('click', (event) => {
    if (!document.getElementById('skusRoot')) return;
    if (event.target.matches('[data-create-sku]')) openSkuModal();
    if (event.target.matches('[data-edit-sku]')) {
        event.stopPropagation();
        openSkuModal(Number(event.target.dataset.editSku));
    }
    if (event.target.closest('.row-action-menu') && !event.target.closest('[data-sku-detail]')) return;
    const detail = event.target.closest('[data-sku-detail], tr[data-row-detail]');
    if (detail) openSkuDetail(detail.dataset.skuDetail || detail.dataset.rowDetail);
});

async function loadSkus() {
    if (!document.getElementById('skusRoot')) return;
    const [skus, categories, brands] = await Promise.all([VKApi.request('/skus'), VKApi.request('/product-categories'), VKApi.request('/product-brands')]);
    skuState.rows = skus.data || [];
    skuState.categories = categories.data || [];
    skuState.brands = brands.data || [];
    renderSkus(skus.meta.total);
}

function renderSkus(total = skuState.rows.length) {
    const rows = filterRows();
    document.getElementById('skusRoot').innerHTML = VKTable.fullList({
        title: 'Danh sách mã hàng',
        subtitle: 'Quản lý danh mục, hãng, hình ảnh và thông tin kỹ thuật của sản phẩm.',
        meta: `${VKTable.money(rows.length)} / ${VKTable.money(total)} mã hàng`,
        filters: VKTable.filterBar({
            searchPlaceholder: 'Tìm SKU, tên hàng, số serial...',
            status: [
                { value: 'active', label: 'Hoạt động' },
                { value: 'inactive', label: 'Không hoạt động' },
            ],
        }),
        table: renderSkuTable(rows),
    });
    restoreFilters();
}

function renderSkuTable(rows) {
    return VKTable.renderTable([
        { label: 'Mã hàng', render: row => `<strong class="mono">${VKTable.escapeHtml(row.sku_code)}</strong>` },
        { label: 'Tên hàng', render: row => `<strong>${VKTable.escapeHtml(row.name)}</strong>${row.serial_number ? `<span class="row-note">Serial: ${VKTable.escapeHtml(row.serial_number)}</span>` : ''}` },
        { label: 'Sản phẩm', render: row => renderProduct(row.product) },
        { label: 'Đơn vị', render: row => VKTable.escapeHtml(row.unit || '-') },
        { label: 'Giá vốn', render: row => VKTable.money(row.cost_price) },
        { label: 'Giá bán', render: row => VKTable.money(row.sale_price) },
        { label: 'Tồn min/max', render: row => `${VKTable.money(row.min_stock)} / ${VKTable.money(row.max_stock)}` },
        { label: 'Trạng thái', render: row => VKTable.statusBadge(row.status) },
        { label: '', render: row => VKTable.rowActions([
            VKTable.smallButton('Mở', `data-sku-detail="${row.id}"`),
            VKTable.smallButton('Sửa', `data-edit-sku="${row.id}"`),
        ]) },
    ], rows, 'Chưa có mã hàng', { rowAttr: row => `data-row-detail="${row.id}"` });
}

function filterRows() {
    return skuState.rows.filter(row => {
        const haystack = `${row.sku_code || ''} ${row.name || ''} ${row.serial_number || ''} ${row.product?.name || ''} ${row.product?.category?.name || ''} ${row.product?.brand?.name || ''}`.toLowerCase();
        return (!skuState.q || haystack.includes(skuState.q)) && (!skuState.status || row.status === skuState.status);
    });
}

function restoreFilters() {
    const search = document.querySelector('[data-list-search]');
    const status = document.querySelector('[data-list-status]');
    if (search) search.value = skuState.q;
    if (status) status.value = skuState.status;
}

function renderProduct(product) {
    if (!product) return '-';
    const image = product.image_path ? `<img class="sku-product-thumb" src="/storage/${VKTable.escapeHtml(product.image_path)}" alt="">` : '<span class="sku-product-thumb placeholder">SP</span>';
    const details = [product.category?.name, product.brand?.name].filter(Boolean).map(VKTable.escapeHtml).join(' · ');
    return `${image}<span><strong>${VKTable.escapeHtml(product.name)}</strong><span class="row-note">${details || 'Chưa phân loại'}</span></span>`;
}

function openSkuModal(id = null) {
    const row = id ? skuState.rows.find(item => item.id === id) : {};
    VKModal.open(id ? 'Sửa mã hàng' : 'Tạo mã hàng', `
        <div class="product-form-step active"><div class="form-grid">
            <div class="sku-product-form">
                <div class="sku-image-field"><label for="product_image">Ảnh sản phẩm</label>${row?.product?.image_path ? `<img class="sku-image-preview" src="/storage/${VKTable.escapeHtml(row.product.image_path)}" alt="Ảnh sản phẩm">` : '<div class="sku-image-preview empty">Chưa có ảnh</div>'}<input id="product_image" name="product_image" type="file" accept="image/png,image/jpeg,image/webp"></div>
                <div class="form-grid">${VKModal.field('product_name', 'Tên sản phẩm', 'text', row?.product?.name || '')}${VKModal.field('name', 'Tên mã hàng', 'text', row?.name || '')}${VKModal.select('category_id', 'Danh mục sản phẩm', [{value:'',label:'Chọn danh mục'}, ...skuState.categories.filter(x=>x.status==='active').map(x=>({value:x.id,label:x.name}))], row?.product?.category_id || '')}${VKModal.select('brand_id', 'Hãng / thương hiệu', [{value:'',label:'Chọn hãng'}, ...skuState.brands.filter(x=>x.status==='active').map(x=>({value:x.id,label:x.name}))], row?.product?.brand_id || '')}</div>
            </div>
        </div></div>
        <div class="product-form-step active"><div class="form-grid">
            <div class="field"><label for="description">Mô tả sản phẩm</label><textarea id="description" name="description" rows="3" placeholder="Mô tả ngắn về sản phẩm">${VKTable.escapeHtml(row?.product?.description || '')}</textarea></div>
            <div class="field"><label for="technical_specs">Thông số kỹ thuật</label><textarea id="technical_specs" name="technical_specs" rows="4" placeholder="Màn hình: 10 inch&#10;Kết nối: Wi-Fi, LAN&#10;Bảo hành: 12 tháng">${VKTable.escapeHtml((row?.product?.technical_specs || []).map(item => `${item.name}: ${item.value}`).join('\n'))}</textarea><small>Mỗi dòng một thông số, theo dạng “Tên: Giá trị”.</small></div>
        </div></div>
        <div class="product-form-step active">
            <div class="sku-stock-price-grid">
            ${VKModal.field('serial_number', 'Số serial', 'text', row?.serial_number || '')}
            ${VKModal.field('unit', 'Đơn vị tính', 'text', row?.unit || 'pcs')}
            ${VKModal.field('min_stock', 'Tồn tối thiểu', 'number', row?.min_stock || '0')}
            ${VKModal.field('max_stock', 'Tồn tối đa', 'number', row?.max_stock || '0')}
            <div class="field"><label for="cost_price">Giá vốn</label><input id="cost_price" name="cost_price" type="text" inputmode="numeric" autocomplete="off" data-money-input value="${formatSkuMoney(row?.cost_price || 0)}"><small>Nhập số, hệ thống tự ngăn cách hàng nghìn.</small></div>
            <div class="field"><label for="sale_price">Giá bán</label><input id="sale_price" name="sale_price" type="text" inputmode="numeric" autocomplete="off" data-money-input value="${formatSkuMoney(row?.sale_price || 0)}"><small>Nhập số, hệ thống tự ngăn cách hàng nghìn.</small></div>
            ${VKModal.select('status', 'Trạng thái', [
                { value: 'active', label: 'Hoạt động' },
                { value: 'inactive', label: 'Không hoạt động' },
            ], row?.status || 'active')}
            </div>
        </div>
    `, async (form) => {
        const data = new FormData(form);
        ['cost_price', 'sale_price'].forEach(name => data.set(name, String(parseSkuMoney(data.get(name)))));
        if (id) data.append('_method', 'PUT');
        await VKApi.request(id ? `/skus/${id}` : '/skus', {
            method: 'POST', body: data,
        });
        VKModal.toast(id ? 'Đã cập nhật mã hàng.' : 'Đã tạo mã hàng.');
        VKModal.close();
        loadSkus();
    }, { className: 'modal-wide sku-catalog-modal', submitText: id ? 'Lưu sản phẩm' : 'Tạo sản phẩm' });
}

function parseSkuMoney(value) {
    return Number(String(value ?? '').replace(/\D/g, '')) || 0;
}

function formatSkuMoney(value) {
    return parseSkuMoney(value).toLocaleString('vi-VN');
}

function formatSkuMoneyInput(input) {
    const cursorFromEnd = input.value.length - input.selectionStart;
    input.value = formatSkuMoney(input.value);
    const nextPosition = Math.max(0, input.value.length - cursorFromEnd);
    input.setSelectionRange(nextPosition, nextPosition);
}

function openSkuDetail(id) {
    const row = skuState.rows.find(item => String(item.id) === String(id));
    if (!row) return;
    VKRecordPage.open({
        root: '#skusRoot',
        type: 'sku',
        id,
        path: `/skus/${id}`,
        preview: { ...row, timeline: [{ label: 'Tạo mã hàng', status: row.status, at: row.created_at }] },
        onBack: () => renderSkus(),
        actions: () => `<button class="btn primary small" type="button" data-edit-sku="${id}">Sửa mã hàng</button>`,
    });
    return;
    VKDetailDrawer.open({ type: 'sku', row: { ...row, timeline: [{ label: 'Tạo mã hàng', status: row.status, at: row.created_at }] } });
}

window.loadSkus = loadSkus;
})();
