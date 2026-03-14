<?php
// config/database.php - MySQL Configuration

require_once __DIR__ . '/db_config.php';
// Credentials ($dbHost, etc.) are imported from db_config.php

try {
    $pdo = new PDO("mysql:host=$dbHost;dbname=$dbName;charset=utf8mb4", $dbUser, $dbPass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

} catch (PDOException $e) {
    // Throw exception instead of die() to allow proper error handling
    throw new Exception("Database Connection Error: " . $e->getMessage());
}
