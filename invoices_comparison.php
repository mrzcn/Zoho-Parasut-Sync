<?php
$pageTitle = 'Fatura Karşılaştırma';
include 'templates/header.php';
?>

<div class="glass-card rounded-2xl overflow-hidden mb-6">
    <div class="p-6">
        <div class="mb-6">
            <h2 class="text-2xl font-bold text-white mb-2">🧾 Fatura Karşılaştırma</h2>
            <p class="text-slate-400 text-sm">Paraşüt ve Zoho'daki faturaları karşılaştırın.</p>
        </div>

        <!-- Search Bar and Stats with Filters -->
        <div class="mb-6 flex flex-col xl:flex-row items-start xl:items-center gap-4 justify-between">
            <!-- Search Bar -->
            <div class="relative w-full xl:w-96 flex-shrink-0">
                <div class="absolute inset-y-0 left-0 pl-4 flex items-center pointer-events-none">
                    <svg class="w-5 h-5 text-slate-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                            d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"></path>
                    </svg>
                </div>
                <input type="text" id="searchInput" placeholder="Fatura No, Müşteri Adı ile arama yapın..."
                    class="w-full bg-slate-800/60 border-2 border-slate-700 text-white rounded-xl pl-12 pr-4 py-3 text-sm focus:ring-2 focus:ring-violet-500 focus:border-violet-500 outline-none transition-all placeholder-slate-500"
                    oninput="debounceSearch()">
            </div>

            <!-- Stats with Filters -->
            <div class="w-full xl:w-auto overflow-x-auto pb-2 xl:pb-0">
                <div class="flex items-center gap-3 min-w-max">
                    <select id="filter-year" onchange="currentPage=1; loadInvoices();"
                        class="bg-slate-800 border-2 border-slate-700 text-white rounded-lg px-4 py-3 text-sm font-bold focus:ring-2 focus:ring-violet-500/50 focus:border-violet-500 transition-all cursor-pointer hover:border-slate-600">
                        <option value="2026" selected>2026</option>
                        <option value="2025">2025</option>
                        <option value="2024">2024</option>
                        <option value="2023">2023</option>
                        <option value="2022">2022</option>
                        <option value="ALL">Tüm Yıllar</option>
                    </select>

                    <div class="h-10 w-px bg-slate-700 mx-1"></div>

                    <div onclick="setStatusFilter('')" id="stat-all"
                        class="stat-box min-w-[100px] bg-slate-800/50 rounded-lg px-3 py-2 border-2 border-slate-700 cursor-pointer hover:border-slate-600 transition-all">
                        <div class="text-[9px] text-slate-500 uppercase tracking-widest mb-0.5 font-bold">Toplam</div>
                        <div class="text-sm font-bold text-white font-mono" id="statTotal">0</div>
                    </div>
                    <div onclick="setStatusFilter('matched')" id="stat-matched"
                        class="stat-box min-w-[100px] bg-emerald-900/20 rounded-lg px-3 py-2 border-2 border-emerald-700/30 cursor-pointer hover:border-emerald-500/50 transition-all">
                        <div class="text-[9px] text-emerald-400 uppercase tracking-widest mb-0.5 font-bold">Eşleşti
                        </div>
                        <div class="text-sm font-bold text-emerald-400 font-mono" id="statMatched">0</div>
                    </div>
                    <div onclick="setStatusFilter('price_diff')" id="stat-diff"
                        class="stat-box min-w-[100px] bg-amber-900/20 rounded-lg px-3 py-2 border-2 border-amber-700/30 cursor-pointer hover:border-amber-500/50 transition-all">
                        <div class="text-[9px] text-amber-400 uppercase tracking-widest mb-0.5 font-bold">Farklılık
                        </div>
                        <div class="text-sm font-bold text-amber-400 font-mono" id="statDiff">0</div>
                    </div>
                    <div onclick="setStatusFilter('only_zoho')" id="stat-zoho"
                        class="stat-box min-w-[100px] bg-blue-900/20 rounded-lg px-3 py-2 border-2 border-blue-700/30 cursor-pointer hover:border-blue-500/50 transition-all">
                        <div class="text-[9px] text-blue-400 uppercase tracking-widest mb-0.5 font-bold">Sadece Zoho
                        </div>
                        <div class="text-sm font-bold text-blue-400 font-mono" id="statZoho">0</div>
                    </div>
                    <div onclick="setStatusFilter('only_parasut')" id="stat-parasut"
                        class="stat-box min-w-[100px] bg-orange-900/20 rounded-lg px-3 py-2 border-2 border-orange-700/30 cursor-pointer hover:border-orange-500/50 transition-all">
                        <div class="text-[9px] text-orange-400 uppercase tracking-widest mb-0.5 font-bold">Sadece
                            Paraşüt</div>
                        <div class="text-sm font-bold text-orange-400 font-mono" id="statParasut">0</div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Invoices Table -->
        <div class="overflow-x-auto rounded-xl">
            <table class="table-premium min-w-full">
                <thead>
                    <tr>
                        <th class="px-4 py-3 text-left text-xs font-semibold text-slate-400 uppercase cursor-pointer hover:text-white transition-colors"
                            onclick="sortBy('issue_date')">
                            Tarih <span id="sort-issue_date" class="ml-1">↓</span>
                        </th>
                        <th class="px-4 py-3 text-left text-xs font-semibold text-slate-400 uppercase cursor-pointer hover:text-white transition-colors"
                            onclick="sortBy('invoice_number')">
                            Fatura No <span id="sort-invoice_number" class="ml-1">↕</span>
                        </th>
                        <th class="px-4 py-3 text-left text-xs font-semibold text-slate-400 uppercase cursor-pointer hover:text-white transition-colors"
                            onclick="sortBy('customer_name')">
                            Müşteri Adı <span id="sort-customer_name" class="ml-1">↕</span>
                        </th>
                        <th class="px-4 py-3 text-left text-xs font-semibold text-slate-400 uppercase">Açıklama</th>
                        <th class="px-4 py-3 text-center text-xs font-semibold text-slate-400 uppercase">Linkler
                        </th>
                        <th class="px-4 py-3 text-right text-xs font-semibold text-slate-400 uppercase cursor-pointer hover:text-white transition-colors"
                            onclick="sortBy('zoho_total')">
                            Zoho Tutar <span id="sort-zoho_total" class="ml-1">↕</span>
                        </th>
                        <th class="px-4 py-3 text-center text-xs font-semibold text-slate-400 uppercase">Zoho Durum
                        </th>
                        <th class="px-4 py-3 text-right text-xs font-semibold text-slate-400 uppercase cursor-pointer hover:text-white transition-colors"
                            onclick="sortBy('parasut_total')">
                            Paraşüt Tutar <span id="sort-parasut_total" class="ml-1">↕</span>
                        </th>
                        <th class="px-4 py-3 text-center text-xs font-semibold text-slate-400 uppercase">Paraşüt
                            Durum
                        </th>
                        <th class="px-4 py-3 text-left text-xs font-semibold text-slate-400 uppercase cursor-pointer hover:text-white transition-colors"
                            onclick="sortBy('status')">
                            Durum <span id="sort-status" class="ml-1">↕</span>
                        </th>
                    </tr>
                </thead>
                <tbody id="invoicesTableBody" class="text-slate-300">
                    <tr>
                        <td colspan="10" class="px-6 py-8 text-center text-slate-500">
                            <div class="loader mx-auto mb-2"></div>
                            Faturalar yükleniyor...
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <!-- Pagination -->
        <div id="pagination" class="mt-6 flex justify-center items-center gap-2"></div>
    </div>
</div>

<script>
    // CSRF Token Setup
    axios.defaults.headers.common['X-CSRF-TOKEN'] = '<?php echo generateCsrfToken(); ?>';

    let currentPage = 1;
    let currentSort = { field: 'issue_date', order: 'DESC' };
    let currentStatusFilter = '';
    let searchTimeout = null;
    let allInvoices = [];

    document.addEventListener('DOMContentLoaded', () => {
        loadInvoices();
    });

    function debounceSearch() {
        clearTimeout(searchTimeout);
        searchTimeout = setTimeout(() => {
            currentPage = 1;
            loadInvoices();
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

        loadInvoices();
    }

    function sortBy(field) {
        if (currentSort.field === field) {
            currentSort.order = currentSort.order === 'ASC' ? 'DESC' : 'ASC';
        } else {
            currentSort.field = field;
            currentSort.order = 'ASC';
        }
        updateSortIndicators();
        loadInvoices();
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

    async function loadInvoices() {
        const tbody = document.getElementById('invoicesTableBody');
        tbody.innerHTML = '<tr><td colspan="10" class="px-6 py-8 text-center text-slate-500"><div class="loader mx-auto mb-2"></div>Faturalar yükleniyor...</td></tr>';

        const search = document.getElementById('searchInput').value;

        try {
            const params = new URLSearchParams();
            params.append('action', 'get_invoices_comparison');
            params.append('page', currentPage);
            params.append('limit', 50);
            params.append('search', search);
            params.append('status', currentStatusFilter);
            params.append('year', document.getElementById('filter-year').value);
            params.append('sort_field', currentSort.field);
            params.append('sort_order', currentSort.order);
            params.append('csrf_token', '<?php echo generateCsrfToken(); ?>');

            const res = await axios.post('api_handler.php', params);

            if (res.data.success) {
                allInvoices = res.data.data;
                renderInvoices(allInvoices);
                updateStats(res.data.stats);
                updatePagination(res.data.meta);
            } else {
                tbody.innerHTML = '<tr><td colspan="10" class="px-6 py-8 text-center text-red-400">Hata: ' + res.data.message + '</td></tr>';
            }
        } catch (e) {
            tbody.innerHTML = '<tr><td colspan="10" class="px-6 py-8 text-center text-red-400">Bağlantı hatası: ' + e.message + '</td></tr>';
        }
    }

    function renderInvoices(invoices) {
        const tbody = document.getElementById('invoicesTableBody');

        if (!invoices || invoices.length === 0) {
            tbody.innerHTML = '<tr><td colspan="10" class="px-6 py-8 text-center text-slate-500">Fatura bulunamadı.</td></tr>';
            return;
        }

        tbody.innerHTML = '';

        invoices.forEach(inv => {
            const row = document.createElement('tr');
            row.className = 'hover:bg-slate-700/30 transition-colors border-b border-slate-800/50 last:border-0';

            // Date
            const dateCell = document.createElement('td');
            dateCell.className = 'px-4 py-3 text-sm text-slate-300 whitespace-nowrap';
            const dateStr = inv.issue_date ? new Date(inv.issue_date).toLocaleDateString('tr-TR') : '-';
            dateCell.textContent = dateStr;
            row.appendChild(dateCell);

            // Invoice Number
            const noCell = document.createElement('td');
            noCell.className = 'px-4 py-3 text-sm font-mono text-violet-400 whitespace-nowrap';
            noCell.textContent = inv.invoice_number || '-';
            row.appendChild(noCell);

            // Customer Name
            const customerCell = document.createElement('td');
            customerCell.className = 'px-4 py-3 text-sm text-white font-medium';
            customerCell.innerHTML = `<span class="truncate max-w-[200px] block" title="${escapeHtml(inv.customer_name || '')}">${escapeHtml(inv.customer_name || '-')}</span>`;
            row.appendChild(customerCell);

            // Description
            const descCell = document.createElement('td');
            descCell.className = 'px-4 py-3 text-sm text-slate-400';
            descCell.innerHTML = `<span class="truncate max-w-[200px] block" title="${escapeHtml(inv.description || '')}">${escapeHtml(inv.description || '-')}</span>`;
            row.appendChild(descCell);

            // Links
            const linksCell = document.createElement('td');
            linksCell.className = 'px-4 py-3 text-center text-sm';
            let linksHtml = '<div class="flex items-center justify-center gap-2">';
            if (inv.zoho_id) {
                linksHtml += `<a href="https://crm.zoho.com/crm/org636648212/tab/Invoices/${inv.zoho_id}" target="_blank" class="text-xs bg-blue-500/10 text-blue-400 px-2.5 py-1 rounded hover:bg-blue-500/20 transition-colors border border-blue-500/20 font-bold" title="Zoho'da Görüntüle">Z</a>`;
            } else {
                linksHtml += '<span class="text-xs text-slate-600 px-2.5 py-1">-</span>';
            }
            if (inv.parasut_id) {
                linksHtml += `<a href="https://uygulama.parasut.com/136555/satislar/${inv.parasut_id}" target="_blank" class="text-xs bg-orange-500/10 text-orange-400 px-2.5 py-1 rounded hover:bg-orange-500/20 transition-colors border border-orange-500/20 font-bold" title="Paraşüt'te Görüntüle">P</a>`;
            } else {
                linksHtml += '<span class="text-xs text-slate-600 px-2.5 py-1">-</span>';
            }
            linksHtml += '</div>';
            linksCell.innerHTML = linksHtml;
            row.appendChild(linksCell);

            // Zoho Total
            const zohoTotalCell = document.createElement('td');
            zohoTotalCell.className = 'px-4 py-3 text-sm text-right';
            if (inv.zoho_total !== null) {
                const zohoCurrency = (inv.zoho_currency === 'TRL') ? 'TRY' : (inv.zoho_currency || 'TRY');
                zohoTotalCell.innerHTML = `<span class="text-emerald-400 font-mono">${parseFloat(inv.zoho_total).toLocaleString('tr-TR', { minimumFractionDigits: 2 })}</span> <span class="text-xs text-slate-500">${zohoCurrency}</span>`;
            } else {
                zohoTotalCell.innerHTML = '<span class="text-slate-600">-</span>';
            }
            row.appendChild(zohoTotalCell);

            // Zoho Status
            const zohoStatusCell = document.createElement('td');
            zohoStatusCell.className = 'px-4 py-3 text-center text-sm';
            if (inv.zoho_id) {
                zohoStatusCell.innerHTML = formatStatus(inv.zoho_status);
            } else {
                zohoStatusCell.innerHTML = '<span class="text-slate-600">-</span>';
            }
            row.appendChild(zohoStatusCell);

            // Parasut Total
            const parasutTotalCell = document.createElement('td');
            parasutTotalCell.className = 'px-4 py-3 text-sm text-right';
            if (inv.parasut_total !== null) {
                const parasutCurrency = (inv.parasut_currency === 'TRL') ? 'TRY' : (inv.parasut_currency || 'TRY');
                parasutTotalCell.innerHTML = `<span class="text-emerald-400 font-mono">${parseFloat(inv.parasut_total).toLocaleString('tr-TR', { minimumFractionDigits: 2 })}</span> <span class="text-xs text-slate-500">${parasutCurrency}</span>`;
            } else {
                parasutTotalCell.innerHTML = '<span class="text-slate-600">-</span>';
            }
            row.appendChild(parasutTotalCell);

            // Parasut Status
            const parasutStatusCell = document.createElement('td');
            parasutStatusCell.className = 'px-4 py-3 text-center text-sm';
            if (inv.parasut_id) {
                parasutStatusCell.innerHTML = formatStatus(inv.parasut_status);
            } else {
                parasutStatusCell.innerHTML = '<span class="text-slate-600">-</span>';
            }
            row.appendChild(parasutStatusCell);

            // Match Status
            const statusCell = document.createElement('td');
            statusCell.className = 'px-4 py-3 text-sm';
            statusCell.innerHTML = getStatusBadge(inv.match_status);
            row.appendChild(statusCell);

            tbody.appendChild(row);
        });
    }

    function formatStatus(status) {
        if (!status) return '<span class="text-slate-500">-</span>';

        const s = status.toLowerCase();
        if (s === 'paid' || s === 'odendi' || s === 'ödendi') {
            return '<span class="px-2 py-1 rounded text-[10px] bg-emerald-500/20 text-emerald-400 border border-emerald-500/20">Ödendi</span>';
        } else if (s === 'unpaid' || s === 'odenmedi' || s === 'ödenmedi') {
            return '<span class="px-2 py-1 rounded text-[10px] bg-red-500/20 text-red-400 border border-red-500/20">Ödenmedi</span>';
        } else if (s === 'partially_paid' || s === 'kismen_odendi') {
            return '<span class="px-2 py-1 rounded text-[10px] bg-amber-500/20 text-amber-400 border border-amber-500/20">Kısmen Ödendi</span>';
        } else if (s === 'overdue' || s === 'gecikmis') {
            return '<span class="px-2 py-1 rounded text-[10px] bg-red-800/20 text-red-500 border border-red-800/20 font-bold">Gecikmiş</span>';
        } else if (s === 'draft' || s === 'taslak') {
            return '<span class="px-2 py-1 rounded text-[10px] bg-slate-500/20 text-slate-400 border border-slate-500/20">Taslak</span>';
        } else if (s === 'void' || s === 'iptal') {
            return '<span class="px-2 py-1 rounded text-[10px] bg-gray-500/20 text-gray-500 border border-gray-500/20">İptal</span>';
        }

        return `<span class="px-2 py-1 rounded text-[10px] bg-slate-700 text-slate-300 border border-slate-600">${escapeHtml(status)}</span>`;
    }

    function getStatusBadge(status) {
        switch (status) {
            case 'matched':
                return '<span class="px-2 py-1 rounded text-[10px] bg-emerald-500/20 text-emerald-400 border border-emerald-500/20">Eşleşti</span>';
            case 'price_diff':
                return `<div class="flex items-center gap-2">
                    <span class="px-2 py-1 rounded text-[10px] bg-amber-500/20 text-amber-400 border border-amber-500/20">Farklılık Var</span>
                </div>`;
            case 'only_zoho':
                return `<div class="flex items-center gap-2">
                    <span class="px-2 py-1 rounded text-[10px] bg-blue-500/20 text-blue-400 border border-blue-500/20">Sadece Zoho</span>
                </div>`;
            case 'only_parasut':
                return `<div class="flex items-center gap-2">
                    <span class="px-2 py-1 rounded text-[10px] bg-orange-500/20 text-orange-400 border border-orange-500/20">Sadece Paraşüt</span>
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
        loadInvoices();
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