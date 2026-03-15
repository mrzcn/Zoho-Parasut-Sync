<?php
/**
 * ParasutInvoicesTrait — Invoice & E-Document operations for Paraşüt API
 * 
 * Handles sales invoices, purchase bills, contacts, e-invoices, e-archives, and PDF generation.
 * Required properties: $pdo, $companyId
 * Required methods: request()
 */
trait ParasutInvoicesTrait
{
    /**
     * Get sales invoices (paginated, with optional filters)
     */
    public function getSalesInvoices(int $limit = 75, array $filters = []): array
    {
        $params = "?page[size]=$limit";
        foreach ($filters as $key => $value) {
            $params .= "&filter[$key]=$value";
        }
        return $this->request('GET', "/v4/{$this->companyId}/sales_invoices$params") ?? [];
    }

    /**
     * Get invoice details by ID
     */
    public function getInvoiceDetails(string $id): ?array
    {
        return $this->request('GET', "/v4/{$this->companyId}/sales_invoices/$id");
    }

    /**
     * Create a sales invoice
     */
    public function createSalesInvoice(array $data): array
    {
        return $this->request('POST', "/v4/{$this->companyId}/sales_invoices", $data);
    }

    /**
     * Create a full sales invoice with contact and line items
     */
    public function createFullSalesInvoice(string $contactId, array $lineItems, array $options = []): array
    {
        $data = [
            'data' => [
                'type' => 'sales_invoices',
                'attributes' => array_merge([
                    'item_type'    => 'invoice',
                    'description'  => $options['description'] ?? '',
                    'issue_date'   => $options['issue_date'] ?? date('Y-m-d'),
                    'due_date'     => $options['due_date'] ?? date('Y-m-d', strtotime('+30 days')),
                    'currency'     => $options['currency'] ?? 'TRL',
                ], $options['attributes'] ?? []),
                'relationships' => [
                    'contact' => ['data' => ['id' => $contactId, 'type' => 'contacts']],
                    'details' => ['data' => $lineItems],
                ]
            ]
        ];
        return $this->request('POST', "/v4/{$this->companyId}/sales_invoices", $data);
    }

    /**
     * Get contacts (paginated)
     */
    public function getContacts(int $page = 1, int $limit = 100): array
    {
        return $this->request('GET', "/v4/{$this->companyId}/contacts?page[number]=$page&page[size]=$limit") ?? [];
    }

    /**
     * Create a contact
     */
    public function createContact(array $attributes): array
    {
        return $this->request('POST', "/v4/{$this->companyId}/contacts", [
            'data' => ['type' => 'contacts', 'attributes' => $attributes]
        ]);
    }

    /**
     * Search for a contact by query
     */
    public function searchContact(string $query): ?array
    {
        $result = $this->request('GET', "/v4/{$this->companyId}/contacts?filter[name]=$query");
        return $result['data'][0] ?? null;
    }

    /**
     * Find a contact by name or tax number
     */
    public function findContact(string $query, string $field = 'name'): ?array
    {
        $result = $this->request('GET', "/v4/{$this->companyId}/contacts?filter[$field]=$query");
        return $result['data'][0] ?? null;
    }

    // --- E-Invoice / E-Archive ---

    /**
     * Get e-invoice details
     */
    public function getEInvoice(string $eInvoiceId): ?array
    {
        return $this->request('GET', "/v4/{$this->companyId}/e_invoices/$eInvoiceId");
    }

    /**
     * Check e-invoice inbox for a tax number
     */
    public function checkEInvoiceInbox(string $vkn): array
    {
        return $this->request('GET', "/v4/{$this->companyId}/e_invoice_inboxes?filter[vkn]=$vkn") ?? [];
    }

    /**
     * Create an e-invoice from a sales invoice
     */
    public function createEInvoice(string $salesInvoiceId, array $options = []): array
    {
        $data = [
            'data' => [
                'type' => 'e_invoices',
                'relationships' => [
                    'invoice' => ['data' => ['id' => $salesInvoiceId, 'type' => 'sales_invoices']]
                ]
            ]
        ];
        if (!empty($options)) {
            $data['data']['attributes'] = $options;
        }
        return $this->request('POST', "/v4/{$this->companyId}/e_invoices", $data);
    }

    /**
     * Create an e-archive from a sales invoice
     */
    public function createEArchive(string $salesInvoiceId, array $options = []): array
    {
        $data = [
            'data' => [
                'type' => 'e_archives',
                'relationships' => [
                    'sales_invoice' => ['data' => ['id' => $salesInvoiceId, 'type' => 'sales_invoices']]
                ]
            ]
        ];
        if (!empty($options)) {
            $data['data']['attributes'] = $options;
        }
        return $this->request('POST', "/v4/{$this->companyId}/e_archives", $data);
    }

    /**
     * Get e-invoice PDF
     */
    public function getEInvoicePdf(string $eInvoiceId): ?array
    {
        return $this->request('GET', "/v4/{$this->companyId}/e_invoices/$eInvoiceId/pdf");
    }

    /**
     * Get e-archive PDF
     */
    public function getEArchivePdf(string $eArchiveId): ?array
    {
        return $this->request('GET', "/v4/{$this->companyId}/e_archives/$eArchiveId/pdf");
    }

    /**
     * Get trackable job status
     */
    public function getTrackableJob(string $jobId): ?array
    {
        return $this->request('GET', "/v4/{$this->companyId}/trackable_jobs/$jobId");
    }

    /**
     * Wait for a trackable job to complete
     */
    public function waitForTrackableJob(string $jobId, int $maxWaitSeconds = 120): ?array
    {
        $start = time();
        while (time() - $start < $maxWaitSeconds) {
            $result = $this->getTrackableJob($jobId);
            $status = $result['data']['attributes']['status'] ?? null;
            if ($status === 'done' || $status === 'error') {
                return $result;
            }
            sleep(3);
        }
        return null;
    }

    // --- Purchase Bills ---

    /**
     * Get purchase bills (paginated)
     */
    public function getPurchaseBills(int $limit = 75, array $filters = []): array
    {
        $params = "?page[size]=$limit";
        foreach ($filters as $key => $value) {
            $params .= "&filter[$key]=$value";
        }
        return $this->request('GET', "/v4/{$this->companyId}/purchase_bills$params") ?? [];
    }

    /**
     * Get purchase bill details
     */
    public function getPurchaseBillDetails(string $id): ?array
    {
        return $this->request('GET', "/v4/{$this->companyId}/purchase_bills/$id?include=details,details.product");
    }
}
