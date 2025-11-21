<?php

namespace GraphQLCodegen\Generators;

use GraphQLCodegen\Support\FileWriter;

class ClientGenerator
{
    private FileWriter $files;

    public function __construct(?FileWriter $files = null)
    {
        $this->files = $files ?? new FileWriter;
    }

    public function generate(string $outputDir, string $stubsDir, string $baseNamespace): void
    {
        $stubPath = $stubsDir.'/client.stub';
        $stub = file_get_contents($stubPath);
        if ($stub === false) {
            throw new \RuntimeException("Failed to read stub file: {$stubPath}");
        }

        $namespace = $baseNamespace;
        $targetDir = rtrim($outputDir, '/\\');
        $className = 'Client';

        $this->files->ensureDir($targetDir);

        $code = str_replace(
            ['{{ namespace }}', '{{ class }}'],
            [$namespace, $className],
            $stub
        );

        $path = $targetDir.'/'.$className.'.php';

        if (file_exists($path)) {
            return;
        }

        $this->files->writeIfChanged($path, $code);
    }
}
