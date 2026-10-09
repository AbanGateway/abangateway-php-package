<?php

declare(strict_types=1);

namespace AbanGateway\Http;

/** One HTTP answer: status, lower-cased headers, raw body. */
final class Response
{
    /** @var int */
    public $status;

    /** @var array<string, string> */
    public $headers;

    /** @var string */
    public $body;

    /**
     * @param array<string, string> $headers
     */
    public function __construct(int $status, array $headers, string $body)
    {
        $this->status = $status;
        $this->headers = array_change_key_case($headers, CASE_LOWER);
        $this->body = $body;
    }

    public function header(string $name): ?string
    {
        $key = strtolower($name);
        return isset($this->headers[$key]) ? $this->headers[$key] : null;
    }

    /** The body as an array, or null when it is not a JSON object. */
    public function json(): ?array
    {
        if ($this->body === '') {
            return null;
        }
        $decoded = json_decode($this->body, true);
        return is_array($decoded) ? $decoded : null;
    }
}
