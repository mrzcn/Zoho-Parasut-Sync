<?php
$pageTitle = 'Gider Faturaları';
include 'templates/header.php';
?>

<div class="glass-card rounded-2xl overflow-hidden mb-6">
    <div class="p-6">
        <div class="flex justify-between items-center mb-4">
            <div class="flex items-center gap-4">
                <h2 class="text-xl font-bold text-white">📦 Gider Faturaları (Purchase Orders)</h2>
                <select id="filter-year" onchange="loadPurchaseOrdersFromDB(1)"
                    class="bg-slate-800 border border-slate-700 text-white rounded-lg px-3 py-1.5 text-sm focus:ring-2 focus:ring-violet-500/50 focus:border-violet-500 transition-all">
                    <option value="2026" selected>2026</option>
                    <option value="2025">2025</option>
                    <option value="2024">2024</option>
                    <option value="2023">2023</option>
                    <option value="2022">2022</option>
                    <option value="2021">2021</option>
                    <option value="2020">2020</option>
                    <option value="2019">2019</option>
                    <option value="2018">2018</option>
                </select>
                <div class="h-6 w-px bg-slate-700 mx-2"></div>
                <span class="text-xs text-slate-400">Son Gider Faturası: <span id="latest-po-date"
                        class="text-white font-medium">Yükleniyor...</span></span>
            </div>
            <div class="flex gap-4">
                <label
                    class="flex items-center gap-2 text-sm text-slate-400 cursor-pointer hover:text-slate-200 transition-colors">
                    <input type="radio" name="sync-filter" value="unsynced"
                        onchange="loadPurchaseOrdersFromDB(1); updateBatchControlsVisibility()"
                        class="form-radio bg-slate-800 border-slate-700 text-slate-500 focus:ring-slate-500/50" checked>
                    Henüz Sync Edilmemiş
                </label>
                <label
                    class="flex items-center gap-2 text-sm text-slate-400 cursor-pointer hover:text-slate-200 transition-colors">
                    <input type="radio" name="sync-filter" value="synced"
                        onchange="loadPurchaseOrdersFromDB(1); updateBatchControlsVisibility()"
                        class="form-radio bg-slate-800 border-slate-700 text-emerald-500 focus:ring-emerald-500/50">
                    Sync Edilmiş
                </label>
                <div id="batch-controls" class="flex items-center gap-2">
                    <button onclick="fetchPurchaseOrders()"
                        class="btn-outline border-orange-500/50 text-orange-400 px-3 py-1.5 rounded-lg text-sm hover:bg-orange-500/10">
                        Gider Faturalarını Yenile
                    </button>
                    <select id="batch-size"
                        class="bg-slate-800 border border-slate-700 text-white rounded-lg px-3 py-1.5 text-sm focus:ring-2 focus:ring-violet-500/50 focus:border-violet-500 transition-all">
                        <option value="50">50 kayıt</option>
                        <option value="100">100 kayıt</option>
                        <option value="150">150 kayıt</option>
                        <option value="200">200 kayıt</option>
                        <option value="250" selected>250 kayıt</option>
                        <option value="500">500 kayıt</option>
                    </select>
                    <button onclick="selectBatchRecords()"
                        class="btn-outline text-slate-300 px-3 py-1.5 rounded-lg text-sm">
                        Toplu Seç
                    </button>
                </div>
                <button onclick="bulkExportToZoho()" id="bulk-export-btn"
                    class="hidden btn-primary px-4 py-2 rounded-xl text-sm">
                    Seçilenleri Zoho'ya Aktar (<span id="bulk-count">0</span>)
                </button>
            </div>
        </div>
        <div class="overflow-x-auto rounded-xl">
            <table class="table-premium min-w-full">
                <thead>
                    <tr>
                        <th class="px-6 py-4 text-left">
                            <input type="checkbox" id="select-all-pos"
                                class="rounded border-slate-600 bg-slate-700 text-violet-500 focus:ring-violet-500"
                                onchange="toggleSelectAll(this)">
                        </th>
                        <th class="px-6 py-4 text-left text-xs font-semibold text-slate-400 uppercase">Tarih</th>
                        <th class="px-6 py-4 text-left text-xs font-semibold text-slate-400 uppercase">Fatura No</th>
                        <th class="px-6 py-4 text-left text-xs font-semibold text-slate-400 uppercase">Açıklama</th>
                        <th class="px-6 py-4 text-left text-xs font-semibold text-slate-400 uppercase">Toplam Tutar</th>
                        <th class="px-6 py-4 text-left text-xs font-semibold text-slate-400 uppercase">Durum</th>
                        <th class="px-6 py-4 text-right text-xs font-semibold text-slate-400 uppercase">İşlemler</th>
                    </tr>
                </thead>
                <tbody id="poTableBody" class="text-slate-300">
                    <tr>
                        <td colspan="7" class="px-6 py-8 text-center text-slate-500">Veri yok veya yüklenmedi.</td>
                    </tr>
                </tbody>
            </table>
        </div>

        <!-- Pagination -->
        <div id="po-pagination" class="mt-6 flex justify-center items-center gap-2">
            <!-- Loaded via JS -->
        </div>
    </div>
</div>

<!-- Purchase Order Detail Modal -->
<div id="po-modal" class="fixed inset-0 bg-black/60 backdrop-blur-sm overflow-y-auto h-full w-full hidden z-50">
    <div class="relative top-10 mx-auto p-6 w-11/12 max-w-6xl glass-card rounded-2xl">
        <div class="flex justify-between items-center mb-6">
            <h3 class="text-xl font-bold text-white">📦 Gider Faturası Detayı</h3>
            <button onclick="closeModal()"
                class="text-slate-400 hover:text-white text-2xl transition-colors">&times;</button>
        </div>
        <div class="overflow-x-auto rounded-xl">
            <table class="table-premium min-w-full">
                <thead>
                    <tr>
                        <th class="px-4 py-3 text-left text-xs font-semibold text-slate-400 uppercase">Ürün Adı</th>
                        <th class="px-4 py-3 text-left text-xs font-semibold text-slate-400 uppercase">Miktar</th>
                        <th class="px-4 py-3 text-left text-xs font-semibold text-slate-400 uppercase">Birim Fiyat</th>
                        <th class="px-4 py-3 text-left text-xs font-semibold text-slate-400 uppercase">KDV %</th>
                        <th class="px-4 py-3 text-left text-xs font-semibold text-slate-400 uppercase">Toplam</th>
                    </tr>
                </thead>
                <tbody id="po-details-body" class="text-slate-300">
                </tbody>
            </table>
        </div>
        <div class="mt-6 text-right">
            <button onclick="closeModal()"
                class="btn-outline text-slate-300 px-6 py-2.5 rounded-xl font-medium">Kapat</button>
        </div>
    </div>
</div>

<script>
    // CSRF Token Setup
    axios.defaults.headers.common['X-CSRF-TOKEN'] = '<?php echo generateCsrfToken(); ?>';

    // Purchase Orders Global State
    let allPOs = [];
    let currentPOPage = 1;
    let totalPOPages = 1;

    function updateBatchControlsVisibility() {
        const batchControls = document.getElementById('batch-controls');
        if (batchControls) batchControls.style.display = 'flex';
    }

    function updateBulkButton() {
        const checkedCount = document.querySelectorAll('.po-checkbox:checked').length;
        const exportBtn = document.getElementById('bulk-export-btn');
        const countSpan = document.getElementById('bulk-count');

        countSpan.textContent = checkedCount;

        const syncFilter = document.querySelector('input[name="sync-filter"]:checked').value;

        exportBtn.classList.add('hidden');

        if (checkedCount > 0 && syncFilter !== 'synced') {
            exportBtn.classList.remove('hidden');
        }
    }

    async function fetchPurchaseOrders() {
        const tbody = document.getElementById('poTableBody');
        tbody.innerHTML = '<tr><td colspan="7" class="text-center py-8"><div class="loader"></div> <span class="ml-2 text-slate-400">Paraşüt API\'den çekiliyor...</span></td></tr>';

        const formData = new FormData();
        formData.append('action', 'fetch_parasut_purchase_orders');
        formData.append('csrf_token', '<?php echo generateCsrfToken(); ?>');

        try {
            const res = await axios.post('api_handler.php', formData, { timeout: 600000 });
            if (res.data.success) {
                const insertedCount = res.data.inserted_count || 0;

                if (insertedCount > 0) {
                    showToast(`${insertedCount} gider faturası eklendi.`, 'success');
                } else {
                    showToast('Yeni Eklen Gider Faturası Yok', 'info');
                }

                if (res.data.meta) {
                    currentPOPage = res.data.meta.current_page;
                    totalPOPages = res.data.meta.total_pages;
                    allPOs = res.data.data;
                } else {
                    allPOs = res.data.data || [];
                }

                if (allPOs.length === 0) {
                    tbody.innerHTML = '<tr><td colspan="7" class="text-center py-8 text-slate-500">Yeni gider faturası yok.</td></tr>';
                } else {
                    renderPOs(allPOs);
                    updatePaginationUI();
                }
            } else {
                tbody.innerHTML = '<tr><td colspan="7" class="text-center py-8 text-red-400">' + (res.data.message || 'Bilinmeyen hata') + '</td></tr>';
            }
        } catch (err) {
            tbody.innerHTML = '<tr><td colspan="7" class="text-center py-8 text-red-400">Hata: ' + err.message + '</td></tr>';
        }
    }

    function loadPurchaseOrdersFromDB(page = 1) {
        const tbody = document.getElementById('poTableBody');
        tbody.innerHTML = '<tr><td colspan="7" class="text-center py-8"><div class="loader"></div> <span class="ml-2 text-slate-400">Yükleniyor...</span></td></tr>';
        updatePaginationUI();

        const formData = new FormData();
        formData.append('action', 'get_parasut_purchase_orders');
        formData.append('csrf_token', '<?php echo generateCsrfToken(); ?>');
        formData.append('page', page);
        formData.append('limit', 250);

        const filterYear = document.getElementById('filter-year').value;
        if (filterYear) {
            formData.append('year', filterYear);
        }

        const syncFilter = document.querySelector('input[name="sync-filter"]:checked').value;
        let syncStatus = syncFilter === 'synced' ? 'synced' : 'unsynced';
        formData.append('sync_status', syncStatus);

        axios.post('api_handler.php', formData).then(res => {
            if (res.data.success) {
                if (res.data.meta) {
                    currentPOPage = res.data.meta.current_page;
                    totalPOPages = res.data.meta.total_pages;
                    allPOs = res.data.data;
                    if (res.data.meta.latest_issue_date) {
                        document.getElementById('latest-po-date').textContent = res.data.meta.latest_issue_date;
                    } else {
                        document.getElementById('latest-po-date').textContent = '-';
                    }
                } else {
                    allPOs = res.data.data;
                }

                renderPOs(allPOs);
                updatePaginationUI();
            } else {
                tbody.innerHTML = '<tr><td colspan="7" class="text-center py-8 text-slate-500">Veritabanında gider faturası yok. "Gider Faturalarını Yenile" butonuna basın.</td></tr>';
            }
        });
    }

    function changePOPage(newPage) {
        if (newPage < 1 || newPage > totalPOPages) return;
        loadPurchaseOrdersFromDB(newPage);
    }

    function updatePaginationUI() {
        const container = document.getElementById('po-pagination');
        if (!container) return;

        if (!allPOs || allPOs.length === 0 || totalPOPages <= 1) {
            container.innerHTML = '';
            return;
        }

        const current = parseInt(currentPOPage);
        const total = parseInt(totalPOPages);
        let html = '';

        // Previous button
        if (current > 1) {
            html += `<button onclick="loadPurchaseOrdersFromDB(${current - 1})" class="min-w-[90px] px-3 py-2 rounded-lg bg-slate-700 hover:bg-slate-600 text-white text-sm transition-colors">← Önceki</button>`;
        } else {
            html += `<button disabled class="min-w-[90px] px-3 py-2 rounded-lg bg-slate-800 text-slate-600 text-sm cursor-not-allowed">← Önceki</button>`;
        }

        // Page number buttons
        let startPage = Math.max(1, current - 2);
        let endPage = Math.min(total, current + 2);

        if (startPage > 1) {
            html += `<button onclick="loadPurchaseOrdersFromDB(1)" class="min-w-[40px] px-3 py-2 rounded-lg bg-slate-700 hover:bg-slate-600 text-white text-sm transition-colors">1</button>`;
            if (startPage > 2) html += `<span class="text-slate-500 px-2">...</span>`;
        }

        for (let i = startPage; i <= endPage; i++) {
            if (i === current) {
                html += `<button class="min-w-[40px] px-3 py-2 rounded-lg bg-white text-black text-sm font-bold">${i}</button>`;
            } else {
                html += `<button onclick="loadPurchaseOrdersFromDB(${i})" class="min-w-[40px] px-3 py-2 rounded-lg bg-slate-700 hover:bg-slate-600 text-white text-sm transition-colors">${i}</button>`;
            }
        }

        if (endPage < total) {
            if (endPage < total - 1) html += `<span class="text-slate-500 px-2">...</span>`;
            html += `<button onclick="loadPurchaseOrdersFromDB(${total})" class="min-w-[40px] px-3 py-2 rounded-lg bg-slate-700 hover:bg-slate-600 text-white text-sm transition-colors">${total}</button>`;
        }

        // Next button
        if (current < total) {
            html += `<button onclick="loadPurchaseOrdersFromDB(${current + 1})" class="min-w-[90px] px-3 py-2 rounded-lg bg-slate-700 hover:bg-slate-600 text-white text-sm transition-colors">Sonraki →</button>`;
        } else {
            html += `<button disabled class="min-w-[90px] px-3 py-2 rounded-lg bg-slate-800 text-slate-600 text-sm cursor-not-allowed">Sonraki →</button>`;
        }

        container.innerHTML = html;
    }

    function escapeHtml(text) {
        if (text === null || text === undefined) return '';
        return text.toString()
            .replace(/&/g, "&amp;")
            .replace(/</g, "&lt;")
            .replace(/>/g, "&gt;")
            .replace(/"/g, "&quot;")
            .replace(/'/g, "&#039;");
    }

    function renderPOs(pos) {
        const tbody = document.getElementById('poTableBody');
        tbody.innerHTML = '';

        if (!pos || pos.length === 0) {
            tbody.innerHTML = '<tr><td colspan="7" class="text-center py-8 text-slate-500">Gider faturası bulunamadı.</td></tr>';
            return;
        }

        pos.forEach(po => {
            const isSynced = po.synced_to_zoho == 1 && po.zoho_po_id;

            const checkboxHtml = `<input type="checkbox" class="po-checkbox rounded border-slate-600 bg-slate-700 text-violet-500 focus:ring-violet-500" value="${po.parasut_id}" onchange="updateBulkButton()">`;

            let btnHtml = '';
            if (isSynced) {
                btnHtml = `<button class="bg-slate-600/50 text-slate-300 text-xs px-3 py-1.5 rounded-lg border border-slate-600 cursor-default">✓ Zoho'da Mevcut</button>`;
            } else {
                btnHtml = `<button id="export-btn-${po.parasut_id}" class="bg-emerald-600 hover:bg-emerald-500 text-white text-xs px-3 py-1.5 rounded-lg transition-colors shadow-lg shadow-emerald-900/20" onclick="exportPOToZoho('${po.parasut_id}', this)">Zoho'ya Aktar</button>`;
            }

            const pLink = `https://uygulama.parasut.com/136555/fis-faturalar/${po.parasut_id}`;
            const zLink = po.zoho_po_id ? `https://crm.zoho.com/crm/org636648212/tab/Purchase_Orders/${po.zoho_po_id}` : null;

            let linksHtml = `<a href="${pLink}" target="_blank" class="text-xs bg-orange-500/10 text-orange-400 px-2 py-1.5 rounded hover:bg-orange-500/20 transition-colors mr-2 border border-orange-500/20" title="Paraşüt'te Görüntüle">P</a>`;
            if (zLink) {
                linksHtml += `<a href="${zLink}" target="_blank" class="text-xs bg-blue-500/10 text-blue-400 px-2 py-1.5 rounded hover:bg-blue-500/20 transition-colors mr-2 border border-blue-500/20" title="Zoho'da Görüntüle">Z</a>`;
            }

            const row = `
                <tr class="hover:bg-slate-700/30 transition-colors border-b border-slate-800/50 last:border-0">
                    <td class="px-6 py-4 text-sm">
                        ${checkboxHtml}
                    </td>
                    <td class="px-6 py-4 text-sm text-slate-300">${po.issue_date}</td>
                    <td class="px-6 py-4 text-sm text-violet-400 font-mono">${escapeHtml(po.invoice_number || '-')}</td>
                    <td class="px-6 py-4 text-sm text-white font-medium">
                        <span class="truncate max-w-[300px]" title="${escapeHtml(po.description || '')}">${escapeHtml(po.description || '-')}</span>
                    </td>
                    <td class="px-6 py-4 text-sm text-emerald-400 font-bold font-mono tracking-tight">${parseFloat(po.net_total).toLocaleString('tr-TR', { minimumFractionDigits: 2 })} <span class="text-xs text-slate-500 font-normal">${escapeHtml(po.currency)}</span></td>
                    <td class="px-6 py-4 text-sm">
                        ${po.payment_status === 'paid' ?
                    '<span class="px-2 py-1 rounded text-[10px] bg-emerald-500/20 text-emerald-400 border border-emerald-500/20">ÖDENDİ</span>' :
                    (po.payment_status === 'partially_paid' ?
                        '<span class="px-2 py-1 rounded text-[10px] bg-amber-500/20 text-amber-400 border border-amber-500/20">KISMİ ÖDEME</span>' :
                        '<span class="px-2 py-1 rounded text-[10px] bg-slate-700 text-slate-400 border border-slate-600">BEKLEMEDE</span>'
                    )
                }
                    </td>
                    <td class="px-6 py-4 text-right text-sm font-medium flex justify-end items-center">
                        ${linksHtml}
                        <button class="text-violet-400 hover:text-violet-300 transition-colors text-xs mr-3 border-b border-transparent hover:border-violet-400" onclick="showPODetails('${po.parasut_id}')">Detay</button>
                        ${btnHtml}
                    </td>
                </tr>
            `;
            tbody.innerHTML += row;
        });
    }

    function exportPOToZoho(id, btnElement, silent = false) {
        if (!silent && !confirm('Bu gider faturasını Zoho\'ya Purchase Order olarak aktarmak istiyor musunuz?')) return Promise.reject("Cancelled");

        let originalText = '';
        if (btnElement) {
            originalText = btnElement.textContent;
            btnElement.disabled = true;
            btnElement.innerHTML = '<div class="loader" style="width:14px;height:14px;border-width:2px;"></div>';
        }

        if (!silent) showToast('Purchase order aktarılıyor...', 'info');

        const formData = new FormData();
        formData.append('action', 'export_purchase_order_to_zoho');
        formData.append('po_id', id);
        formData.append('csrf_token', '<?php echo generateCsrfToken(); ?>');

        return axios.post('api_handler.php', formData)
            .then(res => {
                if (res.data.success) {
                    if (!silent) showToast(res.data.message, 'success');

                    const idx = allPOs.findIndex(p => p.parasut_id == id);
                    if (idx > -1) allPOs[idx].synced_to_zoho = 1;

                    if (btnElement) {
                        btnElement.outerHTML = '<span class="text-emerald-400 text-xs font-medium">✓ Zoho\'ya Gönderildi</span>';
                    }
                    return true;
                } else {
                    if (!silent) showToast(res.data.message, 'error');
                    if (btnElement) {
                        btnElement.disabled = false;
                        btnElement.textContent = originalText || 'Zoho\'ya Aktar';
                    }
                    throw new Error(res.data.message);
                }
            })
            .catch(err => {
                if (btnElement) {
                    btnElement.disabled = false;
                    btnElement.textContent = originalText || 'Hata! Tekrar Dene';
                }
                if (!silent) {
                    const msg = err.response && err.response.data && err.response.data.message ? err.response.data.message : err.message;
                    showToast('Hata: ' + msg, 'error');
                }
                throw err;
            });
    }

    function showPODetails(id) {
        const modal = document.getElementById('po-modal');
        const tbody = document.getElementById('po-details-body');
        modal.classList.remove('hidden');
        tbody.innerHTML = '<tr><td colspan="5" class="text-center py-8"><div class="loader"></div> <span class="ml-2 text-slate-400">Detaylar yükleniyor...</span></td></tr>';

        const formData = new FormData();
        formData.append('action', 'get_parasut_purchase_order_details');
        formData.append('csrf_token', '<?php echo generateCsrfToken(); ?>');
        formData.append('id', id);

        axios.post('api_handler.php', formData)
            .then(res => {
                if (res.data.success) {
                    if (res.data.data.length > 0) {
                        renderPODetails(res.data.data);
                    } else {
                        tbody.innerHTML = '<tr><td colspan="5" class="text-center py-8"><div class="loader"></div> <span class="ml-2 text-slate-400">Detaylar API\'den getiriliyor...</span></td></tr>';
                        fetchPODetailsFromAPI(id);
                    }
                } else {
                    tbody.innerHTML = '<tr><td colspan="5" class="text-center py-8 text-red-400">Detaylar alınamadı.</td></tr>';
                }
            });
    }

    function fetchPODetailsFromAPI(id) {
        const tbody = document.getElementById('po-details-body');
        const formData = new FormData();
        formData.append('action', 'fetch_parasut_purchase_order_details');
        formData.append('csrf_token', '<?php echo generateCsrfToken(); ?>');
        formData.append('id', id);

        axios.post('api_handler.php', formData).then(res => {
            if (res.data.success) {
                renderPODetails(res.data.data);
            } else {
                tbody.innerHTML = '<tr><td colspan="5" class="text-center py-8 text-red-400">' + res.data.message + '</td></tr>';
            }
        });
    }

    function renderPODetails(items) {
        const tbody = document.getElementById('po-details-body');
        tbody.innerHTML = '';
        if (items.length === 0) {
            tbody.innerHTML = '<tr><td colspan="5" class="text-center py-8 text-slate-500">Detay bulunamadı.</td></tr>';
            return;
        }
        items.forEach(item => {
            const row = `
                <tr>
                    <td class="px-4 py-3 text-sm text-slate-300">${escapeHtml(item.product_name)}</td>
                    <td class="px-4 py-3 text-sm text-slate-300">${item.quantity}</td>
                    <td class="px-4 py-3 text-sm text-slate-300">${parseFloat(item.unit_price).toLocaleString('tr-TR', { minimumFractionDigits: 2 })}</td>
                    <td class="px-4 py-3 text-sm text-slate-300">${item.vat_rate}%</td>
                    <td class="px-4 py-3 text-sm text-emerald-400 font-bold">${parseFloat(item.net_total).toLocaleString('tr-TR', { minimumFractionDigits: 2 })}</td>
                </tr>
            `;
            tbody.innerHTML += row;
        });
    }

    function closeModal() {
        document.getElementById('po-modal').classList.add('hidden');
    }

    function toggleSelectAll(source) {
        const checkboxes = document.querySelectorAll('.po-checkbox');
        checkboxes.forEach(cb => cb.checked = source.checked);
        updateBulkButton();
    }

    async function selectBatchRecords() {
        const batchSize = parseInt(document.getElementById('batch-size').value);
        showToast(`${batchSize} kayıt yükleniyor...`, 'info');

        const tbody = document.getElementById('poTableBody');
        tbody.innerHTML = '<tr><td colspan="7" class="text-center py-8"><div class="loader"></div> <span class="ml-2 text-slate-400">Toplu seçim için kayıtlar yükleniyor...</span></td></tr>';

        const formData = new FormData();
        formData.append('action', 'get_parasut_purchase_orders');
        formData.append('csrf_token', '<?php echo generateCsrfToken(); ?>');
        formData.append('page', 1);
        formData.append('limit', batchSize);

        const filterYear = document.getElementById('filter-year').value;
        if (filterYear) {
            formData.append('year', filterYear);
        }

        const syncFilter = document.querySelector('input[name="sync-filter"]:checked').value;
        let syncStatus = syncFilter === 'synced' ? 'synced' : 'unsynced';
        formData.append('sync_status', syncStatus);

        try {
            const res = await axios.post('api_handler.php', formData);
            if (res.data.success) {
                allPOs = res.data.data;
                currentPOPage = 1;
                totalPOPages = 1;

                if (res.data.meta && res.data.meta.latest_issue_date) {
                    document.getElementById('latest-po-date').textContent = res.data.meta.latest_issue_date;
                }

                renderPOs(allPOs);
                updatePaginationUI();

                setTimeout(() => {
                    const checkboxes = document.querySelectorAll('.po-checkbox');
                    checkboxes.forEach(cb => cb.checked = true);
                    updateBulkButton();
                    showToast(`${checkboxes.length} kayıt seçildi`, 'success');
                }, 100);
            }
        } catch (err) {
            showToast('Hata: ' + err.message, 'error');
            loadPurchaseOrdersFromDB(1);
        }
    }

    async function bulkExportToZoho() {
        const checkboxes = document.querySelectorAll('.po-checkbox:checked');
        const total = checkboxes.length;
        if (total === 0) return;

        if (!confirm(`${total} adet purchase order'ı Zoho'ya aktarmak istediğinize emin misiniz?`)) return;

        let successCount = 0;
        let failCount = 0;

        showToast('Purchase orders aktarılıyor...', 'info');

        for (let i = 0; i < total; i++) {
            const cb = checkboxes[i];
            const poId = cb.value;

            try {
                await exportPOToZoho(poId, null, true);
                successCount++;

                // Update UI
                cb.checked = false;
                cb.parentElement.innerHTML = '✓';

                const btn = document.getElementById('export-btn-' + poId);
                if (btn) {
                    btn.outerHTML = '<span class="text-emerald-400 text-xs font-medium">✓ Zoho\'ya Gönderildi</span>';
                }
            } catch (e) {
                failCount++;
            }

            // Small delay between requests
            if (i < total - 1) {
                await new Promise(resolve => setTimeout(resolve, 1000));
            }
        }

        updateBulkButton();
        showToast(`✅ ${successCount} başarılı, ❌ ${failCount} hata`, successCount > 0 ? 'success' : 'error');
    }

    // Load on page load
    document.addEventListener('DOMContentLoaded', function () {
        loadPurchaseOrdersFromDB(1);
    });
</script>

<?php include 'templates/footer.php'; ?>