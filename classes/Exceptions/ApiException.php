<?php
// classes/Exceptions/ApiException.php
// Custom exception hierarchy for better error handling

/**
 * Base exception for all API-related errors
 */
class ApiException extends \RuntimeException
{
    protected ?int $httpCode;
    protected ?array $apiResponse;

    public function __construct(string $message, int $httpCode = 0, ?array $apiResponse = null, ?\Throwable $previous = null)
    {
        $this->httpCode = $httpCode;
        $this->apiResponse = $apiResponse;
        parent::__construct($message, $httpCode, $previous);
    }

    public function getHttpCode(): ?int
    {
        return $this->httpCode;
    }

    public function getApiResponse(): ?array
    {
        return $this->apiResponse;
    }
}

/**
 * Zoho API errors
 */
class ZohoApiException extends ApiException {}

class ZohoAuthException extends ZohoApiException {}

class ZohoRateLimitException extends ZohoApiException
{
    public function __construct(int $retryAfterSeconds = 0, ?\Throwable $previous = null)
    {
        parent::__construct(
            "Zoho API rate limit aşıldı. $retryAfterSeconds saniye sonra tekrar deneyin.",
            429,
            null,
            $previous
        );
    }
}

class ZohoScopeMismatchException extends ZohoApiException
{
    public function __construct(?\Throwable $previous = null)
    {
        parent::__construct(
            "Zoho yetki hatası: Mevcut token bu işleme izin vermiyor. Lütfen yeni bir Authorization Code oluşturun.",
            403,
            null,
            $previous
        );
    }
}

/**
 * Parasut API errors
 */
class ParasutApiException extends ApiException {}

class ParasutAuthException extends ParasutApiException {}

class ParasutRateLimitException extends ParasutApiException
{
    public function __construct(int $retryAfterSeconds = 0, ?\Throwable $previous = null)
    {
        parent::__construct(
            "Paraşüt API rate limit aşıldı. $retryAfterSeconds saniye sonra tekrar deneyin.",
            429,
            null,
            $previous
        );
    }
}

/**
 * Connection/transport errors (cURL failures)
 */
class ConnectionException extends ApiException
{
    public function __construct(string $service, string $curlError, int $curlErrNo = 0, ?\Throwable $previous = null)
    {
        parent::__construct(
            "$service bağlantı hatası: $curlError (cURL #$curlErrNo)",
            0,
            null,
            $previous
        );
    }
}
