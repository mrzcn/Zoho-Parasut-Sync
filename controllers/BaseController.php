<?php
// controllers/BaseController.php
// Base class for all API controllers — provides common dependencies

class BaseController
{
    protected PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    /**
     * Get a ParasutService instance
     */
    protected function parasut(): ParasutService
    {
        return ServiceFactory::getParasutService();
    }

    /**
     * Get a ZohoService instance
     */
    protected function zoho(): ZohoService
    {
        return ServiceFactory::getZohoService();
    }

    /**
     * Get a SyncService instance
     */
    protected function sync(): SyncService
    {
        return ServiceFactory::getSyncService();
    }

    /**
     * Get a POST parameter with optional default
     */
    protected function input(string $key, $default = null)
    {
        return $_POST[$key] ?? $default;
    }

    /**
     * Require a POST parameter, return 400 if missing
     */
    protected function requireInput(string $key): string
    {
        $value = $_POST[$key] ?? '';
        if ($value === '') {
            jsonResponse(['success' => false, 'message' => "Eksik parametre: $key"], 400);
        }
        return $value;
    }

    /**
     * Get a POST parameter as integer with default
     */
    protected function inputInt(string $key, int $default = 0): int
    {
        return isset($_POST[$key]) ? (int) $_POST[$key] : $default;
    }
}
