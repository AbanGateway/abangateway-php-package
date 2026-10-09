<?php

declare(strict_types=1);

namespace AbanGateway\Exception;

use AbanGateway\Http\Response;

/**
 * The API answered with an error. `getErrorCode()` is the stable machine code
 * from the API's error table (insufficient_fee_wallet, no_card_registered,
 * invoice_expired, …); the message is for logs, not for branching.
 */
class ApiException extends AbanGatewayException
{
    /** @var int */
    private $httpStatus;

    /** @var string */
    private $errorCode;

    /** @var array<string, mixed> */
    private $details;

    /** @var array<string, mixed>|null */
    private $body;

    /**
     * @param array<string, mixed> $details
     * @param array<string, mixed>|null $body
     */
    public function __construct(string $message, int $httpStatus, string $errorCode, array $details = array(), ?array $body = null)
    {
        parent::__construct($message, $httpStatus);
        $this->httpStatus = $httpStatus;
        $this->errorCode = $errorCode;
        $this->details = $details;
        $this->body = $body;
    }

    public function getHttpStatus(): int
    {
        return $this->httpStatus;
    }

    public function getErrorCode(): string
    {
        return $this->errorCode;
    }

    /** @return array<string, mixed> */
    public function getDetails(): array
    {
        return $this->details;
    }

    /** @return array<string, mixed>|null */
    public function getBody(): ?array
    {
        return $this->body;
    }

    /** Builds the most specific exception for an error answer. */
    public static function fromResponse(Response $response): self
    {
        $body = $response->json();
        $code = 'http_' . $response->status;
        $message = 'AbanGateway answered HTTP ' . $response->status;
        $details = array();
        if (is_array($body) && isset($body['error']) && is_array($body['error'])) {
            $code = isset($body['error']['code']) ? (string) $body['error']['code'] : $code;
            $message = isset($body['error']['message']) ? (string) $body['error']['message'] : $message;
            $details = isset($body['error']['details']) && is_array($body['error']['details']) ? $body['error']['details'] : array();
        } elseif (is_array($body) && isset($body['detail']) && is_string($body['detail'])) {
            $message = $body['detail'];
        }

        switch (true) {
            case $response->status === 401:
                return new AuthenticationException($message, 401, $code === 'http_401' ? 'authentication_failed' : $code, $details, $body);
            case $response->status === 403:
                return new PermissionException($message, 403, $code === 'http_403' ? 'forbidden' : $code, $details, $body);
            case $response->status === 404:
                return new NotFoundException($message, 404, $code, $details, $body);
            case $response->status === 429:
                $retry = $response->header('retry-after');
                return new RateLimitException($message, 429, $code === 'http_429' ? 'rate_limited' : $code, $details, $body, $retry !== null ? (int) $retry : null);
            case $response->status >= 500:
                return new ServerException($message, $response->status, $code, $details, $body);
            default:
                return new self($message, $response->status, $code, $details, $body);
        }
    }
}
