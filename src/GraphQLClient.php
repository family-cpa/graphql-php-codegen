<?php

namespace GraphQLCodegen;

use GraphQLCodegen\Exceptions\GraphQLException;
use GraphQLCodegen\Exceptions\HttpException;
use GraphQLCodegen\Schema\TypeMapper;
use Illuminate\Support\Facades\Http;

class GraphQLClient
{
    protected string $endpoint;

    protected array $headers;

    protected TypeMapper $typeMapper;

    public function __construct(string $endpoint, array $headers = [])
    {
        $this->endpoint = $endpoint;
        $this->headers = $headers;
        $this->typeMapper = new TypeMapper;
    }

    public function execute(Operation $operation): mixed
    {
        $payload = [
            'query' => $operation->document(),
        ];

        $variables = $operation->variables();
        if (! empty($variables)) {
            $payload['variables'] = $variables;
        }

        $response = Http::withHeaders(array_merge(
            [
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
            ],
            $this->headers
        ))->post($this->endpoint, $payload);

        if (! $response->successful()) {
            throw new HttpException($response->status(), $response->body());
        }

        $data = $response->json();

        if (isset($data['errors'])) {
            throw new GraphQLException($data['errors'], $data['extensions'] ?? null);
        }

        $rawResult = $data['data'][$operation->operation] ?? null;

        if ($rawResult === null) {
            return null;
        }

        return $this->deserialize($rawResult, $operation->graphqlType, $operation->namespace);
    }

    protected function deserialize(mixed $data, string $graphQLType, ?string $baseNamespace = null): mixed
    {
        if ($data === null) {
            return null;
        }

        if ($baseNamespace === null) {
            return $data;
        }

        $typeMapping = $this->typeMapper->map($graphQLType);
        $baseType = $typeMapping['base'];

        if ($this->typeMapper->isScalar($baseType)) {
            $scalarMap = $this->typeMapper->scalarMap();
            $phpType = $scalarMap[$baseType] ?? 'mixed';

            return match ($phpType) {
                'int' => (int) $data,
                'float' => (float) $data,
                'bool' => (bool) $data,
                default => (string) $data,
            };
        }

        if (! is_array($data)) {
            return $data;
        }

        $className = $this->resolveClassName($baseType, $baseNamespace);
        if ($className === null || ! class_exists($className)) {
            return $data;
        }

        if ($typeMapping['isList']) {
            return array_map(fn ($item) => $className::tryFrom($item), $data);
        }

        return $className::tryFrom($data);
    }

    protected function resolveClassName(string $baseType, ?string $baseNamespace): ?string
    {
        $scalarMap = $this->typeMapper->scalarMap();
        if (isset($scalarMap[$baseType])) {
            return null;
        }

        if ($baseNamespace === null) {
            return null;
        }

        $possibleNamespaces = [
            $baseNamespace.'\\Types\\',
            $baseNamespace.'\\Enums\\',
        ];

        foreach ($possibleNamespaces as $namespace) {
            $fullName = $namespace.$baseType;
            if (class_exists($fullName)) {
                return $fullName;
            }
        }

        return null;
    }
}
