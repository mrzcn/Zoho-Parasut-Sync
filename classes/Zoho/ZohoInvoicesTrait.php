<?php
/**
 * ZohoInvoicesTrait — Invoice & Purchase Order operations for Zoho CRM
 * 
 * Handles invoice CRUD, line item management, purchase orders,
 * vendor management, and related record searches.
 * 
 * Required properties: $pdo
 * Required methods: request()
 */
trait ZohoInvoicesTrait
{
    /**
     * Create a new invoice (Sales_Orders module)
     */
    public function createInvoice(string $subject, string $accountId, array $lineItems, string $currency = 'TRY', array $options = []): array
    {
        $data = array_merge([
            'Subject'      => $subject,
            'Account_Name' => ['id' => $accountId],
            'Currency'     => $currency,
            'Product_Details' => $lineItems,
        ], $options);

        return $this->request('POST', '/Sales_Orders', ['data' => [$data]]);
    }

    /**
     * Get invoices (paginated)
     */
    public function getInvoices(int $page = 1, int $perPage = 200, ?string $pageToken = null): array
    {
        $params = ['page' => $page, 'per_page' => $perPage];
        if ($pageToken) $params['page_token'] = $pageToken;
        return $this->request('GET', '/Sales_Orders', [], $params) ?? [];
    }

    /**
     * Get a single invoice by ID
     */
    public function getInvoice(string $invoiceId): ?array
    {
        $result = $this->request('GET', "/Sales_Orders/$invoiceId");
        return $result['data'][0] ?? null;
    }

    /**
     * Get invoice with line items
     */
    public function getInvoiceWithLineItems(string $invoiceId): ?array
    {
        return $this->getInvoice($invoiceId);
    }

    /**
     * Update invoice line items
     */
    public function updateInvoice(string $invoiceId, array $lineItems): array
    {
        return $this->request('PUT', '/Sales_Orders', [
            'data' => [['id' => $invoiceId, 'Product_Details' => $lineItems]]
        ]);
    }

    /**
     * Update invoice status
     */
    public function updateInvoiceStatus(string $invoiceId, string $status): array
    {
        return $this->request('PUT', '/Sales_Orders', [
            'data' => [['id' => $invoiceId, 'Status' => $status]]
        ]);
    }

    /**
     * Update arbitrary invoice fields
     */
    public function updateInvoiceFields(string $invoiceId, array $fields): array
    {
        return $this->request('PUT', '/Sales_Orders', [
            'data' => [array_merge(['id' => $invoiceId], $fields)]
        ]);
    }

    /**
     * Search invoice by invoice number
     */
    public function searchInvoiceByNumber(string $invoiceNumber): ?array
    {
        $result = $this->request('GET', '/Sales_Orders/search', [], [
            'criteria' => "(SO_Number:equals:$invoiceNumber)"
        ]);
        return $result['data'][0] ?? null;
    }

    /**
     * Get invoice IDs for bulk operations
     */
    public function getInvoiceIds(int $limit = 50): array
    {
        $result = $this->request('GET', '/Sales_Orders', [], ['fields' => 'id', 'per_page' => $limit]);
        return array_column($result['data'] ?? [], 'id');
    }

    /**
     * Mass delete invoices
     */
    public function massDeleteInvoices(array $ids): array
    {
        return $this->request('DELETE', '/Sales_Orders', [], ['ids' => implode(',', $ids)]);
    }

    /**
     * Bulk delete invoices (alias)
     */
    public function bulkDeleteInvoices(array $ids): array
    {
        return $this->massDeleteInvoices($ids);
    }

    /**
     * Get invoices containing a specific product
     */
    public function getInvoicesByProduct(string $productId): array
    {
        $result = $this->request('GET', '/Sales_Orders/search', [], [
            'criteria' => "(Product_Details.product.id:equals:$productId)"
        ]);
        return $result['data'] ?? [];
    }

    /**
     * Replace a product in an invoice's line items
     */
    public function updateInvoiceLineItemProduct(string $invoiceId, string $oldProductId, string $newProductId): array
    {
        $invoice = $this->getInvoice($invoiceId);
        if (!$invoice || empty($invoice['Product_Details'])) {
            throw new ZohoApiException("Fatura bulunamadı veya satır öğeleri boş: $invoiceId");
        }

        $updatedItems = [];
        foreach ($invoice['Product_Details'] as $item) {
            if (($item['product']['id'] ?? '') === $oldProductId) {
                $item['product']['id'] = $newProductId;
            }
            $updatedItems[] = $item;
        }

        return $this->updateInvoice($invoiceId, $updatedItems);
    }

    /**
     * Add a note to a record
     */
    public function addNote(string $module, string $recordId, string $noteContent): array
    {
        return $this->request('POST', "/$module/$recordId/Notes", [
            'data' => [['Note_Content' => $noteContent]]
        ]);
    }

    // --- Purchase Orders ---

    /**
     * Get purchase orders (paginated)
     */
    public function getPurchaseOrders(int $page = 1, int $perPage = 200, ?string $pageToken = null): array
    {
        $params = ['page' => $page, 'per_page' => $perPage];
        if ($pageToken) $params['page_token'] = $pageToken;
        return $this->request('GET', '/Purchase_Orders', [], $params) ?? [];
    }

    /**
     * Create a purchase order
     */
    public function createPurchaseOrder(string $subject, string $vendorId, array $lineItems, string $currency = 'TRY', array $options = []): array
    {
        $data = array_merge([
            'Subject'      => $subject,
            'Vendor_Name'  => ['id' => $vendorId],
            'Currency'     => $currency,
            'Product_Details' => $lineItems,
        ], $options);

        return $this->request('POST', '/Purchase_Orders', ['data' => [$data]]);
    }

    /**
     * Search purchase order by number
     */
    public function searchPurchaseOrderByNumber(string $poNumber): ?array
    {
        $result = $this->request('GET', '/Purchase_Orders/search', [], [
            'criteria' => "(PO_Number:equals:$poNumber)"
        ]);
        return $result['data'][0] ?? null;
    }

    /**
     * Get purchase orders containing a specific product
     */
    public function getPurchaseOrdersByProduct(string $productId): array
    {
        $result = $this->request('GET', '/Purchase_Orders/search', [], [
            'criteria' => "(Product_Details.product.id:equals:$productId)"
        ]);
        return $result['data'] ?? [];
    }

    /**
     * Replace a product in a PO's line items
     */
    public function updatePOLineItemProduct(string $poId, string $oldProductId, string $newProductId): array
    {
        $po = $this->request('GET', "/Purchase_Orders/$poId");
        $poData = $po['data'][0] ?? null;
        if (!$poData || empty($poData['Product_Details'])) {
            throw new ZohoApiException("Satın alma siparişi bulunamadı: $poId");
        }

        $updatedItems = [];
        foreach ($poData['Product_Details'] as $item) {
            if (($item['product']['id'] ?? '') === $oldProductId) {
                $item['product']['id'] = $newProductId;
            }
            $updatedItems[] = $item;
        }

        return $this->request('PUT', '/Purchase_Orders', [
            'data' => [['id' => $poId, 'Product_Details' => $updatedItems]]
        ]);
    }

    // --- Vendors ---

    /**
     * Search for a vendor by name
     */
    public function searchVendor(string $name): ?array
    {
        $result = $this->request('GET', '/Vendors/search', [], ['criteria' => "(Vendor_Name:equals:$name)"]);
        return $result['data'][0] ?? null;
    }

    /**
     * Create a new vendor
     */
    public function createVendor(string $name, ?string $email = null, ?string $phone = null): array
    {
        $data = ['Vendor_Name' => $name];
        if ($email) $data['Email'] = $email;
        if ($phone) $data['Phone'] = $phone;

        return $this->request('POST', '/Vendors', ['data' => [$data]]);
    }

    // --- Generic Record Operations ---

    /**
     * Get record IDs from any module
     */
    public function getRecordIds(string $module, int $limit = 50): array
    {
        $result = $this->request('GET', "/$module", [], ['fields' => 'id', 'per_page' => $limit]);
        return array_column($result['data'] ?? [], 'id');
    }

    /**
     * Mass delete records from any module
     */
    public function massDeleteRecords(string $module, array $ids): array
    {
        return $this->request('DELETE', "/$module", [], ['ids' => implode(',', $ids)]);
    }

    /**
     * Get records pending approval
     */
    public function getRecordsForApproval(string $module, int $limit = 10): array
    {
        $result = $this->request('GET', "/$module/actions/approvals", [], ['per_page' => $limit]);
        return $result['data'] ?? [];
    }
}
