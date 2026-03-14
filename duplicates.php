<?php
$pageTitle = 'Ürün Birleştirme';
include 'templates/header.php';
?>

<style>
    .product-table {
        width: 100%;
        border-collapse: separate;
        border-spacing: 0;
    }

    .product-table th {
        position: sticky;
        top: 0;
        background: rgba(15, 23, 42, 0.95);
        backdrop-filter: blur(8px);
        padding: 0.75rem 1rem;
        text-align: left;
        font-weight: 600;
        font-size: 0.8rem;
        text-transform: uppercase;
        letter-spacing: 0.05em;
        color: #94a3b8;
        border-bottom: 1px solid rgba(255, 255, 255, 0.08);
        z-index: 10;
    }

    .product-table td {
        padding: 0.6rem 1rem;
        border-bottom: 1px solid rgba(255, 255, 255, 0.04);
        font-size: 0.85rem;
        transition: background 0.15s;
    }

    .product-table tr:hover td {
        background: rgba(255, 255, 255, 0.03);
    }

    .product-table tr.selected td {
        background: rgba(99, 102, 241, 0.12);
    }

    .product-table input[type="checkbox"] {
        width: 16px;
        height: 16px;
        accent-color: #6366f1;
        cursor: pointer;
    }

    .search-input {
        background: rgba(255, 255, 255, 0.05);
        border: 1px solid rgba(255, 255, 255, 0.1);
        border-radius: 0.75rem;
        padding: 0.65rem 1rem 0.65rem 2.5rem;
        color: #fff;
        width: 100%;
        font-size: 0.85rem;
        transition: border-color 0.2s;
    }

    .search-input:focus {
        outline: none;
        border-color: rgba(99, 102, 241, 0.5);
    }

    .search-wrap {
        position: relative;
    }

    .search-wrap svg {
        position: absolute;
        left: 0.75rem;
        top: 50%;
        transform: translateY(-50%);
        color: #64748b;
    }

    .merge-panel {
        position: sticky;
        top: 1rem;
    }

    .selected-item {
        display: flex;
        align-items: center;
        justify-content: space-between;
        padding: 0.5rem 0.75rem;
        background: rgba(255, 255, 255, 0.03);
        border: 1px solid rgba(255, 255, 255, 0.06);
        border-radius: 0.5rem;
        margin-bottom: 0.4rem;
        font-size: 0.8rem;
    }

    .selected-item.master {
        border-color: rgba(99, 102, 241, 0.4);
        background: rgba(99, 102, 241, 0.08);
    }

    .btn-master {
        font-size: 0.65rem;
        padding: 0.2rem 0.5rem;
        border-radius: 4px;
        border: 1px solid rgba(255, 255, 255, 0.1);
        background: transparent;
        color: #94a3b8;
        cursor: pointer;
        transition: all 0.2s;
    }

    .btn-master:hover {
        border-color: #6366f1;
        color: #a5b4fc;
    }

    .btn-master.active {
        background: #6366f1;
        color: #fff;
        border-color: #6366f1;
    }

    .btn-remove {
        background: none;
        border: none;
        color: #ef4444;
        cursor: pointer;
        font-size: 1rem;
        padding: 0 0.25rem;
        opacity: 0.6;
        transition: opacity 0.2s;
    }

    .btn-remove:hover {
        opacity: 1;
    }

    .btn-merge {
        width: 100%;
        padding: 0.75rem;
        border: none;
        border-radius: 0.75rem;
        background: linear-gradient(135deg, #6366f1, #8b5cf6);
        color: #fff;
        font-weight: 600;
        font-size: 0.9rem;
        cursor: pointer;
        transition: all 0.2s;
    }

    .btn-merge:hover:not(:disabled) {
        transform: translateY(-1px);
        box-shadow: 0 4px 15px rgba(99, 102, 241, 0.3);
    }

    .btn-merge:disabled {
        opacity: 0.4;
        cursor: not-allowed;
        transform: none;
    }

    .table-scroll {
        max-height: 70vh;
        overflow-y: auto;
        border-radius: 0.75rem;
        border: 1px solid rgba(255, 255, 255, 0.06);
    }

    .table-scroll::-webkit-scrollbar {
        width: 6px;
    }

    .table-scroll::-webkit-scrollbar-track {
        background: transparent;
    }

    .table-scroll::-webkit-scrollbar-thumb {
        background: rgba(255, 255, 255, 0.1);
        border-radius: 3px;
    }

    .counter-badge {
        background: rgba(99, 102, 241, 0.15);
        color: #a5b4fc;
        padding: 0.15rem 0.6rem;
        border-radius: 999px;
        font-size: 0.75rem;
        font-weight: 600;
    }

    .empty-state {
        text-align: center;
        padding: 3rem;
        color: #64748b;
    }

    .log-line {
        padding: 0.2rem 0;
        font-size: 0.8rem;
        color: #94a3b8;
        font-family: monospace;
    }

    .layout-grid {
        display: grid;
        grid-template-columns: 1fr 340px;
        gap: 1.5rem;
    }

    @media (max-width: 1024px) {
        .layout-grid {
            grid-template-columns: 1fr;
        }
    }

    .select-all-bar {
        display: flex;
        align-items: center;
        gap: 0.75rem;
        padding: 0.5rem 1rem;
        background: rgba(255, 255, 255, 0.02);
        border-bottom: 1px solid rgba(255, 255, 255, 0.06);
    }
</style>

<!-- Header -->
<div class="flex flex-col sm:flex-row items-start sm:items-center justify-between mb-6 gap-4">
    <div>
        <h2 class="text-2xl font-bold gradient-text">🔀 Ürün Birleştirme</h2>
        <p class="text-sm text-slate-400 mt-1">Zoho ürünlerini seçip birleştirin</p>
    </div>
    <div class="flex gap-2">
        <button onclick="refreshProducts()"
            class="text-sm px-3 py-1.5 rounded-lg border border-white/10 text-slate-400 hover:text-white hover:border-white/20 transition-all">🔄
            Zoho'dan Yenile</button>
        <button onclick="loadMergeLog()"
            class="text-sm px-3 py-1.5 rounded-lg border border-white/10 text-slate-400 hover:text-white hover:border-white/20 transition-all">📋
            Geçmiş</button>
    </div>
</div>

<div class="layout-grid">
    <!-- Left: Product List -->
    <div>
        <div class="glass rounded-2xl border border-white/10 overflow-hidden">
            <!-- Search -->
            <div class="p-4 border-b border-white/6">
                <div class="search-wrap">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <circle cx="11" cy="11" r="8" />
                        <path d="M21 21l-4.35-4.35" />
                    </svg>
                    <input type="text" id="searchInput" class="search-input" placeholder="Ürün adı veya kodu ile ara..."
                        oninput="filterProducts()">
                </div>
            </div>

            <!-- Table -->
            <div class="table-scroll" id="tableScroll">
                <table class="product-table">
                    <thead>
                        <tr>
                            <th style="width:40px"><input type="checkbox" id="selectAll"
                                    onchange="toggleSelectAll(this.checked)" title="Tümünü seç"></th>
                            <th>Ürün Adı</th>
                            <th>Ürün Kodu</th>
                        </tr>
                    </thead>
                    <tbody id="productBody">
                        <tr>
                            <td colspan="3" class="empty-state">Yükleniyor...</td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <!-- Footer -->
            <div class="flex items-center justify-between p-3 border-t border-white/6 text-sm text-slate-400">
                <span id="productCount">0 ürün</span>
                <span id="selectedCount" class="counter-badge" style="display:none">0 seçili</span>
            </div>
            <button id="loadMoreBtn" onclick="loadMore()"
                style="display:none; width:100%; padding:0.6rem; background:rgba(99,102,241,0.1); border:1px solid rgba(99,102,241,0.2); color:#a5b4fc; border-radius:0 0 1rem 1rem; cursor:pointer; font-size:0.85rem; transition:background 0.2s"
                onmouseover="this.style.background='rgba(99,102,241,0.2)'"
                onmouseout="this.style.background='rgba(99,102,241,0.1)'">
                Daha Fazla Yükle
            </button>
        </div>
    </div>

    <!-- Right: Merge Panel -->
    <div>
        <div class="merge-panel glass rounded-2xl border border-white/10 p-5">
            <h3 class="font-semibold text-white mb-1">Birleştirme Paneli</h3>
            <p class="text-xs text-slate-500 mb-4">Soldan ürün seçin, master belirleyin</p>

            <div id="selectedList" class="mb-4" style="max-height: 45vh; overflow-y: auto;">
                <div class="empty-state text-sm">Henüz ürün seçilmedi</div>
            </div>

            <button id="btnMerge" class="btn-merge" disabled onclick="doMerge()">
                🔀 Birleştir
            </button>

            <!-- Merge Log -->
            <div id="mergeLogPanel" class="mt-4 hidden">
                <h4 class="text-sm font-semibold text-white mb-2">İşlem Sonucu</h4>
                <div id="mergeLogContent" class="text-sm space-y-0.5" style="max-height: 30vh; overflow-y: auto;"></div>
            </div>
        </div>
    </div>
</div>

<!-- Merge Log Modal -->
<div id="logModal" class="fixed inset-0 bg-black/60 backdrop-blur-sm z-50 hidden flex items-center justify-center p-4"
    onclick="if(event.target===this)closeLogModal()">
    <div class="glass rounded-2xl border border-white/10 p-6 w-full max-w-2xl max-h-[80vh] overflow-y-auto">
        <div class="flex justify-between items-center mb-4">
            <h3 class="font-semibold text-white">📋 Birleştirme Geçmişi</h3>
            <button onclick="closeLogModal()" class="text-slate-400 hover:text-white">&times;</button>
        </div>
        <div id="logModalBody" class="text-sm text-slate-300">Yükleniyor...</div>
    </div>
</div>

<script>
    const CSRF = '<?= htmlspecialchars(generateCsrfToken()) ?>';
    let allProducts = [];
    let selectedProducts = new Map(); // zoho_id → {name, code, price}
    let masterId = null;

    function api(action, data = {}) {
        console.log(`[API] → ${action}`, data);
        const formData = new FormData();
        formData.append('action', action);
        formData.append('csrf_token', CSRF);
        Object.entries(data).forEach(([k, v]) => formData.append(k, v));
        return fetch('api_handler.php', { method: 'POST', body: formData }).then(async r => {
            console.log(`[API] ← ${action} status=${r.status}`);
            if (r.status === 403) {
                const errData = await r.json().catch(() => ({}));
                if ((errData.message || '').includes('CSRF')) {
                    alert('Oturum zaman aşımına uğradı. Sayfa yenilenecek.');
                    location.reload();
                    throw new Error('CSRF expired');
                }
            }
            const text = await r.text();
            console.log(`[API] ← ${action} body length=${text.length}, preview=${text.substring(0, 200)}`);
            if (!text) throw new Error('Sunucudan boş yanıt geldi (timeout olabilir)');
            try { return JSON.parse(text); }
            catch { throw new Error('Sunucu hatası: ' + text.substring(0, 200)); }
        }).catch(e => {
            console.error(`[API] ✖ ${action} FETCH ERROR:`, e);
            throw e;
        });
    }

    // ==================== LOAD PRODUCTS ====================

    let currentPage = 1;
    let totalPages = 1;
    let searchTimer = null;

    async function loadProducts(search = '', page = 1, append = false) {
        console.log(`[loadProducts] search='${search}' page=${page} append=${append}`);
        try {
            const res = await api('get_all_zoho_products', { search, limit: 200, page });
            console.log(`[loadProducts] response:`, { success: res.success, dataCount: res.data?.length, total: res.total, pages: res.pages });
            if (res.success) {
                currentPage = page;
                totalPages = res.pages || 1;
                if (append) {
                    allProducts = allProducts.concat(res.data);
                } else {
                    allProducts = res.data;
                }
                renderProducts(allProducts);
                // Show/hide load more button
                const loadMoreEl = document.getElementById('loadMoreBtn');
                if (loadMoreEl) {
                    loadMoreEl.style.display = currentPage < totalPages ? 'block' : 'none';
                    loadMoreEl.textContent = `Daha Fazla Yükle (${currentPage}/${totalPages})`;
                }
            } else {
                console.error('[loadProducts] API returned success=false:', res.message);
                document.getElementById('productBody').innerHTML =
                    `<tr><td colspan="3" class="empty-state" style="color:#f87171">API Hatası: ${res.message || 'Bilinmeyen'}</td></tr>`;
            }
        } catch (e) {
            console.error('[loadProducts] EXCEPTION:', e);
            document.getElementById('productBody').innerHTML =
                `<tr><td colspan="3" class="empty-state" style="color:#f87171">Hata: ${e.message}</td></tr>`;
        }
    }

    function loadMore() {
        if (currentPage < totalPages) {
            const q = document.getElementById('searchInput').value.trim();
            loadProducts(q, currentPage + 1, true);
        }
    }

    function filterProducts() {
        // Debounced server-side search
        clearTimeout(searchTimer);
        searchTimer = setTimeout(() => {
            const q = document.getElementById('searchInput').value.trim();
            loadProducts(q, 1, false);
        }, 300);
    }

    function renderProducts(products) {
        const body = document.getElementById('productBody');
        document.getElementById('productCount').textContent = `${products.length} ürün`;

        if (products.length === 0) {
            body.innerHTML = '<tr><td colspan="3" class="empty-state">Ürün bulunamadı</td></tr>';
            return;
        }

        body.innerHTML = products.map(p => {
            const checked = selectedProducts.has(p.zoho_id) ? 'checked' : '';
            const cls = selectedProducts.has(p.zoho_id) ? 'selected' : '';
            return `<tr class="${cls}" data-id="${p.zoho_id}">
            <td><input type="checkbox" ${checked} onchange="toggleProduct('${p.zoho_id}', this.checked)"></td>
            <td class="text-white">${esc(p.product_name || '—')}</td>
            <td class="text-slate-400 font-mono text-xs">${esc(p.product_code || '—')}</td>
        </tr>`;
        }).join('');
    }

    function esc(s) { const d = document.createElement('div'); d.textContent = s; return d.innerHTML; }

    // ==================== SELECTION ====================

    function toggleProduct(id, checked) {
        if (checked) {
            const p = allProducts.find(x => x.zoho_id === id);
            if (p) selectedProducts.set(id, { name: p.product_name, code: p.product_code, price: p.unit_price });
            if (selectedProducts.size === 1) masterId = id; // Auto-select first as master
        } else {
            selectedProducts.delete(id);
            if (masterId === id) masterId = selectedProducts.size > 0 ? selectedProducts.keys().next().value : null;
        }
        updateRow(id, checked);
        renderSelectedPanel();
    }

    function toggleSelectAll(checked) {
        const q = document.getElementById('searchInput').value.toLowerCase().trim();
        const visible = q ? allProducts.filter(p =>
            (p.product_name || '').toLowerCase().includes(q) ||
            (p.product_code || '').toLowerCase().includes(q)
        ) : allProducts;

        visible.forEach(p => {
            if (checked) selectedProducts.set(p.zoho_id, { name: p.product_name, code: p.product_code, price: p.unit_price });
            else selectedProducts.delete(p.zoho_id);
        });
        if (checked && !masterId && selectedProducts.size > 0) masterId = selectedProducts.keys().next().value;
        if (!checked) masterId = null;

        filterProducts(); // Re-render with updated checkboxes
        renderSelectedPanel();
    }

    function updateRow(id, selected) {
        const row = document.querySelector(`tr[data-id="${id}"]`);
        if (row) row.classList.toggle('selected', selected);
    }

    function removeSelection(id) {
        selectedProducts.delete(id);
        if (masterId === id) masterId = selectedProducts.size > 0 ? selectedProducts.keys().next().value : null;
        // Uncheck in table
        const row = document.querySelector(`tr[data-id="${id}"]`);
        if (row) {
            row.classList.remove('selected');
            const cb = row.querySelector('input[type="checkbox"]');
            if (cb) cb.checked = false;
        }
        renderSelectedPanel();
    }

    function setMaster(id) {
        masterId = id;
        renderSelectedPanel();
    }

    function renderSelectedPanel() {
        const list = document.getElementById('selectedList');
        const countEl = document.getElementById('selectedCount');
        const btn = document.getElementById('btnMerge');

        if (selectedProducts.size === 0) {
            list.innerHTML = '<div class="empty-state text-sm">Henüz ürün seçilmedi</div>';
            countEl.style.display = 'none';
            btn.disabled = true;
            return;
        }

        countEl.textContent = `${selectedProducts.size} seçili`;
        countEl.style.display = 'inline';
        btn.disabled = selectedProducts.size < 2;

        let html = '';
        for (const [id, p] of selectedProducts) {
            const isMaster = id === masterId;
            html += `<div class="selected-item ${isMaster ? 'master' : ''}">
            <div style="min-width:0; flex:1">
                <div class="text-white text-xs truncate" title="${esc(p.name)}">${esc(p.name || '—')}</div>
                <div class="text-slate-500" style="font-size:0.7rem; font-family:monospace">${esc(p.code || 'Kodsuz')}</div>
            </div>
            <div class="flex items-center gap-1.5" style="flex-shrink:0">
                <button class="btn-master ${isMaster ? 'active' : ''}" onclick="setMaster('${id}')" title="Master olarak ayarla">
                    ${isMaster ? '👑 Master' : 'Master yap'}
                </button>
                <button class="btn-remove" onclick="removeSelection('${id}')" title="Kaldır">&times;</button>
            </div>
        </div>`;
        }
        list.innerHTML = html;
    }

    // ==================== MERGE ====================

    async function doMerge() {
        if (selectedProducts.size < 2 || !masterId) return;

        const masterP = selectedProducts.get(masterId);
        const dupIds = [...selectedProducts.keys()].filter(id => id !== masterId);
        const dupNames = dupIds.map(id => selectedProducts.get(id)?.name || id).join(', ');

        if (!confirm(`Birleştirme onayı:\n\n👑 Master: ${masterP.name} (${masterP.code})\n🗑 Silinecek: ${dupNames}\n\nFatura/PO referansları master ürüne taşınacak.\nDevam etmek istiyor musunuz?`)) return;

        const btn = document.getElementById('btnMerge');
        btn.disabled = true;
        btn.textContent = '⏳ İşleniyor...';

        const logPanel = document.getElementById('mergeLogPanel');
        const logContent = document.getElementById('mergeLogContent');
        logPanel.classList.remove('hidden');
        logContent.innerHTML = '<div class="log-line">⏳ Birleştirme başlatılıyor...</div>';

        try {
            const res = await api('merge_products', {
                master_id: masterId,
                duplicate_ids: JSON.stringify(dupIds),
                new_name: masterP.name,
                product_code: masterP.code || ''
            });

            if (res.success) {
                const d = res.details || {};
                logContent.innerHTML = (d.log || []).map(l => `<div class="log-line">${l}</div>`).join('') +
                    `<div class="log-line" style="color:#34d399; margin-top:0.5rem">✅ ${d.invoices_moved || 0} fatura, ${d.pos_moved || 0} PO güncellendi, ${d.duplicates_deleted || 0} ürün silindi</div>`;

                // Clear selection and reload
                selectedProducts.clear();
                masterId = null;
                renderSelectedPanel();
                await loadProducts();
            } else {
                logContent.innerHTML = `<div class="log-line" style="color:#f87171">❌ ${res.message}</div>`;
            }
        } catch (e) {
            logContent.innerHTML = `<div class="log-line" style="color:#f87171">❌ Hata: ${e.message}</div>`;
        }

        btn.disabled = false;
        btn.textContent = '🔀 Birleştir';
    }

    // ==================== REFRESH FROM ZOHO ====================

    async function refreshProducts() {
        const btn = document.querySelector('[onclick="refreshProducts()"]');
        btn.disabled = true;
        btn.textContent = '⏳ Çekiliyor...';
        try {
            const res = await api('fetch_zoho_products');
            if (res.success) {
                await loadProducts();
            } else {
                alert('Hata: ' + (res.message || 'Bilinmeyen hata'));
            }
        } catch (e) {
            alert('Hata: ' + e.message);
        }
        btn.disabled = false;
        btn.textContent = '🔄 Zoho\'dan Yenile';
    }

    // ==================== MERGE LOG ====================

    async function loadMergeLog() {
        document.getElementById('logModal').classList.remove('hidden');
        const body = document.getElementById('logModalBody');
        body.innerHTML = 'Yükleniyor...';

        try {
            const res = await api('get_merge_log', { entity_type: 'product', limit: 20 });
            if (!res.success || !res.data?.length) {
                body.innerHTML = '<div class="text-slate-500">Henüz birleştirme geçmişi yok.</div>';
                return;
            }
            body.innerHTML = res.data.map(log => {
                const backup = log.backup_data || {};
                const affected = log.affected_records || [];
                const statusColor = log.status === 'completed' ? '#34d399' : log.status === 'failed' ? '#f87171' : '#fbbf24';
                return `<div class="p-3 rounded-lg mb-2" style="background:rgba(255,255,255,0.03); border:1px solid rgba(255,255,255,0.06)">
                <div class="flex justify-between items-center mb-1">
                    <span class="text-white text-xs font-semibold">${backup.product_code || '—'}</span>
                    <span style="color:${statusColor}; font-size:0.7rem">${log.status}</span>
                </div>
                <div class="text-slate-500" style="font-size:0.7rem">
                    Master: ${log.target_zoho_id || '—'} | 
                    Silinen: ${(backup.duplicate_ids || []).length} ürün | 
                    Etkilenen: ${affected.length} kayıt |
                    ${log.created_at || ''}
                </div>
            </div>`;
            }).join('');
        } catch (e) {
            body.innerHTML = `<div style="color:#f87171">Hata: ${e.message}</div>`;
        }
    }

    function closeLogModal() { document.getElementById('logModal').classList.add('hidden'); }

    // ==================== INIT ====================
    loadProducts();
</script>

<?php include 'templates/footer.php'; ?>