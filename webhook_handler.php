<?php
// webhook_handler.php
require_once __DIR__ . '/bootstrap.php';

// Get raw POST data
$payload = file_get_contents('php://input');
$headers = getallheaders(); // Used for routing only — NOT logged

// Log source IP only — do NOT log headers (may contain signature secrets)
$sourceIp = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
writeLog("Webhook received from: $sourceIp", 'INFO', 'webhook');

if (empty($payload)) {
    http_response_code(400);
    die('Empty payload');
}

$syncService = new SyncService($pdo);

// Logic for Paraşüt vs Zoho detection
// Note: Parasut sends 'Signature' header, Zoho we configure a custom header
if (isset($headers['Signature'])) {
    processParasutWebhook($payload, $headers['Signature'], $pdo, $syncService);
} elseif (isset($headers['X-Zoho-Webhook-Key'])) {
    processZohoWebhook($payload, $headers['X-Zoho-Webhook-Key'], $pdo, $syncService);
} else {
    writeLog("Unknown Webhook Source.");
    http_response_code(400);
}

function processParasutWebhook($payload, $signature, $pdo, $syncService)
{
    $secret = getSetting($pdo, 'parasut_webhook_secret');
    if (!$secret) {
        writeLog("Parasut Webhook Secret missing in settings.");
        die();
    }

    $expectedSignature = hash_hmac('sha256', $payload, $secret);
    if ($signature !== $expectedSignature) {
        writeLog("Parasut Webhook INVALID SIGNATURE.");
        http_response_code(401);
        die();
    }

    $data = json_decode($payload, true);
    $resource = $data['resource'] ?? '';
    $action = $data['action'] ?? '';
    $remoteId = $data['data']['id'] ?? '';

    writeLog("Parasut Webhook: $resource $action (ID: $remoteId)");

    if ($syncService->isLocked($resource, $remoteId, 'parasut')) {
        writeLog("Parasut Webhook ignored: Loop prevention lock active for $resource $remoteId");
        return;
    }

    try {
        $syncService->handleParasutWebhook($data);
    } catch (Exception $e) {
        writeLog("Parasut Webhook processing failed: " . $e->getMessage() . " — Pushing to Queue for retry.", 'ERROR', 'webhook');
        Queue::push($pdo, 'retry_webhook', [
            'source' => 'parasut',
            'payload' => $data,
            'error' => $e->getMessage()
        ]);
    }
}

function processZohoWebhook($payload, $key, $pdo, $syncService)
{
    $expectedKey = getSetting($pdo, 'zoho_webhook_key');
    if ($key !== $expectedKey) {
        writeLog("Zoho Webhook INVALID KEY.");
        http_response_code(401);
        die();
    }

    $data = json_decode($payload, true);
    // Zoho body depends on how we map it in the Zoho Workflow configuration
    $module = $data['module'] ?? '';
    $remoteId = $data['id'] ?? '';

    writeLog("Zoho Webhook: $module update (ID: $remoteId)");

    if ($syncService->isLocked($module, $remoteId, 'zoho')) {
        writeLog("Zoho Webhook ignored: Loop prevention lock active for $module $remoteId");
        return;
    }

    try {
        $syncService->handleZohoWebhook($data);
    } catch (Exception $e) {
        writeLog("Zoho Webhook processing failed: " . $e->getMessage() . " — Pushing to Queue for retry.", 'ERROR', 'webhook');
        Queue::push($pdo, 'retry_webhook', [
            'source' => 'zoho',
            'payload' => $data,
            'error' => $e->getMessage()
        ]);
    }
}
