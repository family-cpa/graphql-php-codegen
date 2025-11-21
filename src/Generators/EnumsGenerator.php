<?php

namespace GraphQLCodegen\Generators;

use GraphQLCodegen\Support\FileWriter;

class EnumsGenerator
{
    private FileWriter $files;

    public function __construct(?FileWriter $files = null)
    {
        $this->files = $files ?? new FileWriter();
    }

    public function generate(array $schema, string $outputDir, string $stubsDir, string $baseNamespace): void
    {
        $enums = $schema['enums'] ?? [];
        $stubPath = $stubsDir . '/enum.stub';
        $stub = file_get_contents($stubPath);
        if ($stub === false) {
            throw new \RuntimeException("Failed to read stub file: {$stubPath}");
        }
        $namespace = $baseNamespace . '\\Enums';
        $targetDir = rtrim($outputDir, '/\\') . '/Enums';

        $this->files->ensureDir($targetDir);

        $generatedFiles = [];

        foreach ($enums as $enum) {
            $className = $enum['name'] ?? '';
            if (empty($className)) {
                continue;
            }

            $values = $enum['values'] ?? [];

            if (empty($values)) {
                // Enum должен иметь хотя бы одно значение
                continue;
            }

            $casesLines = [];
            foreach ($values as $v) {
                if (empty($v)) {
                    continue;
                }
                $casesLines[] = "case {$v} = '{$v}';";
            }

            if (empty($casesLines)) {
                continue;
            }

            $cases = implode("\n", array_map(
                fn($line) => '    ' . $line,
                $casesLines
            ));

            $code = str_replace(
                ['{{ namespace }}', '{{ class }}', '{{ cases }}'],
                [$namespace, $className, $cases],
                $stub
            );

            $path = $targetDir . '/' . $className . '.php';
            $this->files->writeIfChanged($path, $code);
            $generatedFiles[] = $path;
        }

        $this->files->cleanupDirectory($targetDir, $generatedFiles);
    }
}
