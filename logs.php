<?php
$pageTitle = 'Loglar';
include 'templates/header.php';
?>

<div class="mb-8 flex justify-between items-center">
    <div>
        <h1 class="text-3xl font-bold gradient-text mb-2">Canlı Log Takibi</h1>
        <p class="text-slate-400">Sistem ve API işlemlerini anlık olarak izleyin.</p>
    </div>
    <div class="flex gap-3">
        <button id="clear-logs"
            class="bg-red-500/10 hover:bg-red-500/20 text-red-500 px-4 py-2 rounded-xl transition-colors text-sm font-medium flex items-center gap-2">
            <span>🗑️</span> Logları Temizle
        </button>
        <button id="toggle-refresh"
            class="bg-emerald-500/10 hover:bg-emerald-500/20 text-emerald-500 px-4 py-2 rounded-xl transition-colors text-sm font-medium flex items-center gap-2">
            <span class="pulse-icon"></span> Canlı İzleme: Açık
        </button>
    </div>
</div>

<div class="glass-card rounded-2xl overflow-hidden flex flex-col" style="height: calc(100vh - 250px);">
    <div class="bg-slate-900/50 p-3 border-b border-slate-700/50 flex justify-between items-center">
        <div class="flex gap-2">
            <div class="w-3 h-3 rounded-full bg-red-500"></div>
            <div class="w-3 h-3 rounded-full bg-yellow-500"></div>
            <div class="w-3 h-3 rounded-full bg-green-500"></div>
        </div>
        <div class="text-xs text-slate-500 font-mono">debug_log.txt</div>
    </div>
    <div id="log-container"
        class="flex-1 p-4 font-mono text-sm overflow-y-auto bg-slate-950/50 text-slate-300 space-y-1">
        <div class="text-slate-500 italic">Loglar yükleniyor...</div>
    </div>
</div>

<style>
    #log-container::-webkit-scrollbar {
        width: 8px;
    }

    #log-container::-webkit-scrollbar-track {
        background: rgba(15, 23, 42, 0.1);
    }

    #log-container::-webkit-scrollbar-thumb {
        background: rgba(51, 65, 85, 0.5);
        border-radius: 4px;
    }

    #log-container::-webkit-scrollbar-thumb:hover {
        background: rgba(71, 85, 105, 0.5);
    }

    .log-line {
        border-left: 2px solid transparent;
        padding-left: 10px;
        white-space: pre-wrap;
        word-break: break-all;
    }

    .log-line:hover {
        background: rgba(255, 255, 255, 0.03);
    }

    .log-time {
        color: #64748b;
        font-size: 0.85em;
    }

    .log-error {
        color: #f87171;
        border-left-color: #ef4444;
        background: rgba(239, 68, 68, 0.05);
    }

    .log-warning {
        color: #fbbf24;
        border-left-color: #f59e0b;
    }

    .log-success {
        color: #34d399;
    }

    .log-info {
        color: #60a5fa;
    }

    .pulse-icon {
        width: 8px;
        height: 8px;
        background-color: #10b981;
        border-radius: 50%;
        display: inline-block;
        box-shadow: 0 0 0 rgba(16, 185, 129, 0.4);
        animation: pulse 2s infinite;
    }

    @keyframes pulse {
        0% {
            box-shadow: 0 0 0 0 rgba(16, 185, 129, 0.4);
        }

        70% {
            box-shadow: 0 0 0 10px rgba(16, 185, 129, 0);
        }

        100% {
            box-shadow: 0 0 0 0 rgba(16, 185, 129, 0);
        }
    }

    .status-off .pulse-icon {
        background-color: #64748b;
        animation: none;
    }
</style>

<script>
    let isAutoRefresh = true;
    let refreshInterval;
    const container = document.getElementById('log-container');
    const toggleBtn = document.getElementById('toggle-refresh');
    const clearBtn = document.getElementById('clear-logs');

    function fetchLogs() {
        const formData = new FormData();
        formData.append('action', 'get_logs');

        axios.post('api_handler.php', formData)
            .then(res => {
                if (res.data.success) {
                    const logs = res.data.data;
                    const wasAtBottom = container.scrollHeight - container.scrollTop <= container.clientHeight + 50;

                    renderLogs(logs);

                    if (wasAtBottom && isAutoRefresh) {
                        container.scrollTop = container.scrollHeight;
                    }
                }
            })
            .catch(err => console.error('Log fetch error:', err));
    }

    function renderLogs(logData) {
        // Handle both array (from database) and string (from file) formats
        if (Array.isArray(logData)) {
            // Database format: array of log objects
            if (logData.length === 0) {
                container.innerHTML = '<div class="text-slate-500 italic">Henüz log kaydı yok.</div>';
                return;
            }

            container.innerHTML = logData.map(log => {
                let typeClass = 'log-line';
                const level = (log.level || 'INFO').toUpperCase();
                const message = log.message || '';

                if (level === 'ERROR' || level === 'CRITICAL') typeClass += ' log-error';
                else if (level === 'WARNING') typeClass += ' log-warning';
                else if (message.toLowerCase().includes('success') || message.toLowerCase().includes('başarılı')) typeClass += ' log-success';
                else if (level === 'DEBUG' || message.toLowerCase().includes('fetching') || message.toLowerCase().includes('starting')) typeClass += ' log-info';

                const timestamp = log.created_at || '';
                const module = log.module ? `[${escapeHtml(log.module)}]` : '';

                return `<div class="${typeClass}"><span class="log-time">[${escapeHtml(timestamp)}]</span> <span class="text-purple-400">${module}</span> <span class="text-amber-300">[${escapeHtml(level)}]</span> ${escapeHtml(message)}</div>`;
            }).join('');
        } else {
            // File format: string content
            if (!logData || !logData.trim()) {
                container.innerHTML = '<div class="text-slate-500 italic">Log dosyası boş.</div>';
                return;
            }

            const lines = logData.split('\n');
            container.innerHTML = lines.map(line => {
                if (!line.trim()) return '';

                let typeClass = 'log-line';
                if (line.toLowerCase().includes('error') || line.toLowerCase().includes('hata')) typeClass += ' log-error';
                else if (line.toLowerCase().includes('warning') || line.toLowerCase().includes('uyarı')) typeClass += ' log-warning';
                else if (line.toLowerCase().includes('success') || line.toLowerCase().includes('başarılı')) typeClass += ' log-success';
                else if (line.toLowerCase().includes('fetching') || line.toLowerCase().includes('starting')) typeClass += ' log-info';

                // Clean up timestamps for better display
                const match = line.match(/^\[(.*?)\] (.*)/);
                if (match) {
                    return `<div class="${typeClass}"><span class="log-time">[${escapeHtml(match[1])}]</span> ${escapeHtml(match[2])}</div>`;
                }
                return `<div class="${typeClass}">${escapeHtml(line)}</div>`;
            }).join('');
        }
    }

    function toggleRefresh() {
        isAutoRefresh = !isAutoRefresh;
        if (isAutoRefresh) {
            startInterval();
            toggleBtn.innerHTML = '<span class="pulse-icon"></span> Canlı İzleme: Açık';
            toggleBtn.classList.remove('status-off');
        } else {
            clearInterval(refreshInterval);
            toggleBtn.innerHTML = '<span>⏸️</span> Canlı İzleme: Durduruldu';
            toggleBtn.classList.add('status-off');
        }
    }

    function startInterval() {
        if (refreshInterval) clearInterval(refreshInterval);
        refreshInterval = setInterval(fetchLogs, 2000);
    }

    function clearLogs() {
        if (!confirm('Tüm logları temizlemek istediğinize emin misiniz?')) return;

        const formData = new FormData();
        formData.append('action', 'clear_logs');

        axios.post('api_handler.php', formData)
            .then(res => {
                if (res.data.success) {
                    fetchLogs();
                }
            });
    }

    toggleBtn.addEventListener('click', toggleRefresh);
    clearBtn.addEventListener('click', clearLogs);

    // Initial load
    fetchLogs();
    startInterval();

    // Initial scroll to bottom
    setTimeout(() => { container.scrollTop = container.scrollHeight; }, 500);
</script>

<?php include 'templates/footer.php'; ?>