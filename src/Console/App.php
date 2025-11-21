<?php

namespace GraphQLCodegen\Console;

use GraphQLCodegen\Generators\ClientGenerator;
use GraphQLCodegen\Generators\EnumsGenerator;
use GraphQLCodegen\Generators\InputsGenerator;
use GraphQLCodegen\Generators\OperationsGenerator;
use GraphQLCodegen\Generators\TypesGenerator;
use GraphQLCodegen\Schema\SchemaParser;
use GraphQLCodegen\Schema\TypeMapper;
use GraphQLCodegen\Support\FileWriter;
use GraphQLCodegen\Support\NamespaceResolver;
use GraphQLCodegen\Support\StubsFinder;

class App
{
    public function run(array $argv): int
    {
        if (count($argv) < 3 || $argv[1] !== 'generate') {
            fwrite(STDERR, "Usage: graphql-codegen generate <schema.graphql> <output-dir>\n");

            return 1;
        }

        $schemaPath = $argv[2];
        $outputDir = $argv[3] ?? 'GraphQL';

        $this->generate($schemaPath, $outputDir);

        return 0;
    }

    public function generate(string $schemaPath, string $outputDir): void
    {
        $parser = new SchemaParser($schemaPath);
        $schema = $parser->parse();

        $stubsDir = StubsFinder::find();
        $baseNamespace = NamespaceResolver::resolveBaseNamespace($outputDir);

        // Создаем общие зависимости
        $typeMapper = new TypeMapper;
        $fileWriter = new FileWriter;

        // Генерируем все компоненты
        (new TypesGenerator($fileWriter, $typeMapper))->generate($schema, $outputDir, $stubsDir, $baseNamespace);
        (new InputsGenerator($fileWriter, $typeMapper))->generate($schema, $outputDir, $stubsDir, $baseNamespace);
        (new EnumsGenerator($fileWriter))->generate($schema, $outputDir, $stubsDir, $baseNamespace);
        (new OperationsGenerator($fileWriter, $typeMapper))->generate($schema, $outputDir, $stubsDir, $baseNamespace);
        (new ClientGenerator($fileWriter))->generate($outputDir, $stubsDir, $baseNamespace);

        fwrite(STDOUT, "GraphQL code generated into: {$outputDir}\n");
    }
}
