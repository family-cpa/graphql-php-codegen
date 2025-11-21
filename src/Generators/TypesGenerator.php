<?php

namespace GraphQLCodegen\Generators;

use GraphQLCodegen\Schema\TypeMapper;
use GraphQLCodegen\Support\FileWriter;

class TypesGenerator
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
        $types = $schema['types'] ?? [];
        if (! $types) {
            return;
        }

        $enumNames = $this->indexByName($schema['enums'] ?? []);
        $typeNames = $this->indexByName($types);
        $inputNames = $this->indexByName($schema['inputs'] ?? []);

        $stubPath = $stubsDir.'/type.stub';
        $stub = file_get_contents($stubPath);
        if ($stub === false) {
            throw new \RuntimeException("Failed to read stub file: {$stubPath}");
        }
        $namespace = $baseNamespace.'\\Types';
        $targetDir = rtrim($outputDir, '/\\').'/Types';

        $this->files->ensureDir($targetDir);

        $generatedFiles = [];

        foreach ($types as $type) {
            $className = $type['name'] ?? '';
            if (empty($className)) {
                continue;
            }

            $fields = $type['fields'] ?? [];

            $constructorLines = [];
            $fromArrayLines = [];
            $fieldConstants = [];
            $imports = [];
            $scalarMap = $this->mapper->scalarMap();

            foreach ($fields as $field) {
                $fieldName = $field['name'] ?? '';
                $fieldType = $field['type'] ?? '';

                if (empty($fieldName) || empty($fieldType)) {
                    continue;
                }

                // Генерируем константу для поля
                // Преобразуем camelCase в SNAKE_CASE: offerID -> OFFER_ID
                $constantName = preg_replace('/([a-z])([A-Z])/', '$1_$2', $fieldName);
                $constantName = strtoupper(preg_replace('/[^a-zA-Z0-9]/', '_', $constantName));
                $fieldConstants[] = "    public const {$constantName} = '{$fieldName}';";

                $typeMapping = $this->mapper->map($fieldType);

                $hint = $typeMapping['php'] !== 'mixed'
                    ? ($typeMapping['nullable'] ? '?'.$typeMapping['php'] : $typeMapping['php'])
                    : '';

                $default = $typeMapping['nullable'] ? ' = null' : '';
                $hintPart = $hint !== '' ? $hint.' ' : '';

                $constructorLines[] = "public {$hintPart}\${$fieldName}{$default},";

                $import = $this->resolveImport($typeMapping['base'], $className, $typeNames, $enumNames, $inputNames, $baseNamespace);
                if ($import) {
                    $imports[$import] = true;
                }

                $fromArrayValue = $this->generateFromArrayValue($fieldName, $typeMapping, $scalarMap, $enumNames, $typeNames, $baseNamespace, $imports);
                $fromArrayLines[] = $fromArrayValue;
            }

            $constructor = implode("\n", array_map(
                fn ($line) => '        '.$line,
                $constructorLines
            ));

            $fromArray = implode(",\n", array_map(
                fn ($line) => '            '.$line,
                $fromArrayLines
            ));

            $uses = $imports ? implode("\n", array_keys($imports)) : '';
            $constants = $fieldConstants ? implode("\n", $fieldConstants) : '';

            $code = str_replace(
                ['{{ namespace }}', '{{ uses }}', '{{ class }}', '{{ constants }}', '{{ constructor }}', '{{ from_array }}'],
                [$namespace, $uses, $className, $constants, rtrim($constructor, ','), $fromArray],
                $stub
            );

            $path = $targetDir.'/'.$className.'.php';
            $this->files->writeIfChanged($path, $code);
            $generatedFiles[] = $path;
        }

        $this->files->cleanupDirectory($targetDir, $generatedFiles);
    }

    /**
     * @param  array<int,array{name:string}>  $items
     * @return array<string,bool>
     */
    private function indexByName(array $items): array
    {
        $index = [];
        foreach ($items as $item) {
            $index[$item['name']] = true;
        }

        return $index;
    }

    /**
     * @param  array<string,bool>  $typeNames
     * @param  array<string,bool>  $enumNames
     * @param  array<string,bool>  $inputNames
     */
    private function resolveImport(string $base, string $className, array $typeNames, array $enumNames, array $inputNames, string $baseNamespace): ?string
    {
        if ($this->mapper->isScalar($base)) {
            return null;
        }

        if ($base === $className && isset($typeNames[$base])) {
            return null;
        }

        if (isset($enumNames[$base])) {
            return "use {$baseNamespace}\\Enums\\{$base};";
        }

        if (isset($typeNames[$base])) {
            return "use {$baseNamespace}\\Types\\{$base};";
        }

        if (isset($inputNames[$base])) {
            return "use {$baseNamespace}\\Inputs\\{$base};";
        }

        return null;
    }

    private function generateFromArrayValue(
        string $fieldName,
        array $typeMapping,
        array $scalarMap,
        array $enumNames,
        array $typeNames,
        string $baseNamespace,
        array $imports = []
    ): string {
        $base = $typeMapping['base'];
        $isList = $typeMapping['isList'];
        $nullable = $typeMapping['nullable'];

        $valueExpr = "\$data['{$fieldName}'] ?? null";
        $valueExprWrapped = "({$valueExpr})";

        if ($nullable) {
            $nullCheck = "{$valueExprWrapped} === null ? null : ";
        } else {
            $nullCheck = '';
        }

        // Получаем короткое имя класса из импортов
        $shortClassName = $this->getShortClassName($base, $baseNamespace, $enumNames, $typeNames, $imports);

        if ($isList) {
            if (isset($scalarMap[$base])) {
                $phpType = $scalarMap[$base];

                return "{$nullCheck}array_map(fn(\$value) => ({$phpType}) \$value, {$valueExprWrapped} ?? [])";
            }

            if (isset($enumNames[$base])) {
                return "{$nullCheck}array_map(fn(\$value) => {$shortClassName}::tryFrom(\$value) ?? \$value, {$valueExprWrapped} ?? [])";
            }

            if (isset($typeNames[$base])) {
                return "{$nullCheck}array_map(fn(\$value) => {$shortClassName}::fromArray(\$value), {$valueExprWrapped} ?? [])";
            }

            return "{$nullCheck}{$valueExprWrapped} ?? []";
        }

        if (isset($scalarMap[$base])) {
            $phpType = $scalarMap[$base];

            return "{$nullCheck}({$phpType}) {$valueExprWrapped}";
        }

        if (isset($enumNames[$base])) {
            return "{$nullCheck}{$shortClassName}::tryFrom({$valueExprWrapped}) ?? {$valueExprWrapped}";
        }

        if (isset($typeNames[$base])) {
            return "{$nullCheck}{$shortClassName}::fromArray({$valueExprWrapped})";
        }

        return "{$nullCheck}{$valueExprWrapped}";
    }

    private function getShortClassName(string $base, string $baseNamespace, array $enumNames, array $typeNames, array $imports): string
    {
        // Проверяем, есть ли импорт для этого класса
        foreach ($imports as $import => $_) {
            // Импорты хранятся как ключи массива в формате "use Namespace\Class;"
            if (preg_match('/use\s+([^;]+)\s*;/', $import, $matches)) {
                $fullPath = trim($matches[1]);
                $parts = explode('\\', $fullPath);
                $importedName = end($parts);
                if ($importedName === $base) {
                    return $base;
                }
            }
        }

        // Если импорта нет, используем полный путь
        if (isset($enumNames[$base])) {
            return "{$baseNamespace}\\Enums\\{$base}";
        }

        if (isset($typeNames[$base])) {
            return "{$baseNamespace}\\Types\\{$base}";
        }

        return $base;
    }
}
