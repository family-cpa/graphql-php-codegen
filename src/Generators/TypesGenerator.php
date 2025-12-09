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

            $propertyDocLines = [];
            $propertyLines = [];
            $fromArrayLines = [];
            $toArrayLines = [];
            $fieldConstants = [];
            $imports = [];
            $scalarMap = $this->mapper->scalarMap();

            // Добавляем поле _kind в начало toArray
            $toArrayLines[] = "        \$result['_kind'] = '{$className}';";

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

                $import = $this->resolveImport($typeMapping['base'], $className, $typeNames, $enumNames, $inputNames, $baseNamespace);
                if ($import) {
                    $imports[$import] = true;
                }

                // Генерируем @property докблок
                $phpType = $this->getPhpTypeForDoc($typeMapping, $typeMapping['base'], $scalarMap, $enumNames, $typeNames, $baseNamespace, $imports);
                $propertyDocLines[] = " * @property {$phpType} \${$fieldName}";

                // Генерируем публичное свойство
                $hint = $this->getPhpTypeHint($typeMapping, $typeMapping['base'], $scalarMap, $enumNames, $typeNames, $baseNamespace, $imports);
                $propertyLines[] = "    public {$hint} \${$fieldName};";

                // Генерируем присваивание в tryFrom только если поле есть в $data
                $fromArrayValue = $this->generateFromArrayValue($fieldName, $typeMapping, $scalarMap, $enumNames, $typeNames, $baseNamespace, $imports);
                $fromArrayLines[] = "        if (array_key_exists('{$fieldName}', \$data)) {";
                $fromArrayLines[] = "            \$instance->{$fieldName} = {$fromArrayValue};";
                $fromArrayLines[] = '        }';

                // Генерируем код для toArray только если свойство установлено
                $toArrayValue = $this->generateToArrayValue($fieldName, $typeMapping, $scalarMap, $enumNames, $typeNames, $baseNamespace, $imports);
                $toArrayLines[] = "        if (isset(\$this->{$fieldName})) {";
                $toArrayLines[] = "            \$result['{$fieldName}'] = {$toArrayValue};";
                $toArrayLines[] = '        }';
            }

            $properties = implode("\n", $propertyDocLines);
            $propertiesCode = implode("\n", $propertyLines);

            $fromArray = implode("\n", $fromArrayLines);
            $toArray = implode("\n", $toArrayLines);

            $uses = $imports ? implode("\n", array_keys($imports)) : '';
            $constants = $fieldConstants ? implode("\n", $fieldConstants) : '';

            $code = str_replace(
                ['{{ namespace }}', '{{ uses }}', '{{ class }}', '{{ constants }}', '{{ properties }}', '{{ properties_code }}', '{{ from_array }}', '{{ to_array }}'],
                [$namespace, $uses, $className, $constants, $properties, $propertiesCode, $fromArray, $toArray],
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

        // Внутри isset() блока $data['field'] всегда существует, не нужен ??
        $valueExpr = "\$data['{$fieldName}']";
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

                if ($phpType === 'mixed') {
                    // Для mixed каст недопустим — возвращаем как есть
                    return "{$nullCheck}{$valueExprWrapped} ?? []";
                }

                return "{$nullCheck}array_map(fn(\$value) => ({$phpType}) \$value, {$valueExprWrapped} ?? [])";
            }

            if (isset($enumNames[$base])) {
                return "{$nullCheck}array_map(fn(\$value) => {$shortClassName}::tryFrom(\$value) ?? \$value, {$valueExprWrapped} ?? [])";
            }

            if (isset($typeNames[$base])) {
                return "{$nullCheck}array_map(fn(\$value) => {$shortClassName}::tryFrom(\$value), {$valueExprWrapped} ?? [])";
            }

            return "{$nullCheck}{$valueExprWrapped} ?? []";
        }

        if (isset($scalarMap[$base])) {
            $phpType = $scalarMap[$base];

            if ($phpType === 'mixed') {
                // Для mixed каст недопустим — возвращаем как есть
                return "{$nullCheck}{$valueExprWrapped}";
            }

            return "{$nullCheck}({$phpType}) {$valueExprWrapped}";
        }

        if (isset($enumNames[$base])) {
            return "{$nullCheck}{$shortClassName}::tryFrom({$valueExprWrapped}) ?? {$valueExprWrapped}";
        }

        if (isset($typeNames[$base])) {
            return "{$nullCheck}{$shortClassName}::tryFrom({$valueExprWrapped})";
        }

        return "{$nullCheck}{$valueExprWrapped}";
    }

    private function getPhpTypeForDoc(
        array $typeMapping,
        string $base,
        array $scalarMap,
        array $enumNames,
        array $typeNames,
        string $baseNamespace,
        array $imports
    ): string {
        $isList = $typeMapping['isList'];
        $nullable = $typeMapping['nullable'];

        // Получаем короткое имя класса из импортов
        $shortClassName = $this->getShortClassName($base, $baseNamespace, $enumNames, $typeNames, $imports);

        $type = '';
        if ($isList) {
            if (isset($scalarMap[$base])) {
                $phpType = $scalarMap[$base];
                $type = "{$phpType}[]";
            } elseif (isset($enumNames[$base])) {
                $type = "{$shortClassName}[]";
            } elseif (isset($typeNames[$base])) {
                $type = "{$shortClassName}[]";
            } else {
                $type = 'array';
            }
        } else {
            if (isset($scalarMap[$base])) {
                $type = $scalarMap[$base];
            } elseif (isset($enumNames[$base])) {
                $type = $shortClassName;
            } elseif (isset($typeNames[$base])) {
                $type = $shortClassName;
            } else {
                $type = 'mixed';
            }
        }

        return $nullable ? "{$type}|null" : $type;
    }

    private function getPhpTypeHint(
        array $typeMapping,
        string $base,
        array $scalarMap,
        array $enumNames,
        array $typeNames,
        string $baseNamespace,
        array $imports
    ): string {
        $isList = $typeMapping['isList'];
        $nullable = $typeMapping['nullable'];

        // Получаем короткое имя класса из импортов
        $shortClassName = $this->getShortClassName($base, $baseNamespace, $enumNames, $typeNames, $imports);

        $hint = '';
        if ($isList) {
            $hint = 'array';
        } elseif (isset($scalarMap[$base])) {
            $hint = $scalarMap[$base];
        } elseif (isset($enumNames[$base]) || isset($typeNames[$base])) {
            $hint = $shortClassName;
        } else {
            $hint = 'mixed';
        }

        return $nullable ? "?{$hint}" : $hint;
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

    private function generateToArrayValue(
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

        $valueExpr = "\$this->{$fieldName}";

        // Получаем короткое имя класса из импортов
        $shortClassName = $this->getShortClassName($base, $baseNamespace, $enumNames, $typeNames, $imports);

        if ($isList) {
            if (isset($scalarMap[$base])) {
                return "{$valueExpr}";
            }

            if (isset($enumNames[$base])) {
                return "{$valueExpr}";
            }

            if (isset($typeNames[$base])) {
                return "array_map(fn(\$item) => \$item->toArray(), {$valueExpr})";
            }

            return "{$valueExpr}";
        }

        if (isset($scalarMap[$base])) {
            return "{$valueExpr}";
        }

        if (isset($enumNames[$base])) {
            return "{$valueExpr}";
        }

        if (isset($typeNames[$base])) {
            return "\$this->{$fieldName}->toArray()";
        }

        return "{$valueExpr}";
    }
}
