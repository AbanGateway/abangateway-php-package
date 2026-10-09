<?php

declare(strict_types=1);

namespace AbanGateway\Exception;

/** Too many requests a minute for this shop; wait getRetryAfter() seconds. */
class RateLimitException extends ApiException
{
    /** @var int|null */
    private $retryAfter;

    /**
     * @param array<string, mixed> $details
     * @param array<string, mixed>|null $body
     */
    public function __construct(string $message, int $httpStatus, string $errorCode, array $details = array(), ?array $body = null, ?int $retryAfter = null)
    {
        parent::__construct($message, $httpStatus, $errorCode, $details, $body);
        $this->retryAfter = $retryAfter;
    }

    public function getRetryAfter(): ?int
    {
        return $this->retryAfter;
    }
}
