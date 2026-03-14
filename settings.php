<?php
require_once __DIR__ . '/bootstrap.php';

// Fetch current settings via key-value helper
$settings = [];
foreach (getAllowedSettingsKeys() as $key) {
    $settings[$key] = getSetting($pdo, $key);
}

$pageTitle = 'Ayarlar';
include 'templates/header.php';
?>

<div class="glass-card rounded-2xl overflow-hidden">
    <div class="p-8">
        <h3 class="text-2xl font-bold gradient-text mb-2">API Ayarları</h3>
        <p class="text-slate-400">
            Paraşüt ve Zoho entegrasyonu için gerekli kimlik bilgilerini giriniz.
        </p>

        <form id="settingsForm" class="mt-8 space-y-8">
            <input type="hidden" name="action" value="save_settings">

            <!-- Paraşüt Settings -->
            <div class="glass rounded-xl p-6">
                <h4 class="text-lg font-semibold text-violet-400 mb-6 flex items-center gap-2">
                    <span class="w-8 h-8 bg-violet-500/20 rounded-lg flex items-center justify-center">📋</span>
                    Paraşüt Ayarları
                </h4>
                <div class="grid grid-cols-1 gap-6 sm:grid-cols-6">
                    <div class="sm:col-span-3">
                        <label class="block text-sm font-medium text-slate-300 mb-2">Client ID</label>
                        <input type="text" name="parasut_client_id"
                            value="<?= sanitize($settings['parasut_client_id'] ?? '') ?>"
                            class="input-premium w-full rounded-xl py-3 px-4">
                    </div>
                    <div class="sm:col-span-3">
                        <label class="block text-sm font-medium text-slate-300 mb-2">Client Secret</label>
                        <input type="password" name="parasut_client_secret"
                            value="<?= sanitize($settings['parasut_client_secret'] ?? '') ?>"
                            class="input-premium w-full rounded-xl py-3 px-4" autocomplete="off">
                    </div>
                    <div class="sm:col-span-2">
                        <label class="block text-sm font-medium text-slate-300 mb-2">Username</label>
                        <input type="text" name="parasut_username"
                            value="<?= sanitize($settings['parasut_username'] ?? '') ?>"
                            class="input-premium w-full rounded-xl py-3 px-4">
                    </div>
                    <div class="sm:col-span-2">
                        <label class="block text-sm font-medium text-slate-300 mb-2">Password</label>
                        <input type="password" name="parasut_password"
                            value="<?= sanitize($settings['parasut_password'] ?? '') ?>"
                            class="input-premium w-full rounded-xl py-3 px-4" autocomplete="off">
                    </div>
                    <div class="sm:col-span-2">
                        <label class="block text-sm font-medium text-slate-300 mb-2">Company ID</label>
                        <input type="text" name="parasut_company_id"
                            value="<?= sanitize($settings['parasut_company_id'] ?? '') ?>"
                            class="input-premium w-full rounded-xl py-3 px-4">
                    </div>
                </div>
                <div class="mt-6">
                    <button type="button" onclick="testConnection('parasut')"
                        class="btn-outline text-slate-300 px-5 py-2.5 rounded-xl font-medium text-sm">
                        🔌 Bağlantıyı Test Et
                    </button>
                </div>
            </div>

            <!-- Zoho Settings -->
            <div class="glass rounded-xl p-6">
                <h4 class="text-lg font-semibold text-cyan-400 mb-6 flex items-center gap-2">
                    <span class="w-8 h-8 bg-cyan-500/20 rounded-lg flex items-center justify-center">🌐</span>
                    Zoho Ayarları
                </h4>

                <!-- Connection Status -->
                <div
                    class="mb-6 p-4 rounded-lg <?= !empty($settings['zoho_refresh_token']) ? 'bg-emerald-500/10 border border-emerald-500/30' : 'bg-amber-500/10 border border-amber-500/30' ?>">
                    <div class="flex items-center gap-3">
                        <?php if (!empty($settings['zoho_refresh_token'])): ?>
                            <span class="text-2xl">✅</span>
                            <div>
                                <p class="text-emerald-400 font-medium">Zoho Bağlantısı Aktif</p>
                                <p class="text-xs text-slate-400">Refresh token mevcut. Senkronizasyon yapılabilir.</p>
                            </div>
                        <?php else: ?>
                            <span class="text-2xl">⚠️</span>
                            <div>
                                <p class="text-amber-400 font-medium">Zoho Bağlantısı Kurulmamış</p>
                                <p class="text-xs text-slate-400">Aşağıdaki bilgileri doldurup bağlantıyı kurun.</p>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="grid grid-cols-1 gap-6 sm:grid-cols-6">
                    <div class="sm:col-span-2">
                        <label class="block text-sm font-medium text-slate-300 mb-2">Veri Merkezi (Data Center)</label>
                        <select name="zoho_tld" class="input-premium w-full rounded-xl py-3 px-4">
                            <option value="com" <?= ($settings['zoho_tld'] ?? 'com') == 'com' ? 'selected' : '' ?>>zoho.com
                                (Global/US)</option>
                            <option value="eu" <?= ($settings['zoho_tld'] ?? 'com') == 'eu' ? 'selected' : '' ?>>zoho.eu
                                (Europe)</option>
                            <option value="com.tr" <?= ($settings['zoho_tld'] ?? 'com') == 'com.tr' ? 'selected' : '' ?>>
                                zoho.com.tr (Turkey)</option>
                            <option value="in" <?= ($settings['zoho_tld'] ?? 'com') == 'in' ? 'selected' : '' ?>>zoho.in
                                (India)</option>
                            <option value="com.au" <?= ($settings['zoho_tld'] ?? 'com') == 'com.au' ? 'selected' : '' ?>>
                                zoho.com.au (Australia)</option>
                        </select>
                    </div>
                    <div class="sm:col-span-2">
                        <label class="block text-sm font-medium text-slate-300 mb-2">Client ID</label>
                        <input type="text" name="zoho_client_id"
                            value="<?= sanitize($settings['zoho_client_id'] ?? '') ?>"
                            class="input-premium w-full rounded-xl py-3 px-4">
                    </div>
                    <div class="sm:col-span-2">
                        <label class="block text-sm font-medium text-slate-300 mb-2">Client Secret</label>
                        <input type="password" name="zoho_client_secret"
                            value="<?= sanitize($settings['zoho_client_secret'] ?? '') ?>"
                            class="input-premium w-full rounded-xl py-3 px-4" autocomplete="off">
                    </div>
                    <div class="sm:col-span-2">
                        <label class="block text-sm font-medium text-slate-300 mb-2">Organization ID</label>
                        <input type="text" name="zoho_organization_id"
                            value="<?= sanitize($settings['zoho_organization_id'] ?? '') ?>"
                            class="input-premium w-full rounded-xl py-3 px-4">
                    </div>
                </div>

                <!-- Authorization Code Section (Setup Wizard) -->
                <div class="mt-6 p-4 bg-slate-800/50 rounded-xl border border-slate-700">
                    <h5 class="text-sm font-semibold text-violet-400 mb-3">🔗 Bağlantı Kurulumu</h5>
                    <p class="text-xs text-slate-400 mb-4">
                        <a href="https://api-console.zoho.com/" target="_blank"
                            class="text-violet-400 hover:text-violet-300 transition-colors underline">Zoho API
                            Console</a>'dan "Self Client" oluşturun.
                        Scope: <code
                            class="bg-slate-700/50 px-2 py-0.5 rounded text-cyan-400">ZohoCRM.modules.ALL,ZohoCRM.settings.ALL</code>.
                        Oluşturulan kodu aşağıya yapıştırın.
                    </p>
                    <div class="flex gap-3">
                        <input type="text" id="zoho_auth_code" placeholder="Authorization Code yapıştırın..."
                            class="input-premium flex-1 rounded-xl py-3 px-4">
                        <button type="button" onclick="exchangeZohoCode()"
                            class="btn-primary px-5 py-3 rounded-xl font-medium text-sm whitespace-nowrap">
                            🔗 Bağlantıyı Kur
                        </button>
                    </div>
                </div>

                <div class="mt-6">
                    <button type="button" onclick="testConnection('zoho')"
                        class="btn-outline text-slate-300 px-5 py-2.5 rounded-xl font-medium text-sm">
                        🔌 Bağlantıyı Test Et
                    </button>
                </div>
            </div>

            <!-- Webhooks & Security -->
            <div class="glass rounded-xl p-6">
                <h4 class="text-lg font-semibold text-pink-400 mb-6 flex items-center gap-2">
                    <span class="w-8 h-8 bg-pink-500/20 rounded-lg flex items-center justify-center">🛡️</span>
                    Webhook & Güvenlik Ayarları
                </h4>
                <div class="grid grid-cols-1 gap-6 sm:grid-cols-6">
                    <div class="sm:col-span-3">
                        <label class="block text-sm font-medium text-slate-300 mb-2">Paraşüt Webhook Secret</label>
                        <input type="text" name="parasut_webhook_secret"
                            value="<?= sanitize($settings['parasut_webhook_secret'] ?? '') ?>"
                            placeholder="HMAC-SHA256 Secret Key" class="input-premium w-full rounded-xl py-3 px-4">
                        <p class="text-[10px] text-slate-500 mt-1">Paraşüt Webhook ayarlarında imza doğrulaması için
                            kullanılır.</p>
                    </div>
                    <div class="sm:col-span-3">
                        <label class="block text-sm font-medium text-slate-300 mb-2">Zoho Webhook Key</label>
                        <input type="text" name="zoho_webhook_key"
                            value="<?= sanitize($settings['zoho_webhook_key'] ?? '') ?>"
                            placeholder="X-Zoho-Webhook-Key Header Value"
                            class="input-premium w-full rounded-xl py-3 px-4">
                        <p class="text-[10px] text-slate-500 mt-1">Zoho Workflow Webhook başlığında doğrulanacak
                            anahtar.</p>
                    </div>
                    <div class="sm:col-span-3">
                        <label class="block text-sm font-medium text-slate-300 mb-2">Cloudflare Turnstile Site
                            Key</label>
                        <input type="text" name="turnstile_site_key"
                            value="<?= sanitize($settings['turnstile_site_key'] ?? '') ?>"
                            class="input-premium w-full rounded-xl py-3 px-4">
                    </div>
                    <div class="sm:col-span-3">
                        <label class="block text-sm font-medium text-slate-300 mb-2">Cloudflare Turnstile Secret
                            Key</label>
                        <input type="password" name="turnstile_secret_key"
                            value="<?= sanitize($settings['turnstile_secret_key'] ?? '') ?>"
                            class="input-premium w-full rounded-xl py-3 px-4">
                    </div>
                </div>
                <div class="mt-4 p-4 bg-pink-500/5 rounded-lg border border-pink-500/20">
                    <p class="text-xs text-slate-400">
                        <strong>Not:</strong> Webhook uç noktası (URL): <code
                            class="text-pink-400"><?= (isset($_SERVER['HTTPS']) ? "https" : "http") . "://" . $_SERVER['HTTP_HOST'] . dirname($_SERVER['PHP_SELF']) ?>/webhook_handler.php</code>
                    </p>
                </div>
            </div>

            <div class="flex justify-end pt-4">
                <button type="submit" class="btn-primary text-white px-8 py-3 rounded-xl font-semibold text-sm">
                    💾 Ayarları Kaydet
                </button>
            </div>
        </form>
    </div>
</div>

<script>
    document.getElementById('settingsForm').addEventListener('submit', function (e) {
        e.preventDefault();
        const formData = new FormData(this);

        axios.post('api_handler.php', formData)
            .then(response => {
                if (response.data.success) {
                    showToast(response.data.message, 'success');
                } else {
                    showToast(response.data.message, 'error');
                }
            })
            .catch(error => {
                showToast('Bir hata oluştu: ' + error.message, 'error');
            });
    });

    function testConnection(service) {
        showToast('Bağlantı test ediliyor...', 'info');

        const formData = new FormData(document.getElementById('settingsForm'));

        // Auto-save first
        axios.post('api_handler.php', formData)
            .then(() => {
                // Then test
                const testData = new FormData();
                testData.append('action', 'test_' + service);

                return axios.post('api_handler.php', testData);
            })
            .then(response => {
                if (response.data.success) {
                    showToast(response.data.message, 'success');
                } else {
                    showToast('Bağlantı Hatası: ' + response.data.message, 'error');
                }
            })
            .catch(error => {
                console.error(error);
                showToast('İstek hatası: ' + (error.response?.data?.message || error.message), 'error');
            });
    }

    function exchangeZohoCode() {
        const authCode = document.getElementById('zoho_auth_code').value.trim();
        if (!authCode) {
            showToast('Lütfen Authorization Code girin.', 'error');
            return;
        }

        showToast('Zoho bağlantısı kuruluyor...', 'info');

        // First save settings (Client ID, Secret, TLD)
        const formData = new FormData(document.getElementById('settingsForm'));

        axios.post('api_handler.php', formData)
            .then(() => {
                // Then exchange the code
                const exchangeData = new FormData();
                exchangeData.append('action', 'exchange_zoho_code');
                exchangeData.append('authorization_code', authCode);

                return axios.post('api_handler.php', exchangeData);
            })
            .then(response => {
                if (response.data.success) {
                    showToast(response.data.message, 'success');
                    // Clear the input and reload to show updated status
                    document.getElementById('zoho_auth_code').value = '';
                    setTimeout(() => location.reload(), 1500);
                } else {
                    showToast('Hata: ' + response.data.message, 'error');
                }
            })
            .catch(error => {
                console.error(error);
                showToast('İstek hatası: ' + (error.response?.data?.message || error.message), 'error');
            });
    }
</script>

<?php include 'templates/footer.php'; ?>