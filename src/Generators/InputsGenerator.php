<?php

namespace GraphQLCodegen\Generators;

use GraphQLCodegen\Schema\TypeMapper;
use GraphQLCodegen\Support\FileWriter;

class InputsGenerator
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
        $inputs = $schema['inputs'] ?? [];
        $types = $schema['types'] ?? [];
        $enums = $schema['enums'] ?? [];
        $scalarMap = $this->mapper->scalarMap();

        // Индексы для определения типа
        $typeIndex = [];
        foreach ($types as $type) {
            $typeIndex[$type['name']] = 'type';
        }
        foreach ($enums as $enum) {
            $typeIndex[$enum['name']] = 'enum';
        }
        foreach ($inputs as $input) {
            $typeIndex[$input['name']] = 'input';
        }

        $stubPath = $stubsDir.'/input.stub';
        $stub = file_get_contents($stubPath);
        if ($stub === false) {
            throw new \RuntimeException("Failed to read stub file: {$stubPath}");
        }
        $namespace = $baseNamespace.'\\Inputs';
        $targetDir = rtrim($outputDir, '/\\').'/Inputs';

        $this->files->ensureDir($targetDir);

        $generatedFiles = [];

        foreach ($inputs as $input) {
            $className = $input['name'] ?? '';
            if (empty($className)) {
                continue;
            }

            $fields = $input['fields'] ?? [];

            $constructorLines = [];
            $toArrayLines = [];
            $imports = [];

            foreach ($fields as $field) {
                $fieldName = $field['name'] ?? '';
                $fieldType = $field['type'] ?? '';

                if (empty($fieldName) || empty($fieldType)) {
                    continue;
                }

                $typeMapping = $this->mapper->map($fieldType);

                $hint = $typeMapping['php'] !== 'mixed'
                    ? ($typeMapping['nullable'] ? '?'.$typeMapping['php'] : $typeMapping['php'])
                    : '';

                $default = $typeMapping['nullable'] ? ' = null' : '';

                $constructorLines[] = "public {$hint} \${$fieldName}{$default},";

                $toArrayLines[] = "'{$fieldName}' => \$this->{$fieldName},";

                $base = $typeMapping['base'];
                if (! isset($scalarMap[$base]) && isset($typeIndex[$base])) {
                    $imports[$base] = $typeIndex[$base];
                }
            }

            $constructor = implode("\n", array_map(
                fn ($line) => '        '.$line,
                $constructorLines
            ));

            $toArray = implode("\n", array_map(
                fn ($line) => '            '.$line,
                $toArrayLines
            ));

            $uses = '';
            if ($imports) {
                $useLines = [];
                foreach ($imports as $name => $kind) {
                    if ($kind === 'enum') {
                        $useLines[] = "use {$baseNamespace}\\Enums\\{$name};";
                    } elseif ($kind === 'type') {
                        $useLines[] = "use {$baseNamespace}\\Types\\{$name};";
                    } elseif ($kind === 'input') {
                        $useLines[] = "use {$baseNamespace}\\Inputs\\{$name};";
                    }
                }
                $uses = implode("\n", array_unique($useLines));
            }

            $code = str_replace(
                ['{{ namespace }}', '{{ uses }}', '{{ class }}', '{{ constructor }}', '{{ to_array }}'],
                [$namespace, $uses, $className, rtrim($constructor, ','), rtrim($toArray, ',')],
                $stub
            );

            $path = $targetDir.'/'.$className.'.php';
            $this->files->writeIfChanged($path, $code);
            $generatedFiles[] = $path;
        }

        $this->files->cleanupDirectory($targetDir, $generatedFiles);
    }
}
