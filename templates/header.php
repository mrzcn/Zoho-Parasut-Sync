<?php
require_once __DIR__ . '/../bootstrap.php';

checkAuthentication();
?>
<!DOCTYPE html>
<html lang="tr">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="<?= generateCsrfToken() ?>">
    <title><?= isset($pageTitle) ? htmlspecialchars($pageTitle) . ' - Nolto.sync' : 'Nolto.sync' ?></title>
    <link rel="icon" type="image/svg+xml" href="assets/favicon.svg">
    <link rel="icon" type="image/png" href="assets/favicon.png" sizes="32x32">
    <script src="https://cdn.tailwindcss.com/3.4.17?v=<?= APP_VERSION ?>"></script>
    <script src="https://cdn.jsdelivr.net/npm/axios@1.7.9/dist/axios.min.js"></script>
    <script>
        // Global axios defaults for CSRF protection
        axios.defaults.headers.common['X-CSRF-TOKEN'] = document.querySelector('meta[name="csrf-token"]').getAttribute('content');

        // Global HTML escaping utility for XSS protection
        function escapeHtml(text) {
            if (text === null || text === undefined) return '';
            const map = {
                '&': '&amp;',
                '<': '&lt;',
                '>': '&gt;',
                '"': '&quot;',
                "'": '&#039;'
            };
            return String(text).replace(/[&<>"']/g, function (m) { return map[m]; });
        }
    </script>
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.7/dist/chart.umd.min.js"></script>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11.14.5/dist/sweetalert2.all.min.js"></script>
    <script src="assets/js/common.js?v=<?= APP_VERSION ?>"></script>
    <script>
        tailwind.config = {
            theme: {
                extend: {
                    fontFamily: {
                        sans: ['Inter', 'sans-serif'],
                    },
                }
            }
        }
    </script>
    <script>
        // Global Search Logic
        let searchDebounceTimer;

        document.addEventListener('DOMContentLoaded', () => {
            const searchInput = document.getElementById('globalSearchInput');
            const resultsContainer = document.getElementById('globalSearchResults');

            if (!searchInput) return;

            // Close results when clicking outside
            document.addEventListener('click', (e) => {
                if (!searchInput.contains(e.target) && !resultsContainer.contains(e.target)) {
                    resultsContainer.classList.add('hidden');
                }
            });

            // Focus handler to show results if query exists
            searchInput.addEventListener('focus', () => {
                if (searchInput.value.length >= 3 && resultsContainer.innerHTML.trim() !== '') {
                    resultsContainer.classList.remove('hidden');
                }
            });

            searchInput.addEventListener('input', (e) => {
                const query = e.target.value.trim();

                clearTimeout(searchDebounceTimer);

                if (query.length < 3) {
                    resultsContainer.classList.add('hidden');
                    return;
                }

                searchDebounceTimer = setTimeout(() => {
                    performGlobalSearch(query);
                }, 400); // 400ms debounce
            });

            function performGlobalSearch(query) {
                // Show loading state?
                resultsContainer.innerHTML = '<div class="p-4 text-center text-slate-400">Aranıyor...</div>';
                resultsContainer.classList.remove('hidden');

                const formData = new FormData();
                formData.append('action', 'global_search');
                formData.append('query', query);
                // CSRF Token - already in axios defaults but needed manually here if not using axios for this specific call?
                // Using axios is better since it's already loaded

                axios.post('api_handler.php', formData)
                    .then(response => {
                        if (response.data.success) {
                            renderSearchResults(response.data.data);
                        } else {
                            resultsContainer.innerHTML = `<div class="p-4 text-center text-red-400">${escapeHtml(response.data.message || 'Hata oluştu')}</div>`;
                        }
                    })
                    .catch(error => {
                        console.error('Search error:', error);
                        resultsContainer.innerHTML = '<div class="p-4 text-center text-red-500">Bir hata oluştu.</div>';
                    });
            }

            function renderSearchResults(data) {
                let html = '';
                let hasResults = false;

                // 1. Parasut Invoices
                if (data.parasut_invoices && data.parasut_invoices.length > 0) {
                    hasResults = true;
                    html += '<div class="p-2 bg-slate-800/50 text-xs font-bold text-slate-400 uppercase tracking-wider sticky top-0 backdrop-blur-sm">Paraşüt Faturalar</div>';
                    data.parasut_invoices.forEach(item => {
                        html += `
                            <a href="invoices_comparison.php?search=${encodeURIComponent(item.invoice_number)}" class="block p-3 hover:bg-slate-800 border-b border-slate-700/50 transition-colors">
                                <div class="flex justify-between items-start">
                                    <div>
                                        <div class="font-medium text-white">${escapeHtml(item.invoice_number)}</div>
                                        <div class="text-xs text-slate-400 mt-1 line-clamp-1">${escapeHtml(item.description || 'Açıklama yok')}</div>
                                    </div>
                                    <div class="text-right">
                                        <div class="text-sm font-semibold text-emerald-400">${parseFloat(item.net_total).toLocaleString('tr-TR', { minimumFractionDigits: 2 })} ${item.currency}</div>
                                        <div class="text-[10px] text-slate-500">${item.issue_date}</div>
                                    </div>
                                </div>
                            </a>
                        `;
                    });
                }

                // 2. Parasut Items
                if (data.parasut_items && data.parasut_items.length > 0) {
                    hasResults = true;
                    html += '<div class="p-2 bg-slate-800/50 text-xs font-bold text-slate-400 uppercase tracking-wider sticky top-0 backdrop-blur-sm">Paraşüt Kalemler</div>';
                    data.parasut_items.forEach(item => {
                        html += `
                            <a href="invoices_comparison.php?search=${encodeURIComponent(item.invoice_number)}" class="block p-3 hover:bg-slate-800 border-b border-slate-700/50 transition-colors">
                                <div class="flex justify-between items-start">
                                    <div>
                                        <div class="font-medium text-white">${escapeHtml(item.product_name)}</div>
                                        <div class="text-xs text-slate-400 mt-1">Fatura: <span class="text-indigo-400">${escapeHtml(item.invoice_number)}</span></div>
                                    </div>
                                    <div class="text-right">
                                        <div class="text-xs font-semibold text-slate-300">${parseFloat(item.quantity)} Adet</div>
                                    </div>
                                </div>
                            </a>
                        `;
                    });
                }

                // 3. Parasut Products
                if (data.parasut_products && data.parasut_products.length > 0) {
                    hasResults = true;
                    html += '<div class="p-2 bg-slate-800/50 text-xs font-bold text-slate-400 uppercase tracking-wider sticky top-0 backdrop-blur-sm">Paraşüt Ürünler</div>';
                    data.parasut_products.forEach(item => {
                        html += `
                            <a href="products_comparison.php?search=${encodeURIComponent(item.product_code)}" class="block p-3 hover:bg-slate-800 border-b border-slate-700/50 transition-colors">
                                <div class="flex justify-between items-center">
                                    <div>
                                        <div class="font-medium text-white">${escapeHtml(item.product_name)}</div>
                                        <div class="text-xs text-slate-400 font-mono">${escapeHtml(item.product_code)}</div>
                                    </div>
                                    <div class="text-sm font-semibold text-emerald-400">${parseFloat(item.list_price).toLocaleString('tr-TR', { minimumFractionDigits: 2 })} ${item.currency}</div>
                                </div>
                            </a>
                        `;
                    });
                }

                // 4. Zoho Invoices
                if (data.zoho_invoices && data.zoho_invoices.length > 0) {
                    hasResults = true;
                    html += '<div class="p-2 bg-slate-800/50 text-xs font-bold text-slate-400 uppercase tracking-wider sticky top-0 backdrop-blur-sm">Zoho Faturalar</div>';
                    data.zoho_invoices.forEach(item => {
                        html += `
                            <a href="invoices_comparison.php?search=${encodeURIComponent(item.invoice_number)}" class="block p-3 hover:bg-slate-800 border-b border-slate-700/50 transition-colors">
                                <div class="flex justify-between items-start">
                                    <div>
                                        <div class="font-medium text-white">${escapeHtml(item.customer_name)}</div>
                                        <div class="text-xs text-indigo-400">${escapeHtml(item.invoice_number)}</div>
                                    </div>
                                    <div class="text-right">
                                        <div class="text-sm font-semibold text-emerald-400">${parseFloat(item.total).toLocaleString('tr-TR', { minimumFractionDigits: 2 })} ${item.currency}</div>
                                        <div class="text-[10px] text-slate-500">${item.invoice_date}</div>
                                    </div>
                                </div>
                            </a>
                        `;
                    });
                }

                // 5. Zoho Products
                if (data.zoho_products && data.zoho_products.length > 0) {
                    hasResults = true;
                    html += '<div class="p-2 bg-slate-800/50 text-xs font-bold text-slate-400 uppercase tracking-wider sticky top-0 backdrop-blur-sm">Zoho Ürünler</div>';
                    data.zoho_products.forEach(item => {
                        html += `
                            <a href="products_comparison.php?search=${encodeURIComponent(item.product_code)}" class="block p-3 hover:bg-slate-800 border-b border-slate-700/50 transition-colors">
                                <div class="flex justify-between items-center">
                                    <div>
                                        <div class="font-medium text-white">${escapeHtml(item.product_name)}</div>
                                        <div class="text-xs text-slate-400 font-mono">${escapeHtml(item.product_code)}</div>
                                    </div>
                                    <div class="text-sm font-semibold text-emerald-400">${parseFloat(item.unit_price).toLocaleString('tr-TR', { minimumFractionDigits: 2 })} ${item.currency}</div>
                                </div>
                            </a>
                        `;
                    });
                }

                if (!hasResults) {
                    html = '<div class="p-8 text-center text-slate-500">Sonuç bulunamadı</div>';
                }

                resultsContainer.innerHTML = html;
            }
        });
    </script>
    <style>
        /* Apple Liquid Glass Dark Theme - Softer */
        body {
            font-family: 'Inter', sans-serif;
            background: #1a1a1a;
            min-height: 100vh;
        }

        /* Frosted Glass effect - Lighter */
        .glass {
            background: rgba(40, 40, 40, 0.95);
            backdrop-filter: blur(40px) saturate(180%);
            -webkit-backdrop-filter: blur(40px) saturate(180%);
            border: 1px solid rgba(255, 255, 255, 0.1);
        }

        .glass-card {
            background: rgba(45, 45, 45, 0.95);
            backdrop-filter: blur(40px) saturate(180%);
            -webkit-backdrop-filter: blur(40px) saturate(180%);
            border: 1px solid rgba(255, 255, 255, 0.12);
            box-shadow: 0 8px 32px rgba(0, 0, 0, 0.3);
        }

        /* Clean text */
        .gradient-text {
            color: #f5f5f7;
        }

        /* Solid buttons - Better contrast */
        .btn-primary {
            background: #ffffff;
            color: #000000;
            font-weight: 600;
            transition: all 0.2s ease;
            border: none;
        }

        .btn-primary:hover {
            background: #f0f0f0;
        }

        .btn-secondary {
            background: #14b8a6;
            color: #ffffff;
            font-weight: 600;
            transition: all 0.2s ease;
            border: none;
        }

        .btn-secondary:hover {
            background: #0d9488;
        }

        .btn-success {
            background: #10b981;
            color: #ffffff;
            font-weight: 600;
            transition: all 0.2s ease;
            border: none;
        }

        .btn-success:hover {
            background: #059669;
        }

        .btn-outline {
            background: rgba(255, 255, 255, 0.1);
            border: 1px solid rgba(255, 255, 255, 0.3);
            color: #ffffff;
            font-weight: 500;
            transition: all 0.2s ease;
        }

        .btn-outline:hover {
            background: rgba(255, 255, 255, 0.15);
            border-color: rgba(255, 255, 255, 0.5);
        }

        /* Premium tables - Lighter */
        .table-premium thead {
            background: rgba(35, 35, 35, 0.95);
        }

        .table-premium tbody tr {
            border-bottom: 1px solid rgba(255, 255, 255, 0.08);
            transition: background 0.2s ease;
        }

        .table-premium tbody tr:hover {
            background: rgba(255, 255, 255, 0.05);
        }

        /* Premium inputs - Better contrast */
        .input-premium {
            background: rgba(50, 50, 50, 0.9);
            border: 1px solid rgba(255, 255, 255, 0.15);
            color: #ffffff;
            transition: all 0.2s ease;
        }

        .input-premium:focus {
            border-color: rgba(255, 255, 255, 0.4);
            box-shadow: 0 0 0 4px rgba(255, 255, 255, 0.08);
            outline: none;
        }

        .input-premium::placeholder {
            color: rgba(255, 255, 255, 0.4);
        }

        /* Pill tabs - Better contrast */
        .tab-pill {
            padding: 0.5rem 1.25rem;
            border-radius: 9999px;
            font-weight: 500;
            font-size: 0.875rem;
            transition: all 0.2s ease;
            color: rgba(255, 255, 255, 0.6);
            background: transparent;
        }

        .tab-pill:hover {
            color: rgba(255, 255, 255, 0.9);
            background: rgba(255, 255, 255, 0.08);
        }

        .tab-pill.active {
            background: rgba(255, 255, 255, 0.95);
            color: #000;
        }

        /* Loader */
        .loader {
            border: 2px solid rgba(255, 255, 255, 0.1);
            border-top: 2px solid rgba(255, 255, 255, 0.8);
            border-radius: 50%;
            width: 20px;
            height: 20px;
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

        /* Glow effects */
        .glow-purple {
            box-shadow: 0 0 30px rgba(255, 255, 255, 0.1);
        }

        .glow-cyan {
            box-shadow: 0 0 30px rgba(255, 255, 255, 0.1);
        }

        /* Status badges */
        .badge {
            padding: 0.25rem 0.75rem;
            border-radius: 9999px;
            font-size: 0.75rem;
            font-weight: 600;
        }

        .badge-success {
            background: rgba(52, 211, 153, 0.15);
            color: #34d399;
        }

        .badge-warning {
            background: rgba(251, 191, 36, 0.15);
            color: #fbbf24;
        }

        .badge-error {
            background: rgba(248, 113, 113, 0.15);
            color: #f87171;
        }

        .badge-info {
            background: rgba(96, 165, 250, 0.15);
            color: #60a5fa;
        }

        /* ===== Global Scrollbar Theming ===== */
        /* Apply dark scrollbar everywhere — prevents white flash */
        * {
            scrollbar-width: thin;
            scrollbar-color: rgba(255, 255, 255, 0.1) transparent;
        }

        *::-webkit-scrollbar {
            width: 6px;
            height: 6px;
        }

        *::-webkit-scrollbar-track {
            background: transparent;
        }

        *::-webkit-scrollbar-thumb {
            background: rgba(255, 255, 255, 0.1);
            border-radius: 10px;
        }

        *::-webkit-scrollbar-thumb:hover {
            background: rgba(255, 255, 255, 0.25);
        }

        /* Main content area scrollbar — slightly wider for usability */
        .code-scrollbar::-webkit-scrollbar {
            width: 8px;
        }

        .code-scrollbar::-webkit-scrollbar-thumb {
            background: rgba(255, 255, 255, 0.12);
            border-radius: 4px;
        }

        /* ===== Table overflow fix ===== */
        /* Horizontal scrollbar only appears when needed, styled to match theme */
        .overflow-x-auto {
            scrollbar-width: thin;
            scrollbar-color: rgba(255, 255, 255, 0.08) transparent;
        }

        .overflow-x-auto::-webkit-scrollbar {
            height: 4px;
        }

        .overflow-x-auto::-webkit-scrollbar-thumb {
            background: rgba(255, 255, 255, 0.08);
            border-radius: 2px;
        }

        .overflow-x-auto:hover::-webkit-scrollbar-thumb {
            background: rgba(255, 255, 255, 0.15);
        }

        /* ===== Table cell truncation ===== */
        .table-premium td {
            max-width: 280px;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }

        /* ===== Hover scale containment ===== */
        /* Prevent transform:scale from causing parent overflow */
        .glass-card,
        .glass {
            overflow: hidden;
        }
    </style>
</head>

<body class="text-slate-200 font-sans antialiased bg-[#1a1a1a]">

    <div class="flex h-screen overflow-hidden">
        <!-- Mobile Overlay -->
        <div id="sidebar-overlay" class="fixed inset-0 bg-black/60 z-40 hidden lg:hidden"
            onclick="closeMobileSidebar()"></div>

        <!-- Sidebar -->
        <aside id="sidebar" class="w-64 flex-shrink-0 glass border-r border-white/5 flex flex-col transition-all duration-300 z-50
                 fixed lg:relative inset-y-0 left-0 -translate-x-full lg:translate-x-0">
            <!-- Logo -->
            <div class="h-20 flex items-center justify-center border-b border-white/5">
                <div class="flex items-center gap-3">
                    <img src="assets/nolto_logo.png" alt="Nolto SYNC" class="h-10 w-auto object-contain"
                        style="filter: grayscale(100%) brightness(1.5);">
                    <span class="font-bold text-xl tracking-wide text-white">Nolto.sync</span>
                </div>
            </div>

            <!-- Nav Links -->
            <nav class="flex-1 overflow-y-auto py-6 px-4 space-y-2 custom-scrollbar">
                <?php
                $currentPage = basename($_SERVER['PHP_SELF']);
                $navItems = [
                    ['url' => 'index.php', 'icon' => '📊', 'label' => 'Dashboard'],
                    ['url' => 'products_comparison.php', 'icon' => '📦', 'label' => 'Ürünler'],
                    ['url' => 'invoices_comparison.php', 'icon' => '📄', 'label' => 'Faturalar'],
                    ['url' => 'purchase_orders.php', 'icon' => '📋', 'label' => 'Gider Faturaları'],

                    ['type' => 'separator', 'label' => 'SENKRONİZASYON'],

                    ['url' => 'sync_invoices.php', 'icon' => '⚡', 'label' => 'Sync Gelir Faturaları', 'special' => 'violet'],
                    ['url' => 'sync_purchase_orders.php', 'icon' => '🔄', 'label' => 'Sync Gider Faturaları', 'special' => 'amber'],

                    ['type' => 'separator', 'label' => 'SİSTEM'],

                    ['url' => 'logs.php', 'icon' => '📜', 'label' => 'Loglar'],
                    ['url' => 'settings.php', 'icon' => '⚙️', 'label' => 'Ayarlar'],

                    ['type' => 'separator', 'label' => 'ARAÇLAR'],

                    ['url' => 'duplicates.php', 'icon' => '🔀', 'label' => 'Ürün Birleştirme'],
                ];

                foreach ($navItems as $item) {
                    if (isset($item['type']) && $item['type'] === 'separator') {
                        echo "<div class=\"px-4 mt-6 mb-2\">
                                <span class=\"text-[10px] uppercase tracking-wider text-slate-500 font-bold\">{$item['label']}</span>
                              </div>";
                        continue;
                    }

                    $active = $currentPage == $item['url'];
                    $baseClass = "flex items-center gap-3 px-4 py-3 rounded-xl text-sm font-medium transition-all duration-200 group";

                    if (isset($item['special'])) {
                        if ($item['special'] == 'violet') {
                            $activeClass = $active ? 'bg-violet-600/20 text-violet-300 border border-violet-500/30' : 'text-violet-400 hover:bg-violet-600/10 border border-violet-500/10 hover:border-violet-500/30';
                        } elseif ($item['special'] == 'amber') {
                            $activeClass = $active ? 'bg-amber-600/20 text-amber-300 border border-amber-500/30' : 'text-amber-400 hover:bg-amber-600/10 border border-amber-500/10 hover:border-amber-500/30';
                        } else {
                            $activeClass = $active ? 'bg-emerald-600/20 text-emerald-300 border border-emerald-500/30' : 'text-emerald-400 hover:bg-emerald-600/10 border border-transparent hover:border-emerald-500/20';
                        }
                    } else {
                        $activeClass = $active ? 'bg-white/10 text-white shadow-lg' : 'text-slate-400 hover:text-white hover:bg-white/5';
                    }

                    echo "<a href=\"{$item['url']}\" class=\"{$baseClass} {$activeClass}\">
                            <span class=\"text-lg group-hover:scale-110 transition-transform duration-200\">{$item['icon']}</span>
                            <span>{$item['label']}</span>
                          </a>";
                }
                ?>
            </nav>

            <div class="p-4 border-t border-white/5">
                <div class="flex items-center gap-3 px-4 py-3 rounded-xl bg-white/5">
                    <div
                        class="w-8 h-8 rounded-full bg-gradient-to-tr from-indigo-500 to-purple-500 flex items-center justify-center font-bold text-xs text-white">
                        NU</div>
                    <div class="flex-1 min-w-0">
                        <div class="text-sm font-medium text-white truncate">Nolto Admin</div>
                        <div class="text-xs text-slate-500">v<?= APP_VERSION ?></div>
                    </div>
                </div>
            </div>
        </aside>

        <!-- Main Wrapper -->
        <div class="relative flex flex-col flex-1 overflow-hidden bg-[#1a1a1a]">
            <!-- Top Header -->
            <header class="h-20 glass border-b border-white/5 flex items-center justify-between px-8 z-30 sticky top-0">
                <!-- Mobile Hamburger -->
                <button id="hamburger-btn" class="lg:hidden p-2 rounded-lg hover:bg-white/10 transition-colors mr-3"
                    onclick="openMobileSidebar()">
                    <svg class="w-6 h-6 text-slate-300" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                            d="M4 6h16M4 12h16M4 18h16" />
                    </svg>
                </button>
                <!-- Search -->
                <div class="w-96 relative group">
                    <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
                        <span class="text-slate-400">🔍</span>
                    </div>
                    <input type="text" id="globalSearchInput"
                        class="block w-full pl-10 pr-3 py-2.5 border border-slate-700/50 rounded-xl leading-5 bg-black/20 text-slate-300 placeholder-slate-500 focus:outline-none focus:bg-black/40 focus:ring-1 focus:ring-indigo-500/50 focus:border-indigo-500/50 sm:text-sm transition-all duration-200"
                        placeholder="Faturalarda veya ürünlerde ara..." autocomplete="off">
                    <!-- Search Results Dropdown -->
                    <div id="globalSearchResults"
                        class="hidden absolute left-0 right-0 mt-2 bg-[#1e1e1e] border border-slate-700 rounded-xl shadow-2xl z-50 max-h-[80vh] overflow-y-auto custom-scrollbar">
                    </div>
                </div>

                <!-- Actions -->
                <div class="flex items-center gap-3">
                    <!-- Context-specific buttons added by individual pages via JS -->
                </div>
            </header>

            <!-- Main Content -->
            <main class="flex-1 overflow-y-auto overflow-x-hidden p-8 scroll-smooth code-scrollbar relative">
                <!-- Toast Container -->
                <div id="toast-container" class="fixed top-24 right-8 z-50 flex flex-col gap-3"></div>