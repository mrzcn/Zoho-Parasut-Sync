<?php
// controllers/SyncController.php

class SyncController extends BaseController
{
    public function sync_zoho_to_parasut_stock(): void
    {
        enableLongRunningMode();
        $count = $this->sync()->syncStockZohoToParasut();
        jsonResponse(['success' => true, 'message' => "$count ürünün stoğu Paraşüt'te güncellendi.", 'count' => $count]);
    }

    public function sync_invoice_statuses(): void
    {
        enableLongRunningMode();
        $limit = (int) $this->input('limit', 100);
        $count = $this->sync()->syncInvoiceStatuses($limit);
        jsonResponse(['success' => true, 'message' => "Paraşüt'ten $count faturanın durumu Zoho'da güncellendi.", 'count' => $count]);
    }

    public function sync_zoho_to_parasut_invoices(): void
    {
        enableLongRunningMode();
        $limit = (int) $this->input('limit', 100);
        $count = $this->sync()->syncInvoicesZohoToParasut($limit);
        jsonResponse(['success' => true, 'message' => "Zoho'dan $count yeni fatura Paraşüt'e aktarıldı.", 'count' => $count]);
    }
}

