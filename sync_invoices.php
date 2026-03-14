<?php
$pageTitle = 'Sync Gelir Faturaları';
include 'templates/header.php';
?>

<div class="glass-card rounded-2xl p-6 max-w-5xl mx-auto mt-6">
    <div class="mb-8 text-center border-b border-slate-700/50 pb-6">
        <h2 class="text-3xl font-bold text-white mb-2">Fatura Senkronizasyon Merkezi</h2>
        <p class="text-slate-400">Paraşüt'teki faturaları Zoho CRM'e toplu olarak aktarın.</p>
    </div>

    <!-- Filters and Batch Settings -->
    <div class="grid grid-cols-1 md:grid-cols-4 gap-4 mb-8 bg-slate-900/40 p-5 rounded-xl border border-slate-800">
        <div class="flex flex-col gap-1.5">
            <label class="text-xs font-semibold text-slate-500 uppercase">Yıl Seçimi</label>
            <select id="yearFilter" onchange="refreshStats()"
                class="bg-slate-800 border border-slate-700 text-white rounded-lg px-3 py-2 text-sm focus:ring-2 focus:ring-violet-500/50 outline-none transition-all">
                <option value="">Tüm Yıllar</option>
                <?php for ($y = date('Y'); $y >= 2019; $y--): ?>
                    <option value="<?php echo $y; ?>"><?php echo $y; ?></option>
                <?php endfor; ?>
            </select>
        </div>
        <div class="flex flex-col gap-1.5">
            <label class="text-xs font-semibold text-slate-500 uppercase">Durum Filtresi</label>
            <select id="statusFilter" onchange="refreshStats()"
                class="bg-slate-800 border border-slate-700 text-white rounded-lg px-3 py-2 text-sm focus:ring-2 focus:ring-violet-500/50 outline-none transition-all">
                <option value="0">Sadece Senkronize Edilmeyenler</option>
                <option value="error">Sadece Hatalılar</option>
                <option value="all">Hepsi (Senkronize Edilmemiş + Hatalı)</option>
            </select>
        </div>
        <div class="flex flex-col gap-1.5">
            <label class="text-xs font-semibold text-slate-500 uppercase">Paket Boyutu</label>
            <select id="batchSize"
                class="bg-slate-800 border border-slate-700 text-white rounded-lg px-3 py-2 text-sm focus:ring-2 focus:ring-violet-500/50 outline-none transition-all">
                <option value="50">50 Fatura</option>
                <option value="100">100 Fatura</option>
                <option value="250">250 Fatura</option>
                <option value="500">500 Fatura</option>
                <option value="1000">1000 Fatura</option>
            </select>
        </div>
        <div class="flex flex-col gap-1.5 justify-end">
            <button onclick="refreshStats()"
                class="bg-slate-700 hover:bg-slate-600 text-white px-4 py-2 rounded-lg text-sm font-medium transition-colors flex items-center justify-center gap-2">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                        d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15">
                    </path>
                </svg>
                İstatistikleri Yenile
            </button>
        </div>
    </div>

    <!-- Stats Grid -->
    <div class="grid grid-cols-1 md:grid-cols-4 gap-4 mb-8">
        <div class="bg-slate-800/50 rounded-xl p-4 border border-white/5 shadow-lg">
            <div class="text-[10px] text-slate-500 uppercase tracking-widest mb-1 font-bold">TOPLAM BEKLEYEN</div>
            <div class="text-3xl font-bold text-white font-mono" id="totalPending">0</div>
        </div>
        <div class="bg-indigo-900/20 rounded-xl p-4 border border-indigo-700/30">
            <div class="text-[10px] text-indigo-400 uppercase tracking-widest mb-1 font-bold">İŞLENEN (TOPLAM)</div>
            <div class="text-3xl font-bold text-indigo-400 font-mono" id="sessionProcessed">0</div>
        </div>
        <div class="bg-emerald-900/20 rounded-xl p-4 border border-emerald-700/30">
            <div class="text-[10px] text-emerald-400 uppercase tracking-widest mb-1 font-bold">BAŞARILI</div>
            <div class="text-3xl font-bold text-emerald-400 font-mono" id="sessionSuccess">0</div>
        </div>
        <div class="bg-red-900/20 rounded-xl p-4 border border-red-700/30">
            <div class="text-[10px] text-red-400 uppercase tracking-widest mb-1 font-bold">HATALI</div>
            <div class="text-3xl font-bold text-red-400 font-mono" id="sessionError">0</div>
        </div>
    </div>

    <!-- Progress UI -->
    <div id="progressSection" class="mb-8 p-6 bg-slate-900/60 rounded-xl border border-slate-800 hidden">
        <!-- Master Progress -->
        <div class="mb-6">
            <div class="flex justify-between text-xs font-bold text-slate-400 mb-2 uppercase tracking-tight">
                <span>GENEL İLERLEME</span>
                <span id="masterPercent">0%</span>
            </div>
            <div class="w-full bg-slate-800 rounded-full h-4 overflow-hidden border border-slate-700 p-0.5">
                <div id="masterProgressBar"
                    class="h-full bg-gradient-to-r from-violet-600 to-indigo-600 transition-all duration-300 w-0 rounded-full shadow-[0_0_10px_rgba(124,58,237,0.5)]">
                </div>
            </div>
        </div>

        <!-- Current Batch Progress -->
        <div>
            <div class="flex justify-between text-[10px] font-bold text-slate-500 mb-1.5 uppercase tracking-widest">
                <span id="batchStatus">Paket Hazırlanıyor...</span>
                <span id="batchPercent">0%</span>
            </div>
            <div class="w-full bg-slate-800 rounded-full h-1.5 overflow-hidden">
                <div id="batchProgressBar" class="h-full bg-emerald-500 transition-all duration-300 w-0"></div>
            </div>
        </div>
    </div>

    <!-- Active Controls -->
    <div class="flex flex-wrap justify-center gap-4 mb-8">
        <button id="startSyncBtn" onclick="startBulkSync()"
            class="px-10 py-4 rounded-xl bg-violet-600 hover:bg-violet-500 text-white font-bold text-lg shadow-xl shadow-violet-900/20 transition-all transform hover:scale-[1.02] active:scale-95 flex items-center gap-3">
            <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z">
                </path>
            </svg>
            Senkronizasyonu Başlat
        </button>

        <button id="pauseSyncBtn" onclick="pauseSync()"
            class="hidden px-10 py-4 rounded-xl bg-amber-600 hover:bg-amber-500 text-white font-bold text-lg shadow-xl shadow-amber-900/20 transition-all transform hover:scale-[1.02] active:scale-95 flex items-center gap-3">
            <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                    d="M10 9v6m4-6v6m7-3a9 9 0 11-18 0 9 9 0 0118 0z"></path>
            </svg>
            Durdaklat
        </button>

        <button id="resumeSyncBtn" onclick="resumeSync()"
            class="hidden px-10 py-4 rounded-xl bg-emerald-600 hover:bg-emerald-500 text-white font-bold text-lg shadow-xl shadow-emerald-900/20 transition-all transform hover:scale-[1.02] active:scale-95 flex items-center gap-3">
            <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                    d="M14.752 11.168l-3.197-2.132A1 1 0 0010 9.87v4.263a1 1 0 001.555.832l3.197-2.132a1 1 0 000-1.664z">
                </path>
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                    d="M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path>
            </svg>
            Devam Et
        </button>

        <button id="stopSyncBtn" onclick="stopSync()"
            class="hidden px-10 py-4 rounded-xl bg-red-600 hover:bg-red-500 text-white font-bold text-lg shadow-xl shadow-red-900/20 transition-all transform hover:scale-[1.02] active:scale-95 flex items-center gap-3">
            <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
            </svg>
            İptal Et
        </button>
    </div>

    <!-- Detailed Log Console -->
    <div class="bg-[#0f172a] rounded-xl border border-slate-800 overflow-hidden shadow-2xl">
        <div class="bg-slate-900/80 px-4 py-3 border-b border-slate-800 flex justify-between items-center">
            <div class="flex items-center gap-2">
                <div class="w-2 h-2 rounded-full bg-emerald-500 animate-pulse"></div>
                <span class="text-xs font-bold text-slate-400 uppercase tracking-widest font-mono">GERÇEK ZAMANLI İŞLEM
                    AKIŞI</span>
            </div>
            <button onclick="clearLog()"
                class="text-[10px] font-bold text-slate-500 hover:text-white uppercase transition-colors">Konsolu
                Temizle</button>
        </div>
        <div id="syncLog"
            class="p-4 h-80 overflow-y-auto font-mono text-[11px] text-slate-300 space-y-1 custom-scrollbar scroll-smooth">
            <div class="text-slate-500 italic">Sistem hazır. Filtreleri ayarlayıp başlatın.</div>
        </div>
    </div>
</div>

<script>
    // CSRF Token Setup
    axios.defaults.headers.common['X-CSRF-TOKEN'] = '<?php echo generateCsrfToken(); ?>';

    let isSyncing = false;
    let isPaused = false;
    let totalToProcess = 0;
    let currentProcessed = 0;
    let successCount = 0;
    let errorCount = 0;
    let currentBatchInvoices = [];
    let currentBatchIndex = 0;

    document.addEventListener('DOMContentLoaded', () => {
        refreshStats();
    });

    async function refreshStats() {
        const year = document.getElementById('yearFilter').value;
        const status = document.getElementById('statusFilter').value;
        const totalDisplay = document.getElementById('totalPending');

        totalDisplay.innerHTML = '<span class="loader w-4 h-4"></span>';

        try {
            const params = new URLSearchParams();
            params.append('action', 'get_unsynced_invoices_ids');
            params.append('limit', 1);
            params.append('year', year);
            params.append('sync_status', status);
            params.append('csrf_token', '<?php echo generateCsrfToken(); ?>');

            const res = await axios.post('api_handler.php', params);
            if (res.data.success) {
                totalToProcess = parseInt(res.data.total_remaining);
                totalDisplay.textContent = totalToProcess.toLocaleString('tr-TR');
            }
        } catch (e) {
            console.error(e);
            totalDisplay.textContent = 'Hata';
        }
    }

    function log(message, type = 'info') {
        const logContainer = document.getElementById('syncLog');
        const item = document.createElement('div');
        const time = new Date().toLocaleTimeString('tr-TR', { hour12: false });

        let icon = '○';
        let color = 'text-slate-400';

        if (type === 'success') { icon = '✓'; color = 'text-emerald-400'; }
        if (type === 'error') { icon = '✗'; color = 'text-red-400'; }
        if (type === 'warning') { icon = '⚠'; color = 'text-amber-400'; }
        if (type === 'batch') { icon = '⇉'; color = 'text-indigo-400 font-bold'; }

        item.className = `${color} border-b border-slate-800/30 pb-1.5 animate-in fade-in slide-in-from-left-2 duration-300`;
        item.innerHTML = `<span class="opacity-40 text-[9px] mr-2">[${time}]</span> <span class="mr-2">${icon}</span> ${message}`;

        logContainer.prepend(item);
    }

    function clearLog() {
        document.getElementById('syncLog').innerHTML = '<div class="text-slate-500 italic">Konsol temizlendi.</div>';
    }

    function updateProgressUI() {
        document.getElementById('sessionProcessed').textContent = currentProcessed;
        document.getElementById('sessionSuccess').textContent = successCount;
        document.getElementById('sessionError').textContent = errorCount;

        // Master Progress
        const masterPercent = totalToProcess > 0 ? Math.round((currentProcessed / totalToProcess) * 100) : 0;
        document.getElementById('masterPercent').textContent = masterPercent + '%';
        document.getElementById('masterProgressBar').style.width = masterPercent + '%';

        // Batch Progress
        const batchPercent = currentBatchInvoices.length > 0 ? Math.round((currentBatchIndex / currentBatchInvoices.length) * 100) : 0;
        document.getElementById('batchPercent').textContent = batchPercent + '%';
        document.getElementById('batchProgressBar').style.width = batchPercent + '%';
    }

    async function startBulkSync() {
        if (isSyncing) return;

        // Reset counters
        currentProcessed = 0;
        successCount = 0;
        errorCount = 0;
        isSyncing = true;
        isPaused = false;

        document.getElementById('startSyncBtn').classList.add('hidden');
        document.getElementById('pauseSyncBtn').classList.remove('hidden');
        document.getElementById('stopSyncBtn').classList.remove('hidden');
        document.getElementById('progressSection').classList.remove('hidden');

        log('Senkronizasyon süreci başlatıldı...', 'batch');
        await fetchAndProcessBatch();
    }

    function pauseSync() {
        isPaused = true;
        log('Süreç duraklatıldı. Mevcut faturadan sonra duracak.', 'warning');
        document.getElementById('pauseSyncBtn').classList.add('hidden');
        document.getElementById('resumeSyncBtn').classList.remove('hidden');
    }

    function resumeSync() {
        isPaused = false;
        log('Süreç devam ettiriliyor...', 'success');
        document.getElementById('resumeSyncBtn').classList.add('hidden');
        document.getElementById('pauseSyncBtn').classList.remove('hidden');
        processNextInBatch();
    }

    function stopSync() {
        if (!confirm('Senkronizasyon işlemini tamamen iptal etmek istediğinize emin misiniz?')) return;
        isSyncing = false;
        isPaused = false;
        log('Senkronizasyon kullanıcı tarafından iptal edildi.', 'error');
        resetUI();
    }

    function resetUI() {
        document.getElementById('startSyncBtn').classList.remove('hidden');
        document.getElementById('pauseSyncBtn').classList.add('hidden');
        document.getElementById('resumeSyncBtn').classList.add('hidden');
        document.getElementById('stopSyncBtn').classList.add('hidden');
    }

    async function fetchAndProcessBatch() {
        if (!isSyncing || isPaused) return;

        const year = document.getElementById('yearFilter').value;
        const status = document.getElementById('statusFilter').value;
        const batchSize = document.getElementById('batchSize').value;

        log(`Yeni paket isteniyor (${batchSize} adet)...`, 'info');

        try {
            const params = new URLSearchParams();
            params.append('action', 'get_unsynced_invoices_ids');
            params.append('limit', batchSize);
            params.append('year', year);
            params.append('sync_status', status);
            params.append('csrf_token', '<?php echo generateCsrfToken(); ?>');

            const res = await axios.post('api_handler.php', params);

            if (res.data.success && res.data.data && res.data.data.length > 0) {
                currentBatchInvoices = res.data.data;
                currentBatchIndex = 0;
                log(`${currentBatchInvoices.length} fatura alındı, işleme başlanıyor.`, 'info');
                processNextInInBatch();
            } else {
                log('İşlenecek fatura kalmadı!', 'success');
                isSyncing = false;
                resetUI();
                confetti({ particleCount: 150, spread: 80, origin: { y: 0.6 } });
            }
        } catch (e) {
            log('Paket alma hatası: ' + e.message, 'error');
            isSyncing = false;
            resetUI();
        }
    }

    async function processNextInInBatch() {
        if (!isSyncing || isPaused) return;

        if (currentBatchIndex >= currentBatchInvoices.length) {
            log('Paket tamamlandı. Bir sonraki paket yükleniyor...', 'batch');
            setTimeout(fetchAndProcessBatch, 1000);
            return;
        }

        const inv = currentBatchInvoices[currentBatchIndex];
        document.getElementById('batchStatus').textContent = `Fatura #${inv.invoice_number} Aktarılıyor...`;

        try {
            const params = new URLSearchParams();
            params.append('action', 'export_invoice_to_zoho');
            params.append('invoice_id', inv.id);
            params.append('csrf_token', '<?php echo generateCsrfToken(); ?>');

            const res = await axios.post('api_handler.php', params);

            if (res.data.success) {
                successCount++;
                log(`Başarılı: #${inv.invoice_number}`, 'success');
            } else {
                errorCount++;
                log(`Hata: #${inv.invoice_number} - ${res.data.message}`, 'error');
            }
        } catch (e) {
            errorCount++;
            log(`Bağlantı Hatası: #${inv.invoice_number}`, 'error');
        }

        currentProcessed++;
        currentBatchIndex++;
        updateProgressUI();

        // Recursively process next after a small delay to prevent browser locking and respect rate limits
        if (isSyncing && !isPaused) {
            setTimeout(processNextInInBatch, 300);
        }
    }
</script>

<style>
    .loader {
        border: 2px solid rgba(255, 255, 255, 0.1);
        border-top: 2px solid white;
        border-radius: 50%;
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

    .custom-scrollbar::-webkit-scrollbar {
        width: 6px;
    }

    .custom-scrollbar::-webkit-scrollbar-track {
        background: rgba(0, 0, 0, 0.1);
    }

    .custom-scrollbar::-webkit-scrollbar-thumb {
        background: rgba(255, 255, 255, 0.1);
        border-radius: 10px;
    }

    .custom-scrollbar::-webkit-scrollbar-thumb:hover {
        background: rgba(255, 255, 255, 0.2);
    }
</style>

<!-- Confetti -->
<script src="https://cdn.jsdelivr.net/npm/canvas-confetti@1.6.0/dist/confetti.browser.min.js"></script>

<?php include 'templates/footer.php'; ?>