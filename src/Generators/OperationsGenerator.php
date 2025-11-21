<?php

namespace GraphQLCodegen\Generators;

use GraphQLCodegen\Schema\TypeMapper;
use GraphQLCodegen\Support\FileWriter;

class OperationsGenerator
{
    private FileWriter $files;

    private TypeMapper $mapper;

    public function __construct(?FileWriter $files = null, ?TypeMapper $mapper = null)
    {
        $this->files = $files ?? new FileWriter;
        $this->mapper = $mapper ?? new TypeMapper;
    }

    public function generate(array $schema, string $outputDir, string $stubsDir, string $baseNamespace): void
    {
        $queryFields = $schema['query'] ?? [];
        $mutationFields = $schema['mutation'] ?? [];
        $typeMap = $schema['typeMap'] ?? [];
        $types = $schema['types'] ?? [];
        $enums = $schema['enums'] ?? [];
        $inputs = $schema['inputs'] ?? [];
        $scalarMap = $this->mapper->scalarMap();

        // Индексы для быстрого определения типа
        $typeNames = [];
        foreach ($types as $type) {
            $typeNames[$type['name']] = 'type';
        }
        foreach ($enums as $enum) {
            $typeNames[$enum['name']] = 'enum';
        }
        foreach ($inputs as $input) {
            $typeNames[$input['name']] = 'input';
        }

        $queryStubPath = $stubsDir.'/query.stub';
        $mutationStubPath = $stubsDir.'/mutation.stub';

        $queryStub = file_get_contents($queryStubPath);
        if ($queryStub === false) {
            throw new \RuntimeException("Failed to read stub file: {$queryStubPath}");
        }

        $mutationStub = file_get_contents($mutationStubPath);
        if ($mutationStub === false) {
            throw new \RuntimeException("Failed to read stub file: {$mutationStubPath}");
        }

        $queryNamespace = $baseNamespace.'\\Operations\\Query';
        $mutationNamespace = $baseNamespace.'\\Operations\\Mutation';

        $queryDir = rtrim($outputDir, '/\\').'/Operations/Query';
        $mutationDir = rtrim($outputDir, '/\\').'/Operations/Mutation';

        $this->files->ensureDir($queryDir);
        $this->files->ensureDir($mutationDir);

        $queryFiles = [];
        $mutationFiles = [];

        foreach ($queryFields as $field) {
            $path = $this->generateOperation(
                $field,
                $typeMap,
                $typeNames,
                $scalarMap,
                $queryDir,
                $queryNamespace,
                $queryStub,
                'query',
                $baseNamespace
            );
            if ($path) {
                $queryFiles[] = $path;
            }
        }

        foreach ($mutationFields as $field) {
            $path = $this->generateOperation(
                $field,
                $typeMap,
                $typeNames,
                $scalarMap,
                $mutationDir,
                $mutationNamespace,
                $mutationStub,
                'mutation',
                $baseNamespace
            );
            if ($path) {
                $mutationFiles[] = $path;
            }
        }

        $this->files->cleanupDirectory($queryDir, $queryFiles);
        $this->files->cleanupDirectory($mutationDir, $mutationFiles);
    }

    private function generateOperation(
        array $field,
        array $typeMap,
        array $typeNames,
        array $scalarMap,
        string $targetDir,
        string $namespace,
        string $stub,
        string $kind,
        string $baseNamespace
    ): ?string {
        $name = $field['name'] ?? '';
        if (empty($name)) {
            return null;
        }

        $args = $field['args'] ?? [];
        $returnType = $field['returnType'] ?? '';

        if (empty($returnType)) {
            return null;
        }

        $typeMapping = $this->mapper->map($returnType);

        // Имя класса операции: <FieldName>Query/Mutation
        $suffix = $kind === 'query' ? 'Query' : 'Mutation';
        $className = ucfirst($name).$suffix;

        // constructor
        $ctorLines = [];
        $varsLines = [];
        foreach ($args as $arg) {
            $argName = $arg['name'] ?? '';
            $argType = $arg['type'] ?? '';

            if (empty($argName) || empty($argType)) {
                continue;
            }

            $argTypeMapping = $this->mapper->map($argType);

            $hint = $argTypeMapping['php'] !== 'mixed'
                ? ($argTypeMapping['nullable'] ? '?'.$argTypeMapping['php'] : $argTypeMapping['php'])
                : '';

            $default = $argTypeMapping['nullable'] ? ' = null' : '';

            $ctorLines[] = "public {$hint} \${$argName}{$default},";

            $varsLines[] = "'{$argName}' => \$this->{$argName},";
        }

        $constructor = '';
        if ($ctorLines) {
            $constructor = implode("\n", array_map(
                fn ($line) => '        '.$line,
                $ctorLines
            ));
        }

        $variables = '';
        if ($varsLines) {
            $variables = implode("\n", array_map(
                fn ($line) => '            '.$line,
                $varsLines
            ));
        }

        // args_signature: ($id: ID!, $limit: Int) или пустая строка
        $argsSignature = '';
        if ($args) {
            $sigParts = [];
            foreach ($args as $arg) {
                $argName = $arg['name'] ?? '';
                $argType = $arg['type'] ?? '';
                if (! empty($argName) && ! empty($argType)) {
                    $sigParts[] = '$'.$argName.': '.$argType;
                }
            }
            if ($sigParts) {
                $argsSignature = '('.implode(', ', $sigParts).')';
            }
        }

        // args_pass: (id: $id, limit: $limit) или пустая строка
        $argsPass = '';
        if ($args) {
            $passParts = [];
            foreach ($args as $arg) {
                $argName = $arg['name'] ?? '';
                if (! empty($argName)) {
                    $passParts[] = $argName.': $'.$argName;
                }
            }
            if ($passParts) {
                $argsPass = '('.implode(', ', $passParts).')';
            }
        }

        // selection set — рекурсивная генерация всех полей с вложенностью
        $selection = '';
        $base = $typeMapping['base'];

        if (! isset($scalarMap[$base])) {
            $selectionSet = $this->buildSelectionSet($base, $typeMap, $typeNames, $scalarMap, 1);
            if ($selectionSet) {
                $selection = " {\n{$selectionSet}\n    }";
            } else {
                // Если нет полей, все равно нужны фигурные скобки для валидного GraphQL
                $selection = ' {}';
            }
        }

        // Собираем импорты для возвращаемого типа и аргументов
        $imports = [];

        // Импорт для возвращаемого типа
        if (! isset($scalarMap[$base]) && isset($typeNames[$base])) {
            $typeKind = $typeNames[$base];
            if ($typeKind === 'enum') {
                $imports[] = "use {$baseNamespace}\\Enums\\{$base};";
            } elseif ($typeKind === 'type') {
                $imports[] = "use {$baseNamespace}\\Types\\{$base};";
            }
        }

        // Импорты для типов аргументов
        foreach ($args as $arg) {
            $argType = $arg['type'] ?? '';
            if (empty($argType)) {
                continue;
            }

            $argTypeMapping = $this->mapper->map($argType);
            $argBase = $argTypeMapping['base'];

            // Пропускаем скалярные типы
            if (isset($scalarMap[$argBase])) {
                continue;
            }

            // Добавляем импорт если это известный тип
            if (isset($typeNames[$argBase])) {
                $typeKind = $typeNames[$argBase];
                $import = '';

                if ($typeKind === 'enum') {
                    $import = "use {$baseNamespace}\\Enums\\{$argBase};";
                } elseif ($typeKind === 'type') {
                    $import = "use {$baseNamespace}\\Types\\{$argBase};";
                } elseif ($typeKind === 'input') {
                    $import = "use {$baseNamespace}\\Inputs\\{$argBase};";
                }

                if ($import && ! in_array($import, $imports, true)) {
                    $imports[] = $import;
                }
            }
        }

        $uses = implode("\n", $imports);

        // Определяем возвращаемый тип для метода type()
        $typeClass = '';
        if ($typeMapping['isList']) {
            $typeClass = "'array'";
        } elseif (isset($scalarMap[$typeMapping['base']])) {
            $phpType = $scalarMap[$typeMapping['base']];
            $typeClass = "'{$phpType}'";
        } elseif (isset($typeNames[$typeMapping['base']])) {
            $typeKind = $typeNames[$typeMapping['base']];
            if ($typeKind === 'enum') {
                $fullType = $baseNamespace.'\\Enums\\'.$typeMapping['base'];
                $typeClass = "{$fullType}::class";
            } elseif ($typeKind === 'type') {
                $fullType = $baseNamespace.'\\Types\\'.$typeMapping['base'];
                $typeClass = "{$fullType}::class";
            }
        }

        if (empty($typeClass)) {
            $typeClass = "'mixed'";
        }

        $code = str_replace(
            [
                '{{ namespace }}',
                '{{ uses }}',
                '{{ class }}',
                '{{ constructor }}',
                '{{ operation_name }}',
                '{{ args_signature }}',
                '{{ field_name }}',
                '{{ args_pass }}',
                '{{ selection }}',
                '{{ variables }}',
                '{{ type_class }}',
                '{{ graphql_return_type }}',
                '{{ base_namespace }}',
            ],
            [
                $namespace,
                $uses,
                $className,
                rtrim($constructor, ','),
                ucfirst($name),
                $argsSignature,
                $name,
                $argsPass,
                $selection,
                rtrim($variables, ','),
                $typeClass,
                $returnType,
                $baseNamespace,
            ],
            $stub
        );

        $path = $targetDir.'/'.$className.'.php';
        $this->files->writeIfChanged($path, $code);

        return $path;
    }

    private function buildSelectionSet(
        string $typeName,
        array $typeMap,
        array $typeNames,
        array $scalarMap,
        int $depth = 1,
        array $visited = []
    ): string {
        // Ограничение глубины рекурсии (максимум 5 уровней)
        if ($depth > 5) {
            return '';
        }

        // Защита от циклических ссылок
        if (isset($visited[$typeName])) {
            return '';
        }
        $visited[$typeName] = true;

        // Если это enum или скаляр - просто возвращаем пустую строку (поле будет без вложенности)
        if (isset($scalarMap[$typeName]) || (isset($typeNames[$typeName]) && $typeNames[$typeName] === 'enum')) {
            return '';
        }

        // Если это не объект - возвращаем пустую строку
        if (! isset($typeMap[$typeName])) {
            return '';
        }

        $fields = $typeMap[$typeName];
        if (empty($fields)) {
            return '';
        }

        $lines = [];
        $indent = str_repeat('    ', $depth);

        foreach ($fields as $field) {
            $fieldName = $field['name'] ?? '';
            $fieldType = $field['type'] ?? '';

            if (empty($fieldName) || empty($fieldType)) {
                continue;
            }

            $fieldTypeMapping = $this->mapper->map($fieldType);
            $fieldBase = $fieldTypeMapping['base'];

            // Если это скаляр или enum - просто имя поля
            if (isset($scalarMap[$fieldBase]) || (isset($typeNames[$fieldBase]) && $typeNames[$fieldBase] === 'enum')) {
                $lines[] = $indent.$fieldName;
            }
            // Если это объект - рекурсивно генерируем вложенные поля
            elseif (isset($typeMap[$fieldBase])) {
                $nestedSelection = $this->buildSelectionSet($fieldBase, $typeMap, $typeNames, $scalarMap, $depth + 1, $visited);
                if ($nestedSelection) {
                    $lines[] = $indent.$fieldName." {\n".$nestedSelection."\n".$indent.'}';
                } else {
                    // Если вложенных полей нет, но это объект - все равно добавляем поле с пустыми скобками
                    $lines[] = $indent.$fieldName.' {}';
                }
            }
            // Неизвестный тип - просто имя поля
            else {
                $lines[] = $indent.$fieldName;
            }
        }

        return implode("\n", $lines);
    }
}
