/**
 * common.js — Shared utility functions for Nolto.sync
 * Loaded in header.php after axios/tailwind CDN
 * Eliminates inline JS duplication across pages
 */

// ===== HTML Escape (XSS protection) =====
function escapeHtml(text) {
    if (!text) return '';
    const map = { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;' };
    return String(text).replace(/[&<>"']/g, m => map[m]);
}

// ===== CSRF Token (already set in header.php, this is a fallback) =====
const CSRF_TOKEN = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';

// ===== API Helper =====
function api(action, data = {}) {
    const formData = new FormData();
    formData.append('action', action);
    formData.append('csrf_token', CSRF_TOKEN);
    for (const [k, v] of Object.entries(data)) {
        formData.append(k, v);
    }
    return axios.post('api_handler.php', formData).then(r => r.data);
}

// ===== Pagination Renderer =====
function renderPagination(containerId, current, total, loadFn) {
    const container = document.getElementById(containerId);
    if (!container) return;
    if (total <= 1) { container.innerHTML = ''; return; }

    let html = '';
    if (current > 1) {
        html += `<button onclick="${loadFn}(${current - 1})" class="min-w-[40px] px-3 py-2 rounded-lg bg-slate-700 hover:bg-slate-600 text-white text-sm">←</button>`;
    }

    let startPage = Math.max(1, current - 2);
    let endPage = Math.min(total, current + 2);

    if (startPage > 1) {
        html += `<button onclick="${loadFn}(1)" class="min-w-[40px] px-3 py-2 rounded-lg bg-slate-700 hover:bg-slate-600 text-white text-sm">1</button>`;
        if (startPage > 2) html += `<span class="text-slate-500">...</span>`;
    }

    for (let i = startPage; i <= endPage; i++) {
        if (i === current) {
            html += `<button class="min-w-[40px] px-3 py-2 rounded-lg bg-violet-600 text-white text-sm font-bold">${i}</button>`;
        } else {
            html += `<button onclick="${loadFn}(${i})" class="min-w-[40px] px-3 py-2 rounded-lg bg-slate-700 hover:bg-slate-600 text-white text-sm">${i}</button>`;
        }
    }

    if (endPage < total) {
        if (endPage < total - 1) html += `<span class="text-slate-500">...</span>`;
        html += `<button onclick="${loadFn}(${total})" class="min-w-[40px] px-3 py-2 rounded-lg bg-slate-700 hover:bg-slate-600 text-white text-sm">${total}</button>`;
    }

    if (current < total) {
        html += `<button onclick="${loadFn}(${current + 1})" class="min-w-[40px] px-3 py-2 rounded-lg bg-slate-700 hover:bg-slate-600 text-white text-sm">→</button>`;
    }

    container.innerHTML = html;
}

// ===== Debounced Search Helper =====
function createSearchHandler(callback, delay = 500) {
    let timer;
    return function () {
        clearTimeout(timer);
        timer = setTimeout(callback, delay);
    };
}

// ===== Confirm Action + SweetAlert =====
async function confirmAction(title, text, action) {
    const result = await Swal.fire({
        title: title,
        text: text,
        icon: 'warning',
        showCancelButton: true,
        confirmButtonText: 'Evet, Devam Et',
        cancelButtonText: 'İptal',
        confirmButtonColor: '#6366f1',
    });
    if (result.isConfirmed && typeof action === 'function') {
        return action();
    }
    return null;
}

// ===== Format Helpers =====
function formatCurrency(value, currency = 'TRY') {
    const num = parseFloat(value) || 0;
    return num.toLocaleString('tr-TR', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) + (currency ? ' ' + currency : '');
}

function formatDate(dateStr) {
    if (!dateStr) return '—';
    return new Date(dateStr).toLocaleDateString('tr-TR');
}
