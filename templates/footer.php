</main>
</div> <!-- End Main Wrapper -->
</div> <!-- End Flex Container -->

<script>
    // Mobile Sidebar Toggle
    function openMobileSidebar() {
        document.getElementById('sidebar').classList.remove('-translate-x-full');
        document.getElementById('sidebar-overlay').classList.remove('hidden');
    }
    function closeMobileSidebar() {
        document.getElementById('sidebar').classList.add('-translate-x-full');
        document.getElementById('sidebar-overlay').classList.add('hidden');
    }
</script>

<script>

    // Premium Toast Notification Logic

    function showToast(message, type = 'success') {

        const container = document.getElementById('toast-container');

        const toast = document.createElement('div');



        const styles = {

            success: 'from-emerald-500 to-green-600',

            error: 'from-red-500 to-rose-600',

            info: 'from-violet-500 to-purple-600'

        };



        const icons = {

            success: '✓',

            error: '✕',

            info: 'ℹ'

        };



        const gradient = styles[type] || 'from-slate-600 to-slate-700';

        const icon = icons[type] || 'ℹ';



        toast.className = `bg-gradient-to-r ${gradient} text-white px-5 py-3 rounded-xl shadow-2xl flex items-center gap-3 transform transition-all duration-300 translate-x-full opacity-0`;

        toast.innerHTML = `

            <span class="flex items-center justify-center w-6 h-6 bg-white/20 rounded-full text-sm font-bold">${icon}</span>

            <span class="font-medium">${message}</span>

        `;



        container.appendChild(toast);



        // Slide in

        setTimeout(() => {

            toast.classList.remove('translate-x-full', 'opacity-0');

        }, 10);



        // Remove after 3.5 seconds

        setTimeout(() => {

            toast.classList.add('translate-x-full', 'opacity-0');

            setTimeout(() => toast.remove(), 300);

        }, 3500);

    }

    function syncZohoStockToParasut() {
        if (!confirm('Zoho stok bilgilerini Paraşüt\'e aktarmak istediğinize emin misiniz?')) return;

        Swal.fire({
            title: 'Senkronizasyon Başladı',
            text: 'Stoklar güncelleniyor, lütfen bekleyin...',
            allowOutsideClick: false,
            didOpen: () => { Swal.showLoading(); }
        });

        const formData = new FormData();
        formData.append('action', 'sync_zoho_to_parasut_stock');

        axios.post('api_handler.php', formData)
            .then(res => {
                if (res.data.success) {
                    Swal.fire('Başarılı', res.data.message, 'success').then(() => {
                        if (typeof fetchStats === 'function') fetchStats();
                        if (typeof loadParasutFromDB === 'function') loadParasutFromDB(1);
                    });
                } else {
                    Swal.fire('Hata', res.data.message, 'error');
                }
            })
            .catch(err => {
                Swal.fire('Hata', 'Sunucu hatası oluştu.', 'error');
            });
    }

    function syncZohoInvoicesToParasut() {
        if (!confirm('Yeni Zoho faturalarını Paraşüt\'e aktarmak istediğinize emin misiniz?')) return;

        Swal.fire({
            title: 'Aktarım Başladı',
            text: 'Faturalar Paraşüt\'e gönderiliyor...',
            allowOutsideClick: false,
            didOpen: () => { Swal.showLoading(); }
        });

        const formData = new FormData();
        formData.append('action', 'sync_zoho_to_parasut_invoices');
        formData.append('limit', 50);

        axios.post('api_handler.php', formData)
            .then(res => {
                if (res.data.success) {
                    Swal.fire('Başarılı', res.data.message, 'success').then(() => {
                        if (typeof fetchStats === 'function') fetchStats();
                        if (typeof fetchInvoices === 'function') fetchInvoices();
                        else if (typeof loadInvoicesFromDB === 'function') loadInvoicesFromDB();
                    });
                } else {
                    Swal.fire('Hata', res.data.message, 'error');
                }
            })
            .catch(err => {
                Swal.fire('Hata', 'Sunucu hatası oluştu.', 'error');
            });
    }
</script>

</body>

</html>