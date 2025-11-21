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
        $this->files  = $files ?? new FileWriter();
        $this->mapper = $mapper ?? new TypeMapper();
    }

    public function generate(array $schema, string $outputDir, string $stubsDir, string $baseNamespace): void
    {
        $types = $schema['types'] ?? [];
        if (!$types) {
            return;
        }

        $enumNames = $this->indexByName($schema['enums'] ?? []);
        $typeNames = $this->indexByName($types);
        $inputNames = $this->indexByName($schema['inputs'] ?? []);

        $stubPath = $stubsDir . '/type.stub';
        $stub = file_get_contents($stubPath);
        if ($stub === false) {
            throw new \RuntimeException("Failed to read stub file: {$stubPath}");
        }
        $namespace = $baseNamespace . '\\Types';
        $targetDir = rtrim($outputDir, '/\\') . '/Types';

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
            $imports = [];
            $scalarMap = $this->mapper->scalarMap();

            foreach ($fields as $field) {
                $fieldName = $field['name'] ?? '';
                $fieldType = $field['type'] ?? '';
                
                if (empty($fieldName) || empty($fieldType)) {
                    continue;
                }

                $tm = $this->mapper->map($fieldType);

                $hint = $tm['php'] !== 'mixed'
                    ? ($tm['nullable'] ? '?' . $tm['php'] : $tm['php'])
                    : '';

                $default = $tm['nullable'] ? ' = null' : '';
                $hintPart = $hint !== '' ? $hint . ' ' : '';

                $constructorLines[] = "public {$hintPart}\${$fieldName}{$default},";

                $fromArrayValue = $this->generateFromArrayValue($fieldName, $tm, $scalarMap, $enumNames, $typeNames, $baseNamespace);
                $fromArrayLines[] = $fromArrayValue;

                $import = $this->resolveImport($tm['base'], $className, $typeNames, $enumNames, $inputNames, $baseNamespace);
                if ($import) {
                    $imports[$import] = true;
                }
            }

            $constructor = implode("\n", array_map(
                fn($line) => '        ' . $line,
                $constructorLines
            ));

            $fromArray = implode(",\n", array_map(
                fn($line) => '            ' . $line,
                $fromArrayLines
            ));

            $uses = $imports ? implode("\n", array_keys($imports)) : '';

            $code = str_replace(
                ['{{ namespace }}', '{{ uses }}', '{{ class }}', '{{ constructor }}', '{{ from_array }}'],
                [$namespace, $uses, $className, rtrim($constructor, ","), $fromArray],
                $stub
            );

            $path = $targetDir . '/' . $className . '.php';
            $this->files->writeIfChanged($path, $code);
            $generatedFiles[] = $path;
        }

        $this->files->cleanupDirectory($targetDir, $generatedFiles);
    }

    /**
     * @param array<int,array{name:string}> $items
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
     * @param array<string,bool> $typeNames
     * @param array<string,bool> $enumNames
     * @param array<string,bool> $inputNames
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
        array $tm,
        array $scalarMap,
        array $enumNames,
        array $typeNames,
        string $baseNamespace
    ): string {
        $base = $tm['base'];
        $isList = $tm['isList'];
        $nullable = $tm['nullable'];

        $valueExpr = "\$data['{$fieldName}'] ?? null";
        $valueExprWrapped = "({$valueExpr})";

        if ($nullable) {
            $nullCheck = "{$valueExprWrapped} === null ? null : ";
        } else {
            $nullCheck = '';
        }

        if ($isList) {
            if (isset($scalarMap[$base])) {
                $phpType = $scalarMap[$base];
                return "{$nullCheck}array_map(fn(\$v) => ({$phpType}) \$v, {$valueExprWrapped} ?? [])";
            }

            if (isset($enumNames[$base])) {
                $enumClass = "{$baseNamespace}\\Enums\\{$base}";
                return "{$nullCheck}array_map(fn(\$v) => {$enumClass}::tryFrom(\$v) ?? \$v, {$valueExprWrapped} ?? [])";
            }

            if (isset($typeNames[$base])) {
                $typeClass = "{$baseNamespace}\\Types\\{$base}";
                return "{$nullCheck}array_map(fn(\$v) => {$typeClass}::fromArray(\$v), {$valueExprWrapped} ?? [])";
            }

            return "{$nullCheck}{$valueExprWrapped} ?? []";
        }

        if (isset($scalarMap[$base])) {
            $phpType = $scalarMap[$base];
            return "{$nullCheck}({$phpType}) {$valueExprWrapped}";
        }

        if (isset($enumNames[$base])) {
            $enumClass = "{$baseNamespace}\\Enums\\{$base}";
            return "{$nullCheck}{$enumClass}::tryFrom({$valueExprWrapped}) ?? {$valueExprWrapped}";
        }

        if (isset($typeNames[$base])) {
            $typeClass = "{$baseNamespace}\\Types\\{$base}";
            return "{$nullCheck}{$typeClass}::fromArray({$valueExprWrapped})";
        }

        return "{$nullCheck}{$valueExprWrapped}";
    }
}
