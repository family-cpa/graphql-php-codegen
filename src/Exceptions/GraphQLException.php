<?php

namespace GraphQLCodegen\Exceptions;

class GraphQLException extends \RuntimeException
{
    private array $errors;

    private ?array $extensions;

    public function __construct(array $errors, ?array $extensions = null, int $code = 0, ?\Throwable $previous = null)
    {
        $message = 'GraphQL errors: '.json_encode($errors, JSON_UNESCAPED_UNICODE);
        parent::__construct($message, $code, $previous);

        $this->errors = $errors;
        $this->extensions = $extensions;
    }

    public function getErrors(): array
    {
        return $this->errors;
    }

    public function getExtensions(): ?array
    {
        return $this->extensions;
    }

    public function getFirstError(): ?array
    {
        return $this->errors[0] ?? null;
    }

    public function getFirstErrorMessage(): ?string
    {
        $firstError = $this->getFirstError();

        return $firstError['message'] ?? null;
    }
}
