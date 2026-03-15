<?php
/**
 * ParasutProductsTrait — Product operations for Paraşüt API
 * 
 * Handles product listing, sync, search, update, archive/unarchive, and stock management.
 * Required properties: $pdo, $companyId
 * Required methods: request()
 */
trait ParasutProductsTrait
{
    /**
     * Get products from Paraşüt (paginated)
     */
    public function getProducts(int $limit = 25, ?string $updatedSince = null, bool $includeArchived = false): array
    {
        $params = "?page[size]=$limit";
        if ($updatedSince) {
            $params .= "&filter[updated_at]=$updatedSince";
        }
        if ($includeArchived) {
            $params .= "&filter[archived]=true";
        }
        return $this->request('GET', "/v4/{$this->companyId}/products$params") ?? [];
    }

    /**
     * Update a product's attributes
     */
    public function updateProduct(string $id, array $attributes): array
    {
        return $this->request('PATCH', "/v4/{$this->companyId}/products/$id", [
            'data' => ['id' => $id, 'type' => 'products', 'attributes' => $attributes]
        ]);
    }

    /**
     * Archive a product
     */
    public function archiveProduct(string $id): array
    {
        return $this->request('PATCH', "/v4/{$this->companyId}/products/$id/archive");
    }

    /**
     * Unarchive a product
     */
    public function unarchiveProduct(string $id): array
    {
        return $this->request('PATCH', "/v4/{$this->companyId}/products/$id/unarchive");
    }

    /**
     * Update product stock count
     */
    public function updateStock(string $productId, $count, ?string $entryDate = null): array
    {
        $data = [
            'data' => [
                'type' => 'inventory_movements',
                'attributes' => [
                    'date' => $entryDate ?? date('Y-m-d'),
                    'direction' => 'input',
                    'quantity' => $count,
                ],
                'relationships' => [
                    'product' => ['data' => ['id' => $productId, 'type' => 'products']]
                ]
            ]
        ];
        return $this->request('POST', "/v4/{$this->companyId}/inventory_movements", $data);
    }

    /**
     * Search products by query
     */
    public function searchProducts(string $query): array
    {
        $result = $this->request('GET', "/v4/{$this->companyId}/products?filter[name]=$query");
        return $result['data'] ?? [];
    }
}
