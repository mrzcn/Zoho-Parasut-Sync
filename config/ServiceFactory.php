<?php
// config/ServiceFactory.php

/**
 * Service Factory Pattern
 * Manages singleton instances of service classes to prevent redundant instantiations
 */
class ServiceFactory
{
    private static $instances = [];
    private static $pdo = null;

    /**
     * Initialize factory with PDO connection
     * @param PDO $pdo Database connection
     */
    public static function init($pdo)
    {
        self::$pdo = $pdo;
    }

    /**
     * Get ParasutService singleton instance
     * @return ParasutService
     */
    public static function getParasutService(): ParasutService
    {
        if (!isset(self::$instances['parasut'])) {
            if (!self::$pdo) {
                throw new Exception('ServiceFactory not initialized. Call ServiceFactory::init($pdo) first.');
            }
            self::$instances['parasut'] = new ParasutService(self::$pdo);
        }
        return self::$instances['parasut'];
    }

    /**
     * Get ZohoService singleton instance
     * @return ZohoService
     */
    public static function getZohoService(): ZohoService
    {
        if (!isset(self::$instances['zoho'])) {
            if (!self::$pdo) {
                throw new Exception('ServiceFactory not initialized. Call ServiceFactory::init($pdo) first.');
            }
            self::$instances['zoho'] = new ZohoService(self::$pdo);
        }
        return self::$instances['zoho'];
    }

    /**
     * Get SyncService singleton instance
     * @return SyncService
     */
    public static function getSyncService(): SyncService
    {
        if (!isset(self::$instances['sync'])) {
            if (!self::$pdo) {
                throw new Exception('ServiceFactory not initialized. Call ServiceFactory::init($pdo) first.');
            }
            self::$instances['sync'] = new SyncService(self::$pdo);
        }
        return self::$instances['sync'];
    }

    /**
     * Reset all instances (useful for testing or cleanup)
     */
    public static function reset(): void
    {
        self::$instances = [];
        self::$pdo = null;
    }
}
