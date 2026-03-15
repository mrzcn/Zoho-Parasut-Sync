<?php
/**
 * ZohoProductsTrait — Product CRUD operations for Zoho CRM
 * 
 * Handles product search, creation, updates, bulk operations, tax management,
 * and in-memory caching for performance within a single request.
 * 
 * Required properties: $pdo, $productCache, $productNameCache
 * Required methods: request(), getAccessToken()
 */
trait ZohoProductsTrait
{
    /**
     * Update a product's fields in Zoho CRM
     * @return array API response
     */
    public function updateProduct(string $id, array $data): array
    {
        return $this->request('PUT', "/Products", ['data' => [$data + ['id' => $id]]]);
    }

    /**
     * Create a new product in Zoho CRM
     * @return array API response
     */
    public function createProduct(array $productData): array
    {
        return $this->request('POST', '/Products', ['data' => [$productData]]);
    }

    /**
     * Search product by code (with in-memory cache)
     * @return array|null Product data or null
     */
    public function searchProduct(string $code): ?array
    {
        if (isset($this->productCache[$code])) {
            return $this->productCache[$code];
        }

        $result = $this->request('GET', '/Products/search', [], ['criteria' => "(Product_Code:equals:$code)"]);

        if (isset($result['data'][0])) {
            $this->productCache[$code] = $result['data'][0];
            return $result['data'][0];
        }

        return null;
    }

    /**
     * Search product by name (with in-memory cache)
     * @return array|null Product data or null
     */
    public function searchProductByName(string $name): ?array
    {
        if (isset($this->productNameCache[$name])) {
            return $this->productNameCache[$name];
        }

        $result = $this->request('GET', '/Products/search', [], ['criteria' => "(Product_Name:equals:$name)"]);

        if (isset($result['data'][0])) {
            $this->productNameCache[$name] = $result['data'][0];
            return $result['data'][0];
        }

        return null;
    }

    /**
     * Full-text search across products
     * @return array List of matching products
     */
    public function searchProducts(string $query): array
    {
        $result = $this->request('GET', '/Products/search', [], ['word' => $query]);
        return $result['data'] ?? [];
    }

    /**
     * Get a single product by ID
     */
    public function getProduct(string $id): ?array
    {
        $result = $this->request('GET', "/Products/$id");
        return $result['data'][0] ?? null;
    }

    /**
     * Get a page of products
     */
    public function getProductsPage(int $page = 1, int $perPage = 200, ?string $modifiedSince = null): array
    {
        $params = ['page' => $page, 'per_page' => $perPage];
        $result = $this->request('GET', '/Products', [], $params);
        return $result ?? [];
    }

    /**
     * Get all products (paginated fetch)
     * @return array All products
     */
    public function getAllProducts(): array
    {
        $all = [];
        $page = 1;
        do {
            $response = $this->getProductsPage($page, 200);
            $products = $response['data'] ?? [];
            $all = array_merge($all, $products);
            $hasMore = $response['info']['more_records'] ?? false;
            $page++;
        } while ($hasMore && $page <= 100);

        return $all;
    }

    /**
     * Get product IDs for bulk operations
     */
    public function getProductIds(int $limit = 50): array
    {
        $result = $this->request('GET', '/Products', [], ['fields' => 'id', 'per_page' => $limit]);
        return array_column($result['data'] ?? [], 'id');
    }

    /**
     * Mass delete products by IDs
     */
    public function massDeleteProducts(array $ids): array
    {
        return $this->request('DELETE', '/Products', [], ['ids' => implode(',', $ids)]);
    }

    /**
     * Get organization tax names
     * @return array<string> Tax name list
     */
    public function getOrgTaxNames(): array
    {
        $result = $this->request('GET', '/org/taxes');
        return array_column($result['taxes'] ?? [], 'name');
    }

    /**
     * Get all tax configurations
     */
    public function getTaxes(): ?array
    {
        return $this->request('GET', '/org/taxes');
    }
}
