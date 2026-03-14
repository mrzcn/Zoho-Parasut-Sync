<?php
// classes/ApiClient.php
// Shared HTTP client for API requests — eliminates cURL/retry/metric duplication

abstract class ApiClient
{
    protected PDO $pdo;

    /**
     * Service name for metrics/logging ('zoho' or 'parasut')
     */
    abstract protected function getServiceName(): string;

    /**
     * Get a valid access token (refresh if needed)
     */
    abstract protected function getAccessToken(): string;

    /**
     * Perform an HTTP request with retry logic, rate limit handling, and metric logging.
     *
     * @param string $method   HTTP method (GET, POST, PUT, PATCH, DELETE)
     * @param string $url      Full URL
     * @param array  $headers  HTTP headers
     * @param array  $data     Request body (will be JSON-encoded for non-GET)
     * @param array  $retryConfig  ['maxRetries' => 3, 'connectTimeout' => 10, 'timeout' => 30]
     * @return array|null Decoded JSON response
     * @throws ApiException
     */
    protected function httpRequest(
        string $method,
        string $url,
        array $headers = [],
        array $data = [],
        array $retryConfig = []
    ): ?array {
        $maxRetries = $retryConfig['maxRetries'] ?? 3;
        $connectTimeout = $retryConfig['connectTimeout'] ?? 10;
        $timeout = $retryConfig['timeout'] ?? 30;
        $service = $this->getServiceName();

        $requestStartTime = microtime(true);
        $attempt = 0;
        $httpCode = 0;
        $response = false;

        while ($attempt <= $maxRetries) {
            $ch = curl_init($url);

            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
            curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, $connectTimeout);
            curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);

            if ($method === 'POST') {
                curl_setopt($ch, CURLOPT_POST, true);
                if (!empty($data)) {
                    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
                }
            } elseif (in_array($method, ['PUT', 'PATCH', 'DELETE'])) {
                curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
                if (!empty($data)) {
                    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
                }
            }

            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

            // cURL transport error
            if (curl_errno($ch)) {
                $curlError = curl_error($ch);
                $curlErrNo = curl_errno($ch);
                curl_close($ch);

                writeLog("$service CURL ERROR #$curlErrNo: $curlError (attempt $attempt/$maxRetries)", 'ERROR', $service);

                if ($attempt < $maxRetries) {
                    $attempt++;
                    $sleepTime = (int) pow(2, $attempt);
                    writeLog("Retrying in {$sleepTime}s...", 'WARNING', $service);
                    sleep($sleepTime);
                    continue;
                }

                $this->logApiMetric($method, $url, null, $this->elapsed($requestStartTime), true, $curlError);
                throw new ConnectionException($service, $curlError, $curlErrNo);
            }

            curl_close($ch);

            // Rate limit (429) — retry with exponential backoff
            if ($httpCode === 429 && $attempt < $maxRetries) {
                $attempt++;
                $sleepTime = $this->parseRetryAfter($response, $attempt);
                writeLog("$service RATE LIMIT (429). Waiting {$sleepTime}s (attempt $attempt/$maxRetries)", 'WARNING', $service);
                sleep($sleepTime);
                continue;
            }

            break;
        }

        // Log metrics
        $durationMs = $this->elapsed($requestStartTime);
        $isRetry = $attempt > 0;

        if ($httpCode >= 400) {
            writeLog("$service API Error ($httpCode): " . substr($response, 0, 300), 'ERROR', $service);
            $this->logApiMetric($method, $url, $httpCode, $durationMs, $isRetry, substr($response, 0, 500));
        } else {
            $this->logApiMetric($method, $url, $httpCode, $durationMs, $isRetry);
        }

        $result = json_decode($response, true);

        if ($result === null && !empty($response)) {
            writeLog("$service JSON decode error. Raw: " . substr($response, 0, 200), 'ERROR', $service);
            throw new ApiException("$service API yanıtı çözümlenemedi", $httpCode);
        }

        return $result;
    }

    /**
     * Parse rate limit response for wait time
     */
    protected function parseRetryAfter(string $response, int $attempt): int
    {
        $data = json_decode($response, true);
        if (isset($data['errors'][0]['detail']) && preg_match('/(\d+)\s+seconds/', $data['errors'][0]['detail'], $m)) {
            return (int) $m[1] + 5;
        }
        return (int) pow(2, $attempt);
    }

    /**
     * Calculate elapsed time in milliseconds
     */
    private function elapsed(float $startTime): int
    {
        return (int) ((microtime(true) - $startTime) * 1000);
    }

    /**
     * Log API metric to database (best-effort, never throws)
     */
    protected function logApiMetric(
        string $method,
        string $endpoint,
        ?int $httpCode,
        ?int $durationMs,
        bool $isRetry = false,
        ?string $error = null
    ): void {
        try {
            $stmt = $this->pdo->prepare(
                "INSERT INTO api_metrics (service, method, endpoint, http_code, duration_ms, is_retry, error_message, created_at) 
                 VALUES (?, ?, ?, ?, ?, ?, ?, NOW())"
            );
            $stmt->execute([
                $this->getServiceName(),
                $method,
                substr($endpoint, 0, 500),
                $httpCode,
                $durationMs,
                $isRetry ? 1 : 0,
                $error ? substr($error, 0, 500) : null
            ]);
        } catch (\Exception $e) {
            // Table might not exist yet — silently ignore
        }
    }
}
