<?php

namespace GraphQLCodegen\Exceptions;

class HttpException extends \RuntimeException
{
    private int $statusCode;

    private ?string $responseBody;

    public function __construct(int $statusCode, ?string $responseBody = null, ?\Throwable $previous = null)
    {
        $message = "HTTP request failed with status {$statusCode}";
        if ($responseBody) {
            $message .= ": {$responseBody}";
        }

        parent::__construct($message, $statusCode, $previous);

        $this->statusCode = $statusCode;
        $this->responseBody = $responseBody;
    }

    public function getStatusCode(): int
    {
        return $this->statusCode;
    }

    public function getResponseBody(): ?string
    {
        return $this->responseBody;
    }
}
