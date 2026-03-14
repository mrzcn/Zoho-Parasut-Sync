<?php
$pageTitle = 'Dashboard';
include 'templates/header.php';
?>
<!-- Dashboard Header -->
<div class="mb-8 flex justify-between items-end">
    <div>
        <h1 class="text-3xl font-bold gradient-text mb-2">Genel Bakış</h1>
        <p class="text-slate-400">Görsel analitikler, senkronizasyon durumu ve istatistikler.</p>
    </div>
    <div class="text-right">
        <div class="text-xs text-slate-500 mb-1">Son güncelleme</div>
        <div class="text-sm font-medium text-slate-300" id="last-update-time">--:--</div>
        <div class="text-xs text-slate-600 mt-1">
            <span class="inline-block w-2 h-2 bg-emerald-500 rounded-full animate-pulse mr-1"></span>
            Yenileme: <span id="countdown-timer">60</span>s
        </div>
    </div>
</div>
<!-- Stats Grid -->
<div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6 mb-10">
    <!-- Success Rate Gauge -->
    <div
        class="glass-card rounded-2xl p-6 relative overflow-hidden group hover:scale-[1.02] transition-transform duration-300">
        <div
            class="absolute -right-4 -top-4 w-24 h-24 bg-emerald-500/10 rounded-full blur-2xl group-hover:bg-emerald-500/20 transition-all">
        </div>
        <div class="relative z-10">
            <div class="text-slate-400 text-sm font-medium mb-1">Başarı Oranı</div>
            <div class="flex items-baseline gap-2">
                <div class="text-3xl font-bold text-white" id="stat-success-rate">--%</div>
                <div class="text-xs text-emerald-400">Senkronize</div>
            </div>
            <div class="mt-4 w-full bg-slate-700/50 rounded-full h-1.5">
                <div class="bg-emerald-500 h-1.5 rounded-full transition-all duration-500" style="width: 0%"
                    id="stat-success-bar"></div>
            </div>
        </div>
    </div>
    <!-- Total Invoices -->
    <div
        class="glass-card rounded-2xl p-6 relative overflow-hidden group hover:scale-[1.02] transition-transform duration-300">
        <div
            class="absolute -right-4 -top-4 w-24 h-24 bg-violet-500/10 rounded-full blur-2xl group-hover:bg-violet-500/20 transition-all">
        </div>
        <div class="relative z-10">
            <div class="text-slate-400 text-sm font-medium mb-1">Toplam Faturalar</div>
            <div class="text-3xl font-bold text-white" id="stat-total-invoices">--</div>
            <div class="mt-2 text-xs text-slate-500">
                <span>✅ Senkronize: <span id="stat-synced-short" class="text-emerald-400">--</span></span>
            </div>
        </div>
    </div>
    <!-- Pending Invoices -->
    <div
        class="glass-card rounded-2xl p-6 relative overflow-hidden group hover:scale-[1.02] transition-transform duration-300">
        <div
            class="absolute -right-4 -top-4 w-24 h-24 bg-orange-500/10 rounded-full blur-2xl group-hover:bg-orange-500/20 transition-all">
        </div>
        <div class="relative z-10">
            <div class="text-slate-400 text-sm font-medium mb-1">Bekleyen Faturalar</div>
            <div class="text-3xl font-bold text-white" id="stat-unsynced-count">--</div>
            <div class="mt-2">
                <a href="invoices_comparison.php"
                    class="text-xs text-orange-400 hover:text-orange-300 transition-colors flex items-center gap-1">
                    İncele ve Aktar →
                </a>
            </div>
        </div>
    </div>
    <!-- Errors -->
    <div
        class="glass-card rounded-2xl p-6 relative overflow-hidden group hover:scale-[1.02] transition-transform duration-300">
        <div
            class="absolute -right-4 -top-4 w-24 h-24 bg-red-500/10 rounded-full blur-2xl group-hover:bg-red-500/20 transition-all">
        </div>
        <div class="relative z-10">
            <div class="text-slate-400 text-sm font-medium mb-1">Hatalı Faturalar</div>
            <div class="text-3xl font-bold text-white" id="stat-errors-count">--</div>
            <div class="mt-2">
                <a href="invoices_comparison.php?filter=error"
                    class="text-xs text-red-400 hover:text-red-300 transition-colors flex items-center gap-1">
                    Hataları Görüntüle →
                </a>
            </div>
        </div>
    </div>
</div>
<!-- Product Stats Grid -->
<h3 class="text-lg font-semibold text-white mb-4 mt-6">📦 Ürün İstatistikleri</h3>
<div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6 mb-10">
    <!-- Parasut Products -->
    <div
        class="glass-card rounded-2xl p-6 relative overflow-hidden group hover:scale-[1.02] transition-transform duration-300">
        <div
            class="absolute -right-4 -top-4 w-24 h-24 bg-blue-500/10 rounded-full blur-2xl group-hover:bg-blue-500/20 transition-all">
        </div>
        <div class="relative z-10">
            <div class="text-slate-400 text-sm font-medium mb-1">Paraşüt Ürünleri</div>
            <div class="text-3xl font-bold text-white" id="stat-parasut-total">--</div>
            <div class="mt-2 text-xs text-slate-500">
                Toplam yerel ürün
            </div>
        </div>
    </div>
    <!-- Zoho Products -->
    <div
        class="glass-card rounded-2xl p-6 relative overflow-hidden group hover:scale-[1.02] transition-transform duration-300">
        <div
            class="absolute -right-4 -top-4 w-24 h-24 bg-purple-500/10 rounded-full blur-2xl group-hover:bg-purple-500/20 transition-all">
        </div>
        <div class="relative z-10">
            <div class="text-slate-400 text-sm font-medium mb-1">Zoho Ürünleri</div>
            <div class="text-3xl font-bold text-white" id="stat-zoho-total">--</div>
            <div class="mt-2 text-xs text-slate-500">
                Toplam CRM ürünü
            </div>
        </div>
    </div>
    <!-- Missing in Zoho -->
    <div
        class="glass-card rounded-2xl p-6 relative overflow-hidden group hover:scale-[1.02] transition-transform duration-300">
        <div
            class="absolute -right-4 -top-4 w-24 h-24 bg-orange-500/10 rounded-full blur-2xl group-hover:bg-orange-500/20 transition-all">
        </div>
        <div class="relative z-10">
            <div class="text-slate-400 text-sm font-medium mb-1">Zoho'da Eksik</div>
            <div class="text-3xl font-bold text-white" id="stat-missing-zoho">--</div>
            <div class="mt-2 text-xs">
                <a href="products_comparison.php"
                    class="text-orange-400 hover:text-orange-300 transition-colors flex items-center gap-1">
                    Eşleştir ve Aktar →
                </a>
            </div>
        </div>
    </div>
    <!-- Missing in Parasut -->
    <div
        class="glass-card rounded-2xl p-6 relative overflow-hidden group hover:scale-[1.02] transition-transform duration-300">
        <div
            class="absolute -right-4 -top-4 w-24 h-24 bg-red-500/10 rounded-full blur-2xl group-hover:bg-red-500/20 transition-all">
        </div>
        <div class="relative z-10">
            <div class="text-slate-400 text-sm font-medium mb-1">Paraşüt'te Eksik</div>
            <div class="text-3xl font-bold text-white" id="stat-missing-parasut">--</div>
            <div class="mt-2 text-xs text-slate-500">
                Zoho'da var, burada yok
            </div>
        </div>
    </div>
</div>

<!-- Purchase Orders Stats Grid -->
<h3 class="text-lg font-semibold text-white mb-4 mt-6">📦 Gider Faturaları İstatistikleri</h3>
<div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6 mb-10">
    <!-- Total Purchase Orders -->
    <div
        class="glass-card rounded-2xl p-6 relative overflow-hidden group hover:scale-[1.02] transition-transform duration-300">
        <div
            class="absolute -right-4 -top-4 w-24 h-24 bg-amber-500/10 rounded-full blur-2xl group-hover:bg-amber-500/20 transition-all">
        </div>
        <div class="relative z-10">
            <div class="text-slate-400 text-sm font-medium mb-1">Toplam GF</div>
            <div class="text-3xl font-bold text-white" id="stat-po-total">--</div>
            <div class="mt-2 text-xs text-slate-500">
                <span>✅ Senkronize: <span id="stat-po-synced" class="text-emerald-400">--</span></span>
            </div>
        </div>
    </div>
    <!-- Pending POs -->
    <div
        class="glass-card rounded-2xl p-6 relative overflow-hidden group hover:scale-[1.02] transition-transform duration-300">
        <div
            class="absolute -right-4 -top-4 w-24 h-24 bg-orange-500/10 rounded-full blur-2xl group-hover:bg-orange-500/20 transition-all">
        </div>
        <div class="relative z-10">
            <div class="text-slate-400 text-sm font-medium mb-1">Bekleyen GF</div>
            <div class="text-3xl font-bold text-white" id="stat-po-unsynced">--</div>
            <div class="mt-2">
                <a href="purchase_orders.php"
                    class="text-xs text-orange-400 hover:text-orange-300 transition-colors flex items-center gap-1">
                    İncele ve Aktar →
                </a>
            </div>
        </div>
    </div>
    <!-- PO Success Rate -->
    <div
        class="glass-card rounded-2xl p-6 relative overflow-hidden group hover:scale-[1.02] transition-transform duration-300">
        <div
            class="absolute -right-4 -top-4 w-24 h-24 bg-emerald-500/10 rounded-full blur-2xl group-hover:bg-emerald-500/20 transition-all">
        </div>
        <div class="relative z-10">
            <div class="text-slate-400 text-sm font-medium mb-1">GF Başarı Oranı</div>
            <div class="flex items-baseline gap-2">
                <div class="text-3xl font-bold text-white" id="stat-po-success-rate">--%</div>
                <div class="text-xs text-emerald-400">Sync</div>
            </div>
            <div class="mt-4 w-full bg-slate-700/50 rounded-full h-1.5">
                <div class="bg-emerald-500 h-1.5 rounded-full transition-all duration-500" style="width: 0%"
                    id="stat-po-success-bar"></div>
            </div>
        </div>
    </div>
    <!-- PO Errors -->
    <div
        class="glass-card rounded-2xl p-6 relative overflow-hidden group hover:scale-[1.02] transition-transform duration-300">
        <div
            class="absolute -right-4 -top-4 w-24 h-24 bg-red-500/10 rounded-full blur-2xl group-hover:bg-red-500/20 transition-all">
        </div>
        <div class="relative z-10">
            <div class="text-slate-400 text-sm font-medium mb-1">Hatalı GF</div>
            <div class="text-3xl font-bold text-white" id="stat-po-errors">--</div>
            <div class="mt-2">
                <a href="purchase_orders.php?filter=error"
                    class="text-xs text-red-400 hover:text-red-300 transition-colors flex items-center gap-1">
                    Hataları Görüntüle →
                </a>
            </div>
        </div>
    </div>
</div>

<!-- Charts Grid -->
<div class="grid grid-cols-1 lg:grid-cols-2 gap-6 mb-10">
    <!-- Year over Year Chart -->
    <div class="glass-card rounded-2xl p-6">
        <h3 class="text-lg font-semibold text-white mb-4">📊 Yıllara Göre Faturalar</h3>
        <div style="position: relative; height: 250px;">
            <canvas id="yearChart"></canvas>
        </div>
    </div>
    <!-- Sync Status Pie Chart -->
    <div class="glass-card rounded-2xl p-6">
        <h3 class="text-lg font-semibold text-white mb-4">🥧 Senkronizasyon Durumu</h3>
        <div style="position: relative; height: 250px;">
            <canvas id="statusChart"></canvas>
        </div>
    </div>
</div>
<!-- Monthly Trend & Recent Activities -->
<div class="grid grid-cols-1 lg:grid-cols-3 gap-6 mb-10">
    <!-- Monthly Trend Chart (Full width) -->
    <div class="glass-card rounded-2xl p-6 lg:col-span-3">
        <h3 class="text-lg font-semibold text-white mb-4">📈 <span id="monthly-chart-title">Aylık Trend</span></h3>
        <div style="position: relative; height: 200px;">
            <canvas id="monthlyChart"></canvas>
        </div>
    </div>
</div>

<!-- Recent Activities Grid -->
<div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
    <!-- Recent Invoice Activity Feed -->
    <div class="glass-card rounded-2xl p-6">
        <h3 class="text-lg font-semibold text-white mb-4">🕐 Son Fatura Senkronizasyonları</h3>
        <div id="recent-activity" class="space-y-3 max-h-96 overflow-y-auto">
            <div class="text-center text-slate-500 py-8">Yükleniyor...</div>
        </div>
    </div>
    <!-- Recent PO Activity Feed -->
    <div class="glass-card rounded-2xl p-6">
        <h3 class="text-lg font-semibold text-white mb-4">🕐 Son GF Senkronizasyonları</h3>
        <div id="recent-po-activity" class="space-y-3 max-h-96 overflow-y-auto">
            <div class="text-center text-slate-500 py-8">Yükleniyor...</div>
        </div>
    </div>
</div>

<!-- Quick Actions -->
<div class="mt-10">
    <h3 class="text-lg font-semibold text-white mb-4">⚡ Hızlı İşlemler</h3>
    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 xl:grid-cols-5 gap-4">
        <a href="invoices_comparison.php"
            class="glass-card rounded-xl p-5 hover:bg-slate-700/50 transition-all duration-300 flex items-center gap-4 group">
            <div
                class="w-14 h-14 bg-gradient-to-br from-violet-500 to-purple-600 rounded-xl flex items-center justify-center text-2xl transform group-hover:scale-110 transition-transform">
                📄
            </div>
            <div class="flex-1">
                <h4 class="text-base font-semibold text-white group-hover:text-violet-400 transition-colors">Faturaları
                    Yönet</h4>
                <p class="text-xs text-slate-400 mt-1">Faturaları görüntüle ve senkronize et</p>
            </div>
        </a>
        <a href="purchase_orders.php"
            class="glass-card rounded-xl p-5 hover:bg-slate-700/50 transition-all duration-300 flex items-center gap-4 group">
            <div
                class="w-14 h-14 bg-gradient-to-br from-amber-500 to-orange-600 rounded-xl flex items-center justify-center text-2xl transform group-hover:scale-110 transition-transform">
                📋
            </div>
            <div class="flex-1">
                <h4 class="text-base font-semibold text-white group-hover:text-amber-400 transition-colors">Gider
                    Faturaları</h4>
                <p class="text-xs text-slate-400 mt-1">GF'leri görüntüle ve senkronize et</p>
            </div>
        </a>
        <a href="invoices_comparison.php"
            class="glass-card rounded-xl p-5 hover:bg-slate-700/50 transition-all duration-300 flex items-center gap-4 group">
            <div
                class="w-14 h-14 bg-gradient-to-br from-cyan-500 to-teal-600 rounded-xl flex items-center justify-center text-2xl transform group-hover:scale-110 transition-transform">
                ⚖️
            </div>
            <div class="flex-1">
                <h4 class="text-base font-semibold text-white group-hover:text-cyan-400 transition-colors">Fatura
                    Karşılaştır</h4>
                <p class="text-xs text-slate-400 mt-1">Zoho ve Paraşüt farklarını kontrol et</p>
            </div>
        </a>
        <a href="settings.php"
            class="glass-card rounded-xl p-5 hover:bg-slate-700/50 transition-all duration-300 flex items-center gap-4 group">
            <div
                class="w-14 h-14 bg-gradient-to-br from-emerald-500 to-green-600 rounded-xl flex items-center justify-center text-2xl transform group-hover:scale-110 transition-transform">
                ⚙️
            </div>
            <div class="flex-1">
                <h4 class="text-base font-semibold text-white group-hover:text-emerald-400 transition-colors">Ayarlar
                </h4>
                <p class="text-xs text-slate-400 mt-1">API anahtarlarını ve filtreleri düzenle</p>
            </div>
        </a>
        <button onclick="syncZohoStockToParasut()"
            class="glass-card rounded-xl p-5 hover:bg-slate-700/50 transition-all duration-300 flex items-center gap-4 group text-left">
            <div
                class="w-14 h-14 bg-gradient-to-br from-amber-500 to-orange-600 rounded-xl flex items-center justify-center text-2xl transform group-hover:scale-110 transition-transform">
                📦
            </div>
            <div class="flex-1">
                <h4 class="text-base font-semibold text-white group-hover:text-amber-400 transition-colors">Stokları
                    Senkronize Et</h4>
                <p class="text-xs text-slate-400 mt-1">Zoho -> Paraşüt Stok Aktarımı</p>
            </div>
        </button>
        <button onclick="syncZohoInvoicesToParasut()"
            class="glass-card rounded-xl p-5 hover:bg-slate-700/50 transition-all duration-300 flex items-center gap-4 group text-left">
            <div
                class="w-14 h-14 bg-gradient-to-br from-blue-500 to-indigo-600 rounded-xl flex items-center justify-center text-2xl transform group-hover:scale-110 transition-transform">
                📥
            </div>
            <div class="flex-1">
                <h4 class="text-base font-semibold text-white group-hover:text-blue-400 transition-colors">Faturaları
                    Aktar</h4>
                <p class="text-xs text-slate-400 mt-1">Zoho -> Paraşüt Fatura Aktarımı</p>
            </div>
        </button>
        <button onclick="startFullSync()"
            class="glass-card rounded-xl p-5 hover:bg-slate-700/50 transition-all duration-300 flex items-center gap-4 group text-left lg:col-span-2">
            <div
                class="w-14 h-14 bg-gradient-to-br from-rose-500 to-pink-600 rounded-xl flex items-center justify-center text-2xl transform group-hover:scale-110 transition-transform">
                🔄
            </div>
            <div class="flex-1">
                <h4 class="text-base font-semibold text-white group-hover:text-rose-400 transition-colors">Tam
                    Senkronize Et</h4>
                <p class="text-xs text-slate-400 mt-1">Paraşüt → Zoho: Yeni faturalar + durum güncellemeleri</p>
            </div>
        </button>
    </div>
</div>

<!-- Full Sync Progress Modal -->
<div id="fullSyncModal" class="fixed inset-0 bg-black/70 z-50 hidden items-center justify-center" style="display:none;">
    <div class="glass rounded-2xl p-8 w-full max-w-lg mx-4 border border-white/10">
        <div class="flex justify-between items-center mb-6">
            <h3 class="text-xl font-bold text-white">🔄 Tam Senkronizasyon</h3>
            <button id="fullSyncCancelBtn" onclick="cancelFullSync()"
                class="text-slate-400 hover:text-red-400 text-sm px-3 py-1 rounded-lg border border-slate-600 hover:border-red-500 transition-all">İptal</button>
        </div>

        <!-- Phase indicators -->
        <div class="flex gap-2 mb-6">
            <div id="phase-1" class="flex-1 rounded-lg p-3 bg-slate-800/50 border border-slate-700">
                <div class="text-xs text-slate-500 mb-1">Faz 1</div>
                <div class="text-sm font-medium text-slate-300">📥 Paraşüt Çek</div>
            </div>
            <div id="phase-2" class="flex-1 rounded-lg p-3 bg-slate-800/50 border border-slate-700">
                <div class="text-xs text-slate-500 mb-1">Faz 2</div>
                <div class="text-sm font-medium text-slate-300">📤 Zoho Aktar</div>
            </div>
            <div id="phase-3" class="flex-1 rounded-lg p-3 bg-slate-800/50 border border-slate-700">
                <div class="text-xs text-slate-500 mb-1">Faz 3</div>
                <div class="text-sm font-medium text-slate-300">🔄 Durum Güncelle</div>
            </div>
        </div>

        <!-- Progress bar -->
        <div class="mb-4">
            <div class="flex justify-between text-xs text-slate-400 mb-1">
                <span id="syncProgressLabel">Hazırlanıyor...</span>
                <span id="syncProgressPercent">0%</span>
            </div>
            <div class="w-full bg-slate-700 rounded-full h-2">
                <div id="syncProgressBar"
                    class="bg-gradient-to-r from-indigo-500 to-pink-500 h-2 rounded-full transition-all duration-300"
                    style="width:0%"></div>
            </div>
        </div>

        <!-- Stats -->
        <div class="grid grid-cols-3 gap-3 mb-4">
            <div class="bg-slate-800/50 rounded-lg p-3 text-center">
                <div class="text-2xl font-bold text-emerald-400" id="syncStatSuccess">0</div>
                <div class="text-xs text-slate-500">Başarılı</div>
            </div>
            <div class="bg-slate-800/50 rounded-lg p-3 text-center">
                <div class="text-2xl font-bold text-red-400" id="syncStatError">0</div>
                <div class="text-xs text-slate-500">Hatalı</div>
            </div>
            <div class="bg-slate-800/50 rounded-lg p-3 text-center">
                <div class="text-2xl font-bold text-blue-400" id="syncStatSkipped">0</div>
                <div class="text-xs text-slate-500">Atlandı</div>
            </div>
        </div>

        <!-- Log -->
        <div id="syncLogContainer"
            class="bg-black/30 rounded-xl p-3 max-h-40 overflow-y-auto text-xs font-mono custom-scrollbar">
            <div class="text-slate-500">İşlem bekleniyor...</div>
        </div>

        <!-- Close button (hidden until complete) -->
        <button id="fullSyncCloseBtn" onclick="closeFullSyncModal()"
            class="hidden w-full mt-4 bg-indigo-600 hover:bg-indigo-500 text-white font-semibold py-3 rounded-xl transition-colors">Kapat</button>
    </div>
</div>

<script>
    let yearChart, statusChart, monthlyChart;
    let countdownInterval;
    let countdownSeconds = 60;
    document.addEventListener('DOMContentLoaded', () => {
        fetchStats();
        startCountdown();
        // Auto-refresh every 60 seconds
        setInterval(() => {
            fetchStats();
            resetCountdown();
        }, 60000);
    });
    function startCountdown() {
        countdownInterval = setInterval(() => {
            countdownSeconds--;
            if (countdownSeconds < 0) {
                countdownSeconds = 60;
            }
            document.getElementById('countdown-timer').textContent = countdownSeconds;
        }, 1000);
    }
    function resetCountdown() {
        countdownSeconds = 60;
        document.getElementById('countdown-timer').textContent = countdownSeconds;
    }
    function updateTimestamp() {
        const now = new Date();
        const timeStr = now.toLocaleTimeString('tr-TR', { hour: '2-digit', minute: '2-digit', second: '2-digit' });
        document.getElementById('last-update-time').textContent = timeStr;
    }
    function fetchStats() {
        // Check if Chart.js is loaded
        if (typeof Chart === 'undefined') {
            console.warn('Chart.js henüz yüklenmedi, 500ms sonra tekrar denenecek...');
            setTimeout(fetchStats, 500);
            return;
        }
        const formData = new FormData();
        formData.append('action', 'get_dashboard_stats');
        axios.post('api_handler.php', formData)
            .then(res => {
                if (res.data.success) {
                    const stats = res.data.data;
                    try {
                        // Update Quick Stats Cards
                        document.getElementById('stat-success-rate').textContent = stats.success_rate + '%';
                        document.getElementById('stat-success-bar').style.width = stats.success_rate + '%';
                        document.getElementById('stat-total-invoices').textContent = stats.status.total || '0';
                        document.getElementById('stat-synced-short').textContent = stats.status.synced || '0';
                        document.getElementById('stat-unsynced-count').textContent = stats.status.unsynced || '0';
                        document.getElementById('stat-errors-count').textContent = stats.status.errors || '0';
                        // Update Product Stats
                        if (stats.product_stats) {
                            document.getElementById('stat-parasut-total').textContent = stats.product_stats.parasut_total || '0';
                            document.getElementById('stat-zoho-total').textContent = stats.product_stats.zoho_total || '0';
                            document.getElementById('stat-missing-zoho').textContent = stats.product_stats.missing_in_zoho || '0';
                            document.getElementById('stat-missing-parasut').textContent = stats.product_stats.missing_in_parasut || '0';
                        }
                        // Update Purchase Order Stats
                        if (stats.purchase_order_stats) {
                            document.getElementById('stat-po-total').textContent = stats.purchase_order_stats.status.total || '0';
                            document.getElementById('stat-po-synced').textContent = stats.purchase_order_stats.status.synced || '0';
                            document.getElementById('stat-po-unsynced').textContent = stats.purchase_order_stats.status.unsynced || '0';
                            document.getElementById('stat-po-errors').textContent = stats.purchase_order_stats.status.errors || '0';
                            document.getElementById('stat-po-success-rate').textContent = stats.purchase_order_stats.success_rate + '%';
                            document.getElementById('stat-po-success-bar').style.width = stats.purchase_order_stats.success_rate + '%';
                        }
                        // Create Charts
                        createYearChart(stats.by_year);
                        createStatusChart(stats.status);
                        createMonthlyChart(stats.monthly_trend);
                        // Populate Recent Activity
                        populateRecentActivity(stats.recent_activity);
                        // Populate Recent PO Activity
                        if (stats.purchase_order_stats && stats.purchase_order_stats.recent_activity) {
                            populateRecentPOActivity(stats.purchase_order_stats.recent_activity);
                        }
                        // Update timestamp
                        updateTimestamp();
                    } catch (error) {
                        console.error('Error rendering dashboard:', error);
                    }
                }
            })
            .catch(err => {
                console.error('Dashboard Stats Error:', err);
            });
    }
    function createYearChart(yearData) {
        const ctx = document.getElementById('yearChart').getContext('2d');
        // Destroy existing chart if it exists
        if (yearChart) yearChart.destroy();
        const years = yearData.map(d => d.year);
        const counts = yearData.map(d => parseInt(d.count));
        const syncedCounts = yearData.map(d => parseInt(d.synced_count));
        yearChart = new Chart(ctx, {
            type: 'bar',
            data: {
                labels: years,
                datasets: [
                    {
                        label: 'Toplam Faturalar',
                        data: counts,
                        backgroundColor: 'rgba(139, 92, 246, 0.7)',
                        borderColor: 'rgba(139, 92, 246, 1)',
                        borderWidth: 2,
                        borderRadius: 6
                    },
                    {
                        label: 'Senkronize',
                        data: syncedCounts,
                        backgroundColor: 'rgba(16, 185, 129, 0.7)',
                        borderColor: 'rgba(16, 185, 129, 1)',
                        borderWidth: 2,
                        borderRadius: 6
                    }
                ]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        position: 'bottom',
                        labels: { color: '#cbd5e1', font: { size: 12 } }
                    }
                },
                scales: {
                    y: {
                        beginAtZero: true,
                        ticks: { color: '#94a3b8' },
                        grid: { color: 'rgba(148, 163, 184, 0.1)' }
                    },
                    x: {
                        ticks: { color: '#94a3b8' },
                        grid: { display: false }
                    }
                }
            }
        });
    }
    function createStatusChart(statusData) {
        const ctx = document.getElementById('statusChart').getContext('2d');
        // Destroy existing chart if it exists
        if (statusChart) statusChart.destroy();
        statusChart = new Chart(ctx, {
            type: 'doughnut',
            data: {
                labels: ['Senkronize', 'Bekleyen', 'Hatalı'],
                datasets: [{
                    data: [
                        parseInt(statusData.synced) || 0,
                        parseInt(statusData.unsynced) || 0,
                        parseInt(statusData.errors) || 0
                    ],
                    backgroundColor: [
                        'rgba(16, 185, 129, 0.8)',
                        'rgba(251, 146, 60, 0.8)',
                        'rgba(239, 68, 68, 0.8)'
                    ],
                    borderColor: [
                        'rgba(16, 185, 129, 1)',
                        'rgba(251, 146, 60, 1)',
                        'rgba(239, 68, 68, 1)'
                    ],
                    borderWidth: 2
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        position: 'bottom',
                        labels: { color: '#cbd5e1', font: { size: 12 } }
                    }
                }
            }
        });
    }
    function createMonthlyChart(monthlyData) {
        const ctx = document.getElementById('monthlyChart').getContext('2d');
        // Destroy existing chart if it exists
        if (monthlyChart) monthlyChart.destroy();
        const monthNames = ['Oca', 'Şub', 'Mar', 'Nis', 'May', 'Haz', 'Tem', 'Ağu', 'Eyl', 'Eki', 'Kas', 'Ara'];
        const currentYear = new Date().getFullYear();
        const months = monthlyData.map(d => monthNames[parseInt(d.month) - 1]);
        const counts = monthlyData.map(d => parseInt(d.count));
        // Update title dynamically
        const titleEl = document.getElementById('monthly-chart-title');
        if (titleEl) titleEl.textContent = currentYear + ' Aylık Trend';
        monthlyChart = new Chart(ctx, {
            type: 'line',
            data: {
                labels: months,
                datasets: [{
                    label: currentYear + ' Faturalar',
                    data: counts,
                    backgroundColor: 'rgba(6, 182, 212, 0.2)',
                    borderColor: 'rgba(6, 182, 212, 1)',
                    borderWidth: 3,
                    fill: true,
                    tension: 0.4,
                    pointBackgroundColor: '#06b6d4',
                    pointBorderColor: '#fff',
                    pointBorderWidth: 2,
                    pointRadius: 5,
                    pointHoverRadius: 7
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        position: 'bottom',
                        labels: { color: '#cbd5e1', font: { size: 12 } }
                    }
                },
                scales: {
                    y: {
                        beginAtZero: true,
                        ticks: { color: '#94a3b8' },
                        grid: { color: 'rgba(148, 163, 184, 0.1)' }
                    },
                    x: {
                        ticks: { color: '#94a3b8' },
                        grid: { display: false }
                    }
                }
            }
        });
    }

    function populateRecentActivity(activities) {
        const container = document.getElementById('recent-activity');
        if (!activities || activities.length === 0) {
            container.innerHTML = '<div class="text-center text-slate-500 py-8">Henüz senkronizasyon yok</div>';
            return;
        }
        container.innerHTML = activities.map(activity => {
            const isSuccess = activity.synced_to_zoho == 1;
            const statusIcon = isSuccess ? '✓' : '✗';
            const bgClass = isSuccess ? 'bg-emerald-500/20' : 'bg-red-500/20';
            const textClass = isSuccess ? 'text-emerald-400' : 'text-red-400';
            const date = new Date(activity.synced_at);
            const timeStr = date.toLocaleString('tr-TR', { month: 'short', day: 'numeric', hour: '2-digit', minute: '2-digit' });
            return `
                <div class="flex items-center gap-3 p-3 bg-slate-800/50 rounded-lg hover:bg-slate-800/70 transition-colors">
                    <div class="w-8 h-8 rounded-full ${bgClass} flex items-center justify-center ${textClass} font-semibold text-sm">
                        ${statusIcon}
                    </div>
                    <div class="flex-1 min-w-0">
                        <div class="text-sm font-medium text-white truncate">${escapeHtml(activity.invoice_number)}</div>
                        <div class="text-xs text-slate-400">${activity.net_total} ${escapeHtml(activity.currency)}</div>
                    </div>
                    <div class="text-xs text-slate-500">
                        ${timeStr}
                    </div>
                </div>
            `;
        }).join('');
    }

    function populateRecentPOActivity(activities) {
        const container = document.getElementById('recent-po-activity');
        if (!activities || activities.length === 0) {
            container.innerHTML = '<div class="text-center text-slate-500 py-8">Henüz GF senkronizasyonu yok</div>';
            return;
        }
        container.innerHTML = activities.map(activity => {
            const isSuccess = activity.synced_to_zoho == 1;
            const statusIcon = isSuccess ? '✓' : '✗';
            const bgClass = isSuccess ? 'bg-emerald-500/20' : 'bg-red-500/20';
            const textClass = isSuccess ? 'text-emerald-400' : 'text-red-400';
            const date = new Date(activity.synced_at);
            const timeStr = date.toLocaleString('tr-TR', { month: 'short', day: 'numeric', hour: '2-digit', minute: '2-digit' });
            return `
                <div class="flex items-center gap-3 p-3 bg-slate-800/50 rounded-lg hover:bg-slate-800/70 transition-colors">
                    <div class="w-8 h-8 rounded-full ${bgClass} flex items-center justify-center ${textClass} font-semibold text-sm">
                        ${statusIcon}
                    </div>
                    <div class="flex-1 min-w-0">
                        <div class="text-sm font-medium text-white truncate">${escapeHtml(activity.invoice_number || 'N/A')}</div>
                        <div class="text-xs text-slate-400">${activity.net_total || 0} ${escapeHtml(activity.currency || 'TRY')}</div>
                    </div>
                    <div class="text-xs text-slate-500">
                        ${timeStr}
                    </div>
                </div>
            `;
        }).join('');
    }
    // ==================== FULL SYNC ====================
    let fullSyncCancelled = false;
    let syncStats = { success: 0, error: 0, skipped: 0 };

    function startFullSync() {
        Swal.fire({
            title: 'Tam Senkronizasyon',
            html: `<div class="text-left text-sm">
                <p class="mb-2"><strong>3 aşamalı senkronizasyon başlatılacak:</strong></p>
                <ol class="list-decimal pl-5 space-y-1">
                    <li>Paraşüt'ten yeni faturaları çek</li>
                    <li>Bekleyen faturaları Zoho'ya aktar</li>
                    <li>Tamamlanmamış faturaların durumunu güncelle</li>
                </ol>
                <p class="mt-3 text-gray-400">Bu işlem birkaç dakika sürebilir.</p>
            </div>`,
            icon: 'question',
            showCancelButton: true,
            confirmButtonText: 'Başlat',
            cancelButtonText: 'Vazgeç',
            confirmButtonColor: '#6366f1'
        }).then(result => {
            if (result.isConfirmed) runFullSync();
        });
    }

    async function runFullSync() {
        fullSyncCancelled = false;
        syncStats = { success: 0, error: 0, skipped: 0 };
        showFullSyncModal();

        try {
            // === FAZ 1: Paraşüt'ten çek ===
            setPhaseActive(1);
            syncLog('📥 Faz 1: Paraşüt\'ten faturalar çekiliyor...');
            updateProgress('Paraşüt\'ten çekiliyor...', 5);

            const fetchForm = new FormData();
            fetchForm.append('action', 'fetch_parasut_invoices');
            fetchForm.append('full_sync', 'false');
            const fetchRes = await axios.post('api_handler.php', fetchForm);
            if (fetchRes.data.success) {
                syncLog(`✅ Paraşüt: ${fetchRes.data.inserted_count || 0} fatura güncellendi`);
            } else {
                syncLog('⚠️ Paraşüt çekme hatası: ' + (fetchRes.data.message || 'Bilinmeyen'));
            }
            setPhaseComplete(1);

            if (fullSyncCancelled) { syncLog('🛑 İptal edildi.'); finishSync(); return; }

            // === FAZ 2: Zoho'ya aktar ===
            setPhaseActive(2);
            syncLog('📤 Faz 2: Bekleyen faturalar Zoho\'ya aktarılıyor...');
            updateProgress('Bekleyen faturalar alınıyor...', 15);

            let hasMore = true;
            let totalExported = 0;
            while (hasMore && !fullSyncCancelled) {
                const idForm = new FormData();
                idForm.append('action', 'get_unsynced_invoices_ids');
                idForm.append('limit', '10');
                idForm.append('sync_status', 'all');
                const idRes = await axios.post('api_handler.php', idForm);

                if (!idRes.data.success || !idRes.data.data || idRes.data.data.length === 0) {
                    hasMore = false;
                    break;
                }

                const batch = idRes.data.data;
                const totalRemaining = idRes.data.total_remaining || batch.length;
                syncLog(`📋 ${totalRemaining} bekleyen fatura bulundu, ${batch.length} işlenecek`);

                for (let i = 0; i < batch.length && !fullSyncCancelled; i++) {
                    const inv = batch[i];
                    const pct = 15 + Math.min(50, (totalExported / Math.max(totalRemaining, 1)) * 50);
                    updateProgress(`Fatura aktarılıyor: ${inv.invoice_number || inv.id}`, pct);

                    try {
                        const expForm = new FormData();
                        expForm.append('action', 'export_invoice_to_zoho');
                        expForm.append('invoice_id', inv.id);
                        const expRes = await axios.post('api_handler.php', expForm);

                        if (expRes.data.success) {
                            syncStats.success++;
                            syncLog(`✅ ${inv.invoice_number || inv.id}`);
                        } else {
                            syncStats.error++;
                            syncLog(`❌ ${inv.invoice_number || inv.id}: ${expRes.data.message}`);
                        }
                    } catch (e) {
                        syncStats.error++;
                        syncLog(`❌ ${inv.invoice_number || inv.id}: ${e.response?.data?.message || e.message}`);
                    }
                    totalExported++;
                    updateSyncStats();
                    await sleep(500);
                }

                if (batch.length < 10) hasMore = false;
            }
            setPhaseComplete(2);
            syncLog(`📤 Faz 2 tamamlandı: ${totalExported} fatura işlendi`);

            if (fullSyncCancelled) { syncLog('🛑 İptal edildi.'); finishSync(); return; }

            // === FAZ 3: Durum güncelle ===
            setPhaseActive(3);
            syncLog('🔄 Faz 3: Senkronize edilmiş faturaların durumu kontrol ediliyor...');
            updateProgress('Durum güncellemesi...', 70);

            const candForm = new FormData();
            candForm.append('action', 'get_status_update_candidates');
            candForm.append('limit', '50');
            const candRes = await axios.post('api_handler.php', candForm);

            if (candRes.data.success && candRes.data.data) {
                const candidates = candRes.data.data;
                syncLog(`🔍 ${candidates.length} fatura kontrol edilecek (toplam: ${candRes.data.total})`);

                for (let i = 0; i < candidates.length && !fullSyncCancelled; i++) {
                    const inv = candidates[i];
                    const pct = 70 + (i / candidates.length) * 28;
                    updateProgress(`Durum kontrol: ${inv.invoice_number || inv.id}`, pct);

                    try {
                        const updForm = new FormData();
                        updForm.append('action', 'update_invoice_status_in_zoho');
                        updForm.append('invoice_id', inv.id);
                        updForm.append('zoho_invoice_id', inv.zoho_invoice_id);
                        const updRes = await axios.post('api_handler.php', updForm);

                        if (updRes.data.success) {
                            if (updRes.data.skipped) {
                                syncStats.skipped++;
                            } else {
                                syncStats.success++;
                                syncLog(`🔄 ${inv.invoice_number || inv.id}: ${updRes.data.message}`);
                            }
                        } else {
                            syncStats.error++;
                            syncLog(`⚠️ ${inv.invoice_number || inv.id}: ${updRes.data.message}`);
                        }
                    } catch (e) {
                        syncStats.error++;
                    }
                    updateSyncStats();
                    await sleep(500);
                }
            }
            setPhaseComplete(3);
            updateProgress('Tamamlandı!', 100);
            syncLog(`✅ Tam senkronizasyon tamamlandı! Başarılı: ${syncStats.success}, Hata: ${syncStats.error}, Atlandı: ${syncStats.skipped}`);

        } catch (e) {
            syncLog(`❌ Kritik hata: ${e.message}`);
        }

        finishSync();
        fetchStats();
    }

    function showFullSyncModal() {
        const modal = document.getElementById('fullSyncModal');
        modal.classList.remove('hidden');
        modal.style.display = 'flex';
        document.getElementById('fullSyncCloseBtn').classList.add('hidden');
        document.getElementById('fullSyncCancelBtn').classList.remove('hidden');
        document.getElementById('syncLogContainer').innerHTML = '';
        document.getElementById('syncStatSuccess').textContent = '0';
        document.getElementById('syncStatError').textContent = '0';
        document.getElementById('syncStatSkipped').textContent = '0';
        ['phase-1', 'phase-2', 'phase-3'].forEach(id => {
            document.getElementById(id).className = 'flex-1 rounded-lg p-3 bg-slate-800/50 border border-slate-700';
        });
    }

    function closeFullSyncModal() {
        const modal = document.getElementById('fullSyncModal');
        modal.classList.add('hidden');
        modal.style.display = 'none';
    }

    function cancelFullSync() {
        fullSyncCancelled = true;
        document.getElementById('fullSyncCancelBtn').textContent = 'İptal ediliyor...';
        document.getElementById('fullSyncCancelBtn').disabled = true;
    }

    function finishSync() {
        document.getElementById('fullSyncCloseBtn').classList.remove('hidden');
        document.getElementById('fullSyncCancelBtn').classList.add('hidden');
    }

    function setPhaseActive(n) {
        document.getElementById(`phase-${n}`).className = 'flex-1 rounded-lg p-3 bg-indigo-500/10 border border-indigo-500/50';
    }
    function setPhaseComplete(n) {
        document.getElementById(`phase-${n}`).className = 'flex-1 rounded-lg p-3 bg-emerald-500/10 border border-emerald-500/50';
    }

    function updateProgress(label, pct) {
        document.getElementById('syncProgressLabel').textContent = label;
        document.getElementById('syncProgressPercent').textContent = Math.round(pct) + '%';
        document.getElementById('syncProgressBar').style.width = pct + '%';
    }

    function updateSyncStats() {
        document.getElementById('syncStatSuccess').textContent = syncStats.success;
        document.getElementById('syncStatError').textContent = syncStats.error;
        document.getElementById('syncStatSkipped').textContent = syncStats.skipped;
    }

    function syncLog(msg) {
        const container = document.getElementById('syncLogContainer');
        const line = document.createElement('div');
        line.className = 'py-0.5 text-slate-300';
        line.textContent = msg;
        container.appendChild(line);
        container.scrollTop = container.scrollHeight;
    }

    function sleep(ms) { return new Promise(r => setTimeout(r, ms)); }
</script>
<?php include 'templates/footer.php'; ?>