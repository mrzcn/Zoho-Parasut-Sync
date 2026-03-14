<?php
$pageTitle = 'Ürün Karşılaştırma';
include 'templates/header.php';
?>

<div class="glass-card rounded-2xl overflow-hidden mb-6">
    <div class="p-6">
        <div class="mb-6">
            <h2 class="text-2xl font-bold text-white mb-2">🔄 Ürün Karşılaştırma</h2>
            <p class="text-slate-400 text-sm">Paraşüt ve Zoho'daki ürünleri karşılaştırın, eksik olanları oluşturun.</p>
        </div>

        <!-- Search Bar and Stats with Filters -->
        <div class="mb-6 flex items-center gap-[10%]">
            <!-- Search Bar (50%) -->
            <div class="relative w-[50%]">
                <div class="absolute inset-y-0 left-0 pl-4 flex items-center pointer-events-none">
                    <svg class="w-5 h-5 text-slate-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                            d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"></path>
                    </svg>
                </div>
                <input type="text" id="searchInput" placeholder="Ürün kodu veya adı ile arama yapın..."
                    class="w-full bg-slate-800/60 border-2 border-slate-700 text-white rounded-xl pl-12 pr-4 py-4 text-sm focus:ring-2 focus:ring-violet-500 focus:border-violet-500 outline-none transition-all placeholder-slate-500"
                    oninput="debounceSearch()">
            </div>

            <!-- Stats with Filters (40%, left-aligned) -->
            <div class="flex items-center gap-3">
                <div onclick="setStatusFilter('')" id="stat-all"
                    class="stat-box min-w-[140px] bg-slate-800/50 rounded-lg px-3 py-2 border-2 border-slate-700 cursor-pointer hover:border-slate-600 transition-all">
                    <div class="text-[9px] text-slate-500 uppercase tracking-widest mb-0.5 font-bold">Toplam</div>
                    <div class="text-xl font-bold text-white font-mono" id="statTotal">0</div>
                </div>
                <div onclick="setStatusFilter('matched')" id="stat-matched"
                    class="stat-box min-w-[140px] bg-emerald-900/20 rounded-lg px-3 py-2 border-2 border-emerald-700/30 cursor-pointer hover:border-emerald-500/50 transition-all">
                    <div class="text-[9px] text-emerald-400 uppercase tracking-widest mb-0.5 font-bold">Eşleşti</div>
                    <div class="text-xl font-bold text-emerald-400 font-mono" id="statMatched">0</div>
                </div>
                <div onclick="setStatusFilter('price_diff')" id="stat-diff"
                    class="stat-box min-w-[140px] bg-amber-900/20 rounded-lg px-3 py-2 border-2 border-amber-700/30 cursor-pointer hover:border-amber-500/50 transition-all">
                    <div class="text-[9px] text-amber-400 uppercase tracking-widest mb-0.5 font-bold">Farklılık</div>
                    <div class="text-xl font-bold text-amber-400 font-mono" id="statDiff">0</div>
                </div>
                <div onclick="setStatusFilter('only_zoho')" id="stat-zoho"
                    class="stat-box min-w-[140px] bg-blue-900/20 rounded-lg px-3 py-2 border-2 border-blue-700/30 cursor-pointer hover:border-blue-500/50 transition-all">
                    <div class="text-[9px] text-blue-400 uppercase tracking-widest mb-0.5 font-bold">Sadece Zoho</div>
                    <div class="text-xl font-bold text-blue-400 font-mono" id="statZoho">0</div>
                </div>
                <div onclick="setStatusFilter('only_parasut')" id="stat-parasut"
                    class="stat-box min-w-[140px] bg-orange-900/20 rounded-lg px-3 py-2 border-2 border-orange-700/30 cursor-pointer hover:border-orange-500/50 transition-all">
                    <div class="text-[9px] text-orange-400 uppercase tracking-widest mb-0.5 font-bold">Sadece Paraşüt
                    </div>
                    <div class="text-xl font-bold text-orange-400 font-mono" id="statParasut">0</div>
                </div>
            </div>
        </div>

        <!-- Products Table -->
        <div class="overflow-x-auto rounded-xl">
            <table class="table-premium min-w-full">
                <thead>
                    <tr>
                        <th class="px-4 py-3 text-left text-xs font-semibold text-slate-400 uppercase cursor-pointer hover:text-white transition-colors"
                            onclick="sortBy('product_code')">
                            Ürün Kodu <span id="sort-product_code" class="ml-1">↕</span>
                        </th>
                        <th class="px-4 py-3 text-left text-xs font-semibold text-slate-400 uppercase cursor-pointer hover:text-white transition-colors"
                            onclick="sortBy('product_name')">
                            Ürün Adı <span id="sort-product_name" class="ml-1">↕</span>
                        </th>
                        <th class="px-4 py-3 text-center text-xs font-semibold text-slate-400 uppercase">Linkler</th>
                        <th class="px-4 py-3 text-left text-xs font-semibold text-slate-400 uppercase cursor-pointer hover:text-white transition-colors"
                            onclick="sortBy('zoho_price')">
                            Zoho Fiyat <span id="sort-zoho_price" class="ml-1">↕</span>
                        </th>
                        <th class="px-4 py-3 text-center text-xs font-semibold text-slate-400 uppercase">Zoho Durum</th>
                        <th class="px-4 py-3 text-left text-xs font-semibold text-slate-400 uppercase cursor-pointer hover:text-white transition-colors"
                            onclick="sortBy('parasut_price')">
                            Paraşüt Fiyat <span id="sort-parasut_price" class="ml-1">↕</span>
                        </th>
                        <th class="px-4 py-3 text-center text-xs font-semibold text-slate-400 uppercase">Paraşüt Durum
                        </th>
                        <th class="px-4 py-3 text-left text-xs font-semibold text-slate-400 uppercase cursor-pointer hover:text-white transition-colors"
                            onclick="sortBy('status')">
                            Durum <span id="sort-status" class="ml-1">↕</span>
                        </th>
                        <th class="px-4 py-3 text-center text-xs font-semibold text-slate-400 uppercase">Düzenle</th>
                    </tr>
                </thead>
                <tbody id="productsTableBody" class="text-slate-300">
                    <tr>
                        <td colspan="9" class="px-6 py-8 text-center text-slate-500">
                            <div class="loader mx-auto mb-2"></div>
                            Ürünler yükleniyor...
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <!-- Pagination -->
        <div id="pagination" class="mt-6 flex justify-center items-center gap-2"></div>
    </div>
</div>

<!-- Edit Product Modal -->
<div id="editModal" class="fixed inset-0 bg-black/70 backdrop-blur-sm hidden items-center justify-center z-50"
    onclick="closeEditModalOnBackdrop(event)">
    <div class="bg-slate-800 rounded-2xl p-6 w-full max-w-3xl mx-4 max-h-[90vh] overflow-y-auto"
        onclick="event.stopPropagation()">
        <div class="flex justify-between items-center mb-6">
            <h3 class="text-2xl font-bold text-white">Ürünü Düzenle</h3>
            <button onclick="closeEditModal()" class="text-slate-400 hover:text-white transition-colors">
                <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12">
                    </path>
                </svg>
            </button>
        </div>

        <!-- Radio Buttons for Data Source -->
        <div class="mb-6 flex gap-4">
            <label class="flex items-center gap-2 cursor-pointer">
                <input type="radio" name="dataSource" value="parasut" checked onchange="switchDataSource('parasut')"
                    class="w-4 h-4 text-violet-600 bg-slate-700 border-slate-600 focus:ring-violet-500">
                <span class="text-white font-medium" id="parasutLabel">Paraşüt Değerleri</span>
            </label>
            <label class="flex items-center gap-2 cursor-pointer">
                <input type="radio" name="dataSource" value="zoho" onchange="switchDataSource('zoho')"
                    class="w-4 h-4 text-violet-600 bg-slate-700 border-slate-600 focus:ring-violet-500">
                <span class="text-white font-medium">Zoho Değerleri</span>
            </label>
        </div>

        <input type="hidden" id="editProductId">
        <input type="hidden" id="editZohoId">
        <input type="hidden" id="editParasutId">
        <input type="hidden" id="editParasutArchived">

        <div class="grid grid-cols-2 gap-4">
            <!-- Product Code -->
            <div>
                <label class="block text-sm font-medium text-slate-300 mb-2">Ürün Kodu</label>
                <input type="text" id="editProductCode"
                    class="w-full bg-slate-700 border border-slate-600 text-white rounded-lg px-4 py-2.5 focus:ring-2 focus:ring-violet-500 focus:border-violet-500 outline-none">
            </div>

            <!-- Currency -->
            <div>
                <label class="block text-sm font-medium text-slate-300 mb-2">Para Birimi</label>
                <select id="editCurrency"
                    class="w-full bg-slate-700 border border-slate-600 text-white rounded-lg px-4 py-2.5 focus:ring-2 focus:ring-violet-500 focus:border-violet-500 outline-none">
                    <option value="TRY">TRY</option>
                    <option value="USD">USD</option>
                    <option value="EUR">EUR</option>
                    <option value="GBP">GBP</option>
                </select>
            </div>

            <!-- Product Name (Full Width) -->
            <div class="col-span-2">
                <label class="block text-sm font-medium text-slate-300 mb-2">Ürün Adı</label>
                <input type="text" id="editProductName"
                    class="w-full bg-slate-700 border border-slate-600 text-white rounded-lg px-4 py-2.5 focus:ring-2 focus:ring-violet-500 focus:border-violet-500 outline-none">
                <p class="text-xs text-slate-500 mt-1" id="editWarningText"></p>
            </div>

            <!-- Sale Price -->
            <div>
                <label class="block text-sm font-medium text-slate-300 mb-2">Satış Fiyatı</label>
                <input type="number" step="0.01" id="editSalePrice"
                    class="w-full bg-slate-700 border border-slate-600 text-white rounded-lg px-4 py-2.5 focus:ring-2 focus:ring-violet-500 focus:border-violet-500 outline-none">
            </div>

            <!-- Purchase Price -->
            <div>
                <label class="block text-sm font-medium text-slate-300 mb-2">Alış Fiyatı</label>
                <input type="number" step="0.01" id="editPurchasePrice"
                    class="w-full bg-slate-700 border border-slate-600 text-white rounded-lg px-4 py-2.5 focus:ring-2 focus:ring-violet-500 focus:border-violet-500 outline-none">
            </div>

            <!-- Tax Rate -->
            <div>
                <label class="block text-sm font-medium text-slate-300 mb-2">Vergi Oranı (%)</label>
                <select id="editTaxRate"
                    class="w-full bg-slate-700 border border-slate-600 text-white rounded-lg px-4 py-2.5 focus:ring-2 focus:ring-violet-500 focus:border-violet-500 outline-none">
                    <option value="0">0%</option>
                    <option value="1">1%</option>
                    <option value="18">18%</option>
                    <option value="20">20%</option>
                </select>
            </div>

            <!-- Stock Quantity -->
            <div>
                <label class="block text-sm font-medium text-slate-300 mb-2">Stok Adedi</label>
                <input type="number" step="1" id="editStockQuantity"
                    class="w-full bg-slate-700 border border-slate-600 text-white rounded-lg px-4 py-2.5 focus:ring-2 focus:ring-violet-500 focus:border-violet-500 outline-none">
                <p class="text-xs text-slate-500 mt-1">Girdiğiniz değer her iki sistemde "Mevcut Stok" olarak set
                    edilecektir.</p>
            </div>
        </div>

        <!-- Action Buttons -->
        <div class="flex gap-3 mt-6">
            <button onclick="closeEditModal()"
                class="flex-1 px-6 py-3 bg-slate-700 hover:bg-slate-600 text-white rounded-lg font-medium transition-colors">
                İptal
            </button>
            <button onclick="saveProductChanges()"
                class="flex-1 px-6 py-3 bg-violet-600 hover:bg-violet-700 text-white rounded-lg font-medium transition-colors">
                Kaydet ve Her İki Sistemde Güncelle
            </button>
        </div>
    </div>
</div>

<script>
    // CSRF Token Setup
    axios.defaults.headers.common['X-CSRF-TOKEN'] = '<?php echo generateCsrfToken(); ?>';

    let currentPage = 1;
    let currentSort = { field: 'product_code', order: 'ASC' };
    let currentStatusFilter = '';
    let searchTimeout = null;
    let allProducts = [];

    document.addEventListener('DOMContentLoaded', () => {
        loadProducts();
    });

    function debounceSearch() {
        clearTimeout(searchTimeout);
        searchTimeout = setTimeout(() => {
            currentPage = 1;
            loadProducts();
        }, 300);
    }

    function setStatusFilter(status) {
        currentStatusFilter = status;
        currentPage = 1;

        // Update stat box styling
        document.querySelectorAll('.stat-box').forEach(box => {
            box.classList.remove('border-violet-500', 'ring-2', 'ring-violet-500/50', 'scale-105');
        });

        const activeBox = status === '' ? 'stat-all' : `stat-${status}`;
        const box = document.getElementById(activeBox);
        if (box) {
            box.classList.add('border-violet-500', 'ring-2', 'ring-violet-500/50', 'scale-105');
        }

        loadProducts();
    }

    function sortBy(field) {
        if (currentSort.field === field) {
            currentSort.order = currentSort.order === 'ASC' ? 'DESC' : 'ASC';
        } else {
            currentSort.field = field;
            currentSort.order = 'ASC';
        }
        updateSortIndicators();
        loadProducts();
    }

    function updateSortIndicators() {
        // Reset all indicators
        document.querySelectorAll('[id^="sort-"]').forEach(el => {
            el.textContent = '↕';
            el.className = 'ml-1 text-slate-600';
        });

        // Set active indicator
        const indicator = document.getElementById('sort-' + currentSort.field);
        if (indicator) {
            indicator.textContent = currentSort.order === 'ASC' ? '↑' : '↓';
            indicator.className = 'ml-1 text-violet-400';
        }
    }

    async function loadProducts() {
        const tbody = document.getElementById('productsTableBody');
        tbody.innerHTML = '<tr><td colspan="9" class="px-6 py-8 text-center text-slate-500"><div class="loader mx-auto mb-2"></div>Ürünler yükleniyor...</td></tr>';

        const search = document.getElementById('searchInput').value;

        try {
            const params = new URLSearchParams();
            params.append('action', 'get_products_comparison');
            params.append('page', currentPage);
            params.append('limit', 50);
            params.append('search', search);
            params.append('status', currentStatusFilter);
            params.append('sort_field', currentSort.field);
            params.append('sort_order', currentSort.order);
            params.append('csrf_token', '<?php echo generateCsrfToken(); ?>');

            const res = await axios.post('api_handler.php', params);

            if (res.data.success) {
                allProducts = res.data.data;
                renderProducts(allProducts);
                updateStats(res.data.stats);
                updatePagination(res.data.meta);
            } else {
                tbody.innerHTML = '<tr><td colspan="9" class="px-6 py-8 text-center text-red-400">Hata: ' + res.data.message + '</td></tr>';
            }
        } catch (e) {
            tbody.innerHTML = '<tr><td colspan="9" class="px-6 py-8 text-center text-red-400">Bağlantı hatası: ' + e.message + '</td></tr>';
        }
    }

    function renderProducts(products) {
        const tbody = document.getElementById('productsTableBody');

        if (!products || products.length === 0) {
            tbody.innerHTML = '<tr><td colspan="9" class="px-6 py-8 text-center text-slate-500">Ürün bulunamadı.</td></tr>';
            return;
        }

        tbody.innerHTML = '';

        products.forEach(p => {
            const row = document.createElement('tr');
            row.className = 'hover:bg-slate-700/30 transition-colors border-b border-slate-800/50 last:border-0';

            // Product Code
            const codeCell = document.createElement('td');
            codeCell.className = 'px-4 py-3 text-sm font-mono text-violet-400';
            codeCell.textContent = p.product_code || '-';
            row.appendChild(codeCell);

            // Product Name
            const nameCell = document.createElement('td');
            nameCell.className = 'px-4 py-3 text-sm text-white font-medium';
            nameCell.innerHTML = `<span class="truncate max-w-[300px] block" title="${escapeHtml(p.product_name || '')}">${escapeHtml(p.product_name || '-')}</span>`;
            row.appendChild(nameCell);

            // Links Column (Z and P buttons)
            const linksCell = document.createElement('td');
            linksCell.className = 'px-4 py-3 text-center text-sm';
            let linksHtml = '<div class="flex items-center justify-center gap-2">';
            if (p.zoho_id) {
                linksHtml += `<a href="https://crm.zoho.com/crm/org636648212/tab/Products/${p.zoho_id}" target="_blank" class="text-xs bg-blue-500/10 text-blue-400 px-2.5 py-1 rounded hover:bg-blue-500/20 transition-colors border border-blue-500/20 font-bold" title="Zoho'da Görüntüle">Z</a>`;
            } else {
                linksHtml += '<span class="text-xs text-slate-600 px-2.5 py-1">-</span>';
            }
            if (p.parasut_id) {
                linksHtml += `<a href="https://uygulama.parasut.com/136555/hizmet-ve-urunler/${p.parasut_id}" target="_blank" class="text-xs bg-orange-500/10 text-orange-400 px-2.5 py-1 rounded hover:bg-orange-500/20 transition-colors border border-orange-500/20 font-bold" title="Paraşüt'te Görüntüle">P</a>`;
            } else {
                linksHtml += '<span class="text-xs text-slate-600 px-2.5 py-1">-</span>';
            }
            linksHtml += '</div>';
            linksCell.innerHTML = linksHtml;
            row.appendChild(linksCell);

            // Zoho Price
            const zohoPriceCell = document.createElement('td');
            zohoPriceCell.className = 'px-4 py-3 text-sm';
            if (p.zoho_price !== null) {
                zohoPriceCell.innerHTML = `<span class="text-emerald-400 font-mono">${parseFloat(p.zoho_price).toLocaleString('tr-TR', { minimumFractionDigits: 2 })}</span> <span class="text-xs text-slate-500">${p.zoho_currency || 'TRY'}</span>`;
            } else {
                zohoPriceCell.innerHTML = '<span class="text-slate-600">-</span>';
            }
            row.appendChild(zohoPriceCell);

            // Zoho Status (Active/Archived)
            const zohoStatusCell = document.createElement('td');
            zohoStatusCell.className = 'px-4 py-3 text-center text-sm';
            if (p.zoho_id) {
                // Check if active (1 or '1')
                const isActive = p.zoho_is_active == 1;
                zohoStatusCell.innerHTML = isActive
                    ? '<span class="px-2 py-1 rounded text-[10px] bg-emerald-500/20 text-emerald-400 border border-emerald-500/20">Aktif</span>'
                    : '<span class="px-2 py-1 rounded text-[10px] bg-slate-500/20 text-slate-400 border border-slate-500/20">Arşivlendi</span>';
            } else {
                zohoStatusCell.innerHTML = '<span class="text-slate-600">-</span>';
            }
            row.appendChild(zohoStatusCell);

            // Parasut Price
            const parasutPriceCell = document.createElement('td');
            parasutPriceCell.className = 'px-4 py-3 text-sm';
            if (p.parasut_price !== null) {
                parasutPriceCell.innerHTML = `<span class="text-emerald-400 font-mono">${parseFloat(p.parasut_price).toLocaleString('tr-TR', { minimumFractionDigits: 2 })}</span> <span class="text-xs text-slate-500">${p.parasut_currency || 'TRY'}</span>`;
            } else {
                parasutPriceCell.innerHTML = '<span class="text-slate-600">-</span>';
            }
            row.appendChild(parasutPriceCell);

            // Parasut Status (Active/Archived)
            const parasutStatusCell = document.createElement('td');
            parasutStatusCell.className = 'px-4 py-3 text-center text-sm';
            if (p.parasut_id) {
                // Check if archived (1 or '1')
                const isArchived = p.parasut_is_archived == 1;
                parasutStatusCell.innerHTML = !isArchived
                    ? '<span class="px-2 py-1 rounded text-[10px] bg-emerald-500/20 text-emerald-400 border border-emerald-500/20">Aktif</span>'
                    : '<span class="px-2 py-1 rounded text-[10px] bg-slate-500/20 text-slate-400 border border-slate-500/20">Arşivlendi</span>';
            } else {
                parasutStatusCell.innerHTML = '<span class="text-slate-600">-</span>';
            }
            row.appendChild(parasutStatusCell);

            // Status
            const statusCell = document.createElement('td');
            statusCell.className = 'px-4 py-3 text-sm';
            statusCell.innerHTML = getStatusBadge(p.status, p.product_code, p.product_name, p.zoho_id, p.parasut_id, p.parasut_price);
            row.appendChild(statusCell);

            // Edit Button
            const editCell = document.createElement('td');
            editCell.className = 'px-4 py-3 text-center';
            editCell.innerHTML = `
                <button onclick='openEditModal(${JSON.stringify(p)})' 
                    class="text-violet-400 hover:text-violet-300 transition-colors p-2 hover:bg-slate-700/50 rounded" 
                    title="Düzenle">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" 
                            d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"></path>
                    </svg>
                </button>
            `;
            row.appendChild(editCell);

            tbody.appendChild(row);
        });
    }

    function getStatusBadge(status, code, name, zohoId, parasutId, parasutPrice) {
        switch (status) {
            case 'matched':
                return '<span class="px-2 py-1 rounded text-[10px] bg-emerald-500/20 text-emerald-400 border border-emerald-500/20">Eşleşti</span>';
            case 'price_diff':
                return `<div class="flex items-center gap-2">
                    <span class="px-2 py-1 rounded text-[10px] bg-amber-500/20 text-amber-400 border border-amber-500/20">Farklılık Var</span>
                    <button onclick="updatePriceInZoho('${escapeHtml(code)}', '${zohoId}', ${parasutPrice})" class="text-[10px] px-2 py-1 bg-blue-500/10 text-blue-400 hover:bg-blue-500/20 rounded border border-blue-500/20 transition-colors">Zoho'da Güncelle</button>
                </div>`;
            case 'only_zoho':
                return `<div class="flex items-center gap-2">
                    <span class="px-2 py-1 rounded text-[10px] bg-blue-500/20 text-blue-400 border border-blue-500/20">Sadece Zoho</span>
                </div>`;
            case 'only_parasut':
                return `<div class="flex items-center gap-2">
                    <span class="px-2 py-1 rounded text-[10px] bg-orange-500/20 text-orange-400 border border-orange-500/20">Sadece Paraşüt</span>
                    <button onclick="createInZoho('${escapeHtml(code)}', '${escapeHtml(name)}', '${parasutId}')" class="text-[10px] px-2 py-1 bg-blue-500/10 text-blue-400 hover:bg-blue-500/20 rounded border border-blue-500/20 transition-colors">Zoho'da Oluştur</button>
                </div>`;
            default:
                return '<span class="text-slate-600">-</span>';
        }
    }

    function updateStats(stats) {
        document.getElementById('statTotal').textContent = (stats.total || 0).toLocaleString('tr-TR');
        document.getElementById('statMatched').textContent = (stats.matched || 0).toLocaleString('tr-TR');
        document.getElementById('statDiff').textContent = (stats.price_diff || 0).toLocaleString('tr-TR');
        document.getElementById('statZoho').textContent = (stats.only_zoho || 0).toLocaleString('tr-TR');
        document.getElementById('statParasut').textContent = (stats.only_parasut || 0).toLocaleString('tr-TR');
    }

    function updatePagination(meta) {
        const container = document.getElementById('pagination');
        if (!meta || meta.total_pages <= 1) {
            container.innerHTML = '';
            return;
        }

        const current = parseInt(meta.current_page);
        const total = parseInt(meta.total_pages);
        let html = '';

        // Previous
        if (current > 1) {
            html += `<button onclick="changePage(${current - 1})" class="min-w-[90px] px-3 py-2 rounded-lg bg-slate-700 hover:bg-slate-600 text-white text-sm transition-colors">← Önceki</button>`;
        } else {
            html += `<button disabled class="min-w-[90px] px-3 py-2 rounded-lg bg-slate-800 text-slate-600 text-sm cursor-not-allowed">← Önceki</button>`;
        }

        // Pages
        let startPage = Math.max(1, current - 2);
        let endPage = Math.min(total, current + 2);

        if (startPage > 1) {
            html += `<button onclick="changePage(1)" class="min-w-[40px] px-3 py-2 rounded-lg bg-slate-700 hover:bg-slate-600 text-white text-sm transition-colors">1</button>`;
            if (startPage > 2) html += `<span class="text-slate-500 px-2">...</span>`;
        }

        for (let i = startPage; i <= endPage; i++) {
            if (i === current) {
                html += `<button class="min-w-[40px] px-3 py-2 rounded-lg bg-violet-600 text-white text-sm font-bold">${i}</button>`;
            } else {
                html += `<button onclick="changePage(${i})" class="min-w-[40px] px-3 py-2 rounded-lg bg-slate-700 hover:bg-slate-600 text-white text-sm transition-colors">${i}</button>`;
            }
        }

        if (endPage < total) {
            if (endPage < total - 1) html += `<span class="text-slate-500 px-2">...</span>`;
            html += `<button onclick="changePage(${total})" class="min-w-[40px] px-3 py-2 rounded-lg bg-slate-700 hover:bg-slate-600 text-white text-sm transition-colors">${total}</button>`;
        }

        // Next
        if (current < total) {
            html += `<button onclick="changePage(${current + 1})" class="min-w-[90px] px-3 py-2 rounded-lg bg-slate-700 hover:bg-slate-600 text-white text-sm transition-colors">Sonraki →</button>`;
        } else {
            html += `<button disabled class="min-w-[90px] px-3 py-2 rounded-lg bg-slate-800 text-slate-600 text-sm cursor-not-allowed">Sonraki →</button>`;
        }

        container.innerHTML = html;
    }

    function changePage(page) {
        currentPage = page;
        loadProducts();
    }

    async function updatePriceInZoho(code, zohoId, newPrice) {
        if (!confirm(`"${code}" ürününün fiyatını Zoho'da güncellemek istiyor musunuz?\n\nYeni Fiyat: ${parseFloat(newPrice).toLocaleString('tr-TR', { minimumFractionDigits: 2 })} TRY`)) return;

        showToast('Fiyat güncelleniyor...', 'info');

        try {
            const params = new URLSearchParams();
            params.append('action', 'update_price_in_zoho');
            params.append('zoho_id', zohoId);
            params.append('new_price', newPrice);
            params.append('csrf_token', '<?php echo generateCsrfToken(); ?>');

            const res = await axios.post('api_handler.php', params);

            if (res.data.success) {
                showToast(res.data.message, 'success');
                loadProducts();
            } else {
                showToast('Hata: ' + res.data.message, 'error');
            }
        } catch (e) {
            showToast('Bağlantı hatası: ' + e.message, 'error');
        }
    }

    async function createInZoho(code, name, parasutId) {
        if (!confirm(`"${name}" ürününü Zoho'da oluşturmak istiyor musunuz?`)) return;

        showToast('Ürün Zoho\'da oluşturuluyor...', 'info');

        try {
            const params = new URLSearchParams();
            params.append('action', 'create_product_in_zoho_from_parasut');
            params.append('parasut_id', parasutId);
            params.append('csrf_token', '<?php echo generateCsrfToken(); ?>');

            const res = await axios.post('api_handler.php', params);

            if (res.data.success) {
                showToast(res.data.message, 'success');
                loadProducts();
            } else {
                showToast('Hata: ' + res.data.message, 'error');
            }
        } catch (e) {
            showToast('Bağlantı hatası: ' + e.message, 'error');
        }
    }



    // Edit Modal Functions
    let currentProductData = {};
    let zohoData = {};
    let parasutData = {};

    function openEditModal(product) {
        currentProductData = product;

        // Store data for switching
        parasutData = {
            code: product.product_code || '',
            name: product.product_name || '',
            currency: product.parasut_currency || 'TRY',
            salePrice: product.parasut_price || 0,
            purchasePrice: product.parasut_list_price || 0,
            taxRate: product.parasut_vat_rate || 18,
            stock: product.parasut_initial_stock_count || 0
        };

        zohoData = {
            code: product.product_code || '',
            name: product.product_name || '',
            currency: product.zoho_currency || 'TRY',
            salePrice: product.zoho_price || 0,
            purchasePrice: product.zoho_purchase_price || 0,
            taxRate: product.zoho_tax_rate || 18,
            stock: product.zoho_quantity_in_stock || 0
        };

        // Set hidden fields
        document.getElementById('editProductId').value = product.product_id || '';
        document.getElementById('editZohoId').value = product.zoho_id || '';
        document.getElementById('editParasutId').value = product.parasut_id || '';
        document.getElementById('editParasutArchived').value = product.parasut_is_archived ? '1' : '0';

        // Update Parasut label if archived
        const parasutLabel = document.getElementById('parasutLabel');
        if (product.parasut_is_archived) {
            parasutLabel.textContent = 'Paraşüt Değerleri (Arşivlenmiş)';
        } else {
            parasutLabel.textContent = 'Paraşüt Değerleri';
        }

        // Default to Parasut data
        document.querySelector('input[name="dataSource"][value="parasut"]').checked = true;
        switchDataSource('parasut');

        // Show modal
        const modal = document.getElementById('editModal');
        modal.classList.remove('hidden');
        modal.classList.add('flex');
    }

    function closeEditModal() {
        const modal = document.getElementById('editModal');
        modal.classList.add('hidden');
        modal.classList.remove('flex');
    }

    function closeEditModalOnBackdrop(event) {
        if (event.target.id === 'editModal') {
            closeEditModal();
        }
    }

    function switchDataSource(source) {
        const data = source === 'zoho' ? zohoData : parasutData;

        document.getElementById('editProductCode').value = data.code;
        document.getElementById('editProductName').value = data.name;
        document.getElementById('editCurrency').value = data.currency;
        document.getElementById('editSalePrice').value = parseFloat(data.salePrice).toFixed(2);
        document.getElementById('editPurchasePrice').value = parseFloat(data.purchasePrice).toFixed(2);
        document.getElementById('editTaxRate').value = data.taxRate;
        document.getElementById('editStockQuantity').value = parseInt(data.stock);

        // Show warning if there are differences
        const warningText = document.getElementById('editWarningText');
        if (source === 'zoho' && JSON.stringify(zohoData) !== JSON.stringify(parasutData)) {
            warningText.textContent = 'Bu değişiklik hem Paraşüt hem Zoho\'da güncellenecektir.';
            warningText.className = 'text-xs text-amber-400 mt-1';
        } else if (source === 'parasut') {
            warningText.textContent = 'Bu değişiklik hem Paraşüt hem Zoho\'da güncellenecektir.';
            warningText.className = 'text-xs text-slate-500 mt-1';
        }
    }

    async function saveProductChanges() {
        const productCode = document.getElementById('editProductCode').value.trim();
        const productName = document.getElementById('editProductName').value.trim();
        const currency = document.getElementById('editCurrency').value;
        const salePrice = parseFloat(document.getElementById('editSalePrice').value);
        const purchasePrice = parseFloat(document.getElementById('editPurchasePrice').value);
        const taxRate = parseFloat(document.getElementById('editTaxRate').value);
        const stockQuantity = parseInt(document.getElementById('editStockQuantity').value);

        const zohoId = document.getElementById('editZohoId').value;
        const parasutId = document.getElementById('editParasutId').value;
        const isArchived = document.getElementById('editParasutArchived').value === '1';

        if (!productCode || !productName) {
            showToast('Ürün kodu ve adı zorunludur', 'error');
            return;
        }

        if (!confirm(`Bu ürünü her iki sistemde de güncellemek istediğinizden emin misiniz?\n\nÜrün: ${productName}\nZoho: ${zohoId ? 'Güncellenecek' : 'Oluşturulmayacak'}\nParaşüt: ${parasutId ? 'Güncellenecek' : 'Oluşturulmayacak'}`)) {
            return;
        }

        showToast('Ürün güncelleniyor...', 'info');

        try {
            const params = new URLSearchParams();
            params.append('action', 'update_product_in_both_systems');
            params.append('zoho_id', zohoId);
            params.append('parasut_id', parasutId);
            params.append('product_code', productCode);
            params.append('product_name', productName);
            params.append('currency', currency);
            params.append('sale_price', salePrice);
            params.append('purchase_price', purchasePrice);
            params.append('tax_rate', taxRate);
            params.append('stock_quantity', stockQuantity);
            params.append('is_archived', isArchived ? '1' : '0');
            params.append('csrf_token', '<?php echo generateCsrfToken(); ?>');

            const res = await axios.post('api_handler.php', params);

            if (res.data.success) {
                showToast(res.data.message, 'success');
                closeEditModal();
                loadProducts();
            } else {
                showToast('Hata: ' + res.data.message, 'error');
            }
        } catch (e) {
            showToast('Bağlantı hatası: ' + e.message, 'error');
        }
    }

    function escapeHtml(text) {
        if (text === null || text === undefined) return '';
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }
</script>

<style>
    .loader {
        border: 3px solid rgba(255, 255, 255, 0.1);
        border-top: 3px solid white;
        border-radius: 50%;
        width: 30px;
        height: 30px;
        animation: spin 0.8s linear infinite;
        display: inline-block;
    }

    @keyframes spin {
        0% {
            transform: rotate(0deg);
        }

        100% {
            transform: rotate(360deg);
        }
    }

    .stat-box {
        transform: scale(1);
        transition: all 0.2s ease;
    }

    .stat-box:hover {
        transform: scale(1.02);
    }

    .stat-box.scale-105 {
        transform: scale(1.05);
    }
</style>

<?php include 'templates/footer.php'; ?>