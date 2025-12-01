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

    protected ?string $baseNamespace = null;

    public function __construct(string $endpoint, array $headers = [])
    {
        $this->endpoint = $endpoint;
        $this->headers = $headers;
        $this->typeMapper = new TypeMapper;
    }

    /**
     * @param Operation $operation
     * @return mixed Тип из $operation->type или null
     * @phpstan-template TReturn
     * @phpstan-param Operation $operation
     * @phpstan-return ($operation->type is class-string<TReturn> ? TReturn : ($operation->type is 'array' ? array<int, mixed> : mixed))|null
     */
    public function execute(Operation $operation): mixed
    {
        // Проверяем, есть ли Upload файлы в операции
        $uploadFiles = $this->extractUploadFiles($operation);
        
        if (! empty($uploadFiles)) {
            return $this->executeMultipart($operation, $uploadFiles);
        }

        // Обычный JSON запрос
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

        return $this->deserialize($rawResult, $operation->graphqlType, $this->baseNamespace);
    }

    /**
     * Извлекает Upload файлы из операции через рефлексию
     * 
     * @return array<string, UploadFile> Массив [variablePath => UploadFile]
     */
    protected function extractUploadFiles(Operation $operation): array
    {
        $uploadFiles = [];
        $reflection = new \ReflectionClass($operation);
        
        foreach ($reflection->getProperties() as $property) {
            if (! $property->isPublic()) {
                continue;
            }
            
            $value = $property->getValue($operation);
            
            if ($value === null) {
                continue;
            }
            
            // Прямой Upload файл
            if ($value instanceof UploadFile) {
                $uploadFiles['variables.'.$property->getName()] = $value;
                continue;
            }
            
            // Рекурсивный поиск Upload файлов внутри объектов (например, Input)
            $nestedFiles = $this->extractUploadFilesFromValue($value, 'variables.'.$property->getName());
            $uploadFiles = array_merge($uploadFiles, $nestedFiles);
        }
        
        return $uploadFiles;
    }

    /**
     * Рекурсивно извлекает Upload файлы из значения (объекта или массива)
     * 
     * @param mixed $value Значение для поиска
     * @param string $basePath Базовый путь для переменных (например, "variables.input")
     * @return array<string, UploadFile>
     */
    protected function extractUploadFilesFromValue(mixed $value, string $basePath): array
    {
        $uploadFiles = [];
        
        if ($value instanceof UploadFile) {
            $uploadFiles[$basePath] = $value;
            return $uploadFiles;
        }
        
        // Если это объект с публичными свойствами
        if (is_object($value)) {
            $reflection = new \ReflectionClass($value);
            foreach ($reflection->getProperties() as $property) {
                if (! $property->isPublic()) {
                    continue;
                }
                
                $propValue = $property->getValue($value);
                if ($propValue === null) {
                    continue;
                }
                
                $nestedPath = $basePath.'.'.$property->getName();
                $nestedFiles = $this->extractUploadFilesFromValue($propValue, $nestedPath);
                $uploadFiles = array_merge($uploadFiles, $nestedFiles);
            }
        }
        
        // Если это массив
        if (is_array($value)) {
            foreach ($value as $key => $item) {
                if ($item === null) {
                    continue;
                }
                
                $nestedPath = $basePath.'.'.$key;
                $nestedFiles = $this->extractUploadFilesFromValue($item, $nestedPath);
                $uploadFiles = array_merge($uploadFiles, $nestedFiles);
            }
        }
        
        return $uploadFiles;
    }

    /**
     * Выполняет GraphQL запрос с файлами через multipart/form-data
     * Согласно спецификации: https://github.com/jaydenseric/graphql-multipart-request-spec
     */
    protected function executeMultipart(Operation $operation, array $uploadFiles): mixed
    {
        $variables = $operation->variables();
        
        // Заменяем Upload файлы на null в variables для JSON
        $variablesForJson = $this->replaceUploadsWithNull($variables, $uploadFiles);
        
        // Создаем маппинг файлов: {"0": ["variables.file"]}
        $fileMap = [];
        $fileIndex = 0;
        $multipartData = [];
        
        // Согласно спецификации, порядок должен быть: operations, map, файлы
        // 1. Добавляем operations (первая часть)
        $operations = [
            'query' => $operation->document(),
            'variables' => $variablesForJson,
        ];
        
        $multipartData[] = [
            'name' => 'operations',
            'contents' => json_encode($operations, JSON_THROW_ON_ERROR),
        ];
        
        // 2. Собираем маппинг файлов
        foreach ($uploadFiles as $variablePath => $uploadFile) {
            $fileMap[(string) $fileIndex] = [$variablePath];
            $fileIndex++;
        }
        
        // 3. Добавляем map (вторая часть)
        $multipartData[] = [
            'name' => 'map',
            'contents' => json_encode($fileMap, JSON_THROW_ON_ERROR),
        ];
        
        // 4. Добавляем файлы (третья часть и далее)
        $fileIndex = 0;
        foreach ($uploadFiles as $uploadFile) {
            $multipartData[] = [
                'name' => (string) $fileIndex,
                'contents' => $uploadFile->getContents(),
                'filename' => $uploadFile->getFilename(),
                'headers' => [
                    'Content-Type' => $uploadFile->getMimeType(),
                ],
            ];
            
            $fileIndex++;
        }
        
        // Отправляем multipart запрос
        $response = Http::withHeaders(array_merge(
            [
                'Accept' => 'application/json',
            ],
            $this->headers
        ))->asMultipart()->post($this->endpoint, $multipartData);

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

        return $this->deserialize($rawResult, $operation->graphqlType, $this->baseNamespace);
    }

    /**
     * Заменяет Upload файлы на null в массиве variables
     */
    protected function replaceUploadsWithNull(array $variables, array $uploadFiles): array
    {
        $result = $variables;
        
        foreach ($uploadFiles as $variablePath => $uploadFile) {
            // variablePath имеет формат "variables.file" или "variables.input.file"
            $pathParts = explode('.', $variablePath);
            array_shift($pathParts); // Убираем "variables"
            
            $this->setNestedValue($result, $pathParts, null);
        }
        
        return $result;
    }

    /**
     * Устанавливает значение во вложенном массиве по пути
     */
    protected function setNestedValue(array &$array, array $path, mixed $value): void
    {
        $key = array_shift($path);
        
        if (empty($path)) {
            $array[$key] = $value;
        } else {
            if (! isset($array[$key]) || ! is_array($array[$key])) {
                $array[$key] = [];
            }
            $this->setNestedValue($array[$key], $path, $value);
        }
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
