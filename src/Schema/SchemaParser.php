<?php

namespace GraphQLCodegen\Schema;

class SchemaParser
{
    private string $schema;
    private TypeMapper $mapper;

    public function __construct(string $schemaPath)
    {
        if (!is_file($schemaPath)) {
            throw new \RuntimeException("Schema file not found: {$schemaPath}");
        }

        $content = file_get_contents($schemaPath);
        if ($content === false) {
            throw new \RuntimeException("Failed to read schema file: {$schemaPath}");
        }

        $this->schema = $content;
        $this->mapper = new TypeMapper();

        $this->preprocess();
    }

    private function preprocess(): void
    {
        $s = $this->schema;

        // Remove block strings """ ... """
        $s = preg_replace('/"""[\s\S]*?"""/m', '', $s);

        // Remove directives: @directive(...) and @directive
        $s = preg_replace('/@\w+\([^)]*\)/m', '', $s);
        $s = preg_replace('/@\w+/m', '', $s);

        // Remove comments (# ...)
        $s = preg_replace('/#.*$/m', '', $s);

        // Normalize whitespace to simplify parsers
        $s = preg_replace('/\s+/', ' ', $s);

        $this->schema = trim($s);
        
        if (empty($this->schema)) {
            throw new \RuntimeException('Schema file is empty or contains no valid GraphQL definitions');
        }
    }

    /**
     * @return array{
     *  types: array<int,array{name:string,fields:array<int,array{name:string,type:string}>>>,
     *  inputs: array<int,array{name:string,fields:array<int,array{name:string,type:string}>>>,
     *  enums: array<int,array{name:string,values:array<int,string>}>,
     *  query: array<int,array{name:string,args:array<int,array{name:string,type:string}>,returnType:string}>,
     *  mutation: array<int,array{name:string,args:array<int,array{name:string,type:string}>,returnType:string}>,
     *  typeMap: array<string,array<int,array{name:string,type:string}>>
     * }
     */
    public function parse(): array
    {
        $types = $this->parseTypes();
        $inputs = $this->parseInputs();
        $enums = $this->parseEnums();

        $queryFields = $this->parseOperationType('Query');
        $mutationFields = $this->parseOperationType('Mutation');

        $typeMap = [];
        foreach ($types as $t) {
            $typeName = $t['name'] ?? '';
            if (!empty($typeName)) {
                $typeMap[$typeName] = $t['fields'] ?? [];
            }
        }

        return [
            'types'    => $types,
            'inputs'   => $inputs,
            'enums'    => $enums,
            'query'    => $queryFields,
            'mutation' => $mutationFields,
            'typeMap'  => $typeMap,
        ];
    }

    /**
     * @return array<int,array{name:string,fields:array<int,array{name:string,type:string}>>>
     */
    private function parseTypes(): array
    {
        $result = [];

        $definitions = $this->collectDefinitions('type');

        foreach ($definitions as $definition) {
            $name = $definition['name'];

            if (in_array($name, ['Query', 'Mutation', 'Subscription'], true)) {
                continue;
            }

            $body = $definition['body'];
            $fields = [];

            if (preg_match_all('/(\w+)\s*:\s*([!\[\]\w]+)/', $body, $fm, PREG_SET_ORDER)) {
                foreach ($fm as $f) {
                    $fields[] = [
                        'name' => $f[1],
                        'type' => $f[2],
                    ];
                }
            }

            $result[] = [
                'name'   => $name,
                'fields' => $fields,
            ];
        }

        return $result;
    }

    /**
     * @return array<int,array{name:string,fields:array<int,array{name:string,type:string}>>>
     */
    private function parseInputs(): array
    {
        $result = [];

        $definitions = $this->collectDefinitions('input');

        foreach ($definitions as $definition) {
            $name = $definition['name'];
            $body = $definition['body'];
            $fields = [];

            if (preg_match_all('/(\w+)\s*:\s*([!\[\]\w]+)/', $body, $fm, PREG_SET_ORDER)) {
                foreach ($fm as $f) {
                    $fields[] = [
                        'name' => $f[1],
                        'type' => $f[2],
                    ];
                }
            }

            $result[] = [
                'name'   => $name,
                'fields' => $fields,
            ];
        }

        return $result;
    }

    /**
     * @return array<int,array{name:string,values:array<int,string>}>
     */
    private function parseEnums(): array
    {
        $result = [];

        $definitions = $this->collectDefinitions('enum');

        foreach ($definitions as $definition) {
            $name = $definition['name'];
            $body = $definition['body'];

            $values = [];
            if (preg_match_all('/(\w+)/', $body, $vm)) {
                foreach ($vm[1] as $v) {
                    $values[] = $v;
                }
            }

            $result[] = [
                'name'   => $name,
                'values' => $values,
            ];
        }

        return $result;
    }

    /**
     * @return array<int,array{name:string,args:array<int,array{name:string,type:string}>,returnType:string}>
     */
    private function parseOperationType(string $name): array
    {
        $result = [];

        $body = $this->findDefinitionBody('type', $name);
        if ($body === null) {
            return $result;
        }

        // Парсим поля с аргументами, учитывая многострочные аргументы
        // Паттерн: имя_поля (аргументы) : тип_возврата
        if (!preg_match_all('/(\w+)\s*(\([^)]*\))?\s*:\s*([!\[\]\w]+)/', $body, $matches, PREG_SET_ORDER)) {
            return $result;
        }

        foreach ($matches as $f) {
            $fieldName = $f[1];
            $argsDef   = $f[2] ?? '';
            $retType   = $f[3];

            $args = [];

            if ($argsDef) {
                // Убираем скобки
                $argsDef = trim($argsDef, '()');
                
                // Нормализуем пробелы и переносы строк
                $argsDef = preg_replace('/\s+/', ' ', $argsDef);
                
                // Парсим аргументы по паттерну "имя: тип"
                // Аргументы могут быть разделены запятыми или просто пробелами
                // Паттерн: (\w+)\s*:\s*([!\[\]\w]+)
                if (preg_match_all('/(\w+)\s*:\s*([!\[\]\w]+)/', $argsDef, $argMatches, PREG_SET_ORDER)) {
                    foreach ($argMatches as $am) {
                        $args[] = [
                            'name' => $am[1],
                            'type' => $am[2],
                        ];
                    }
                }
            }

            $result[] = [
                'name'       => $fieldName,
                'args'       => $args,
                'returnType' => $retType,
            ];
        }

        return $result;
    }

    public function mapper(): TypeMapper
    {
        return $this->mapper;
    }

    /**
     * @return array<int,array{name:string,body:string}>
     */
    private function collectDefinitions(string $keyword): array
    {
        $result = [];
        $pattern = '/(?:extend\s+)?' . $keyword . '\s+([A-Za-z_][\w]*)[^{]*\{/m';

        if (!preg_match_all($pattern, $this->schema, $matches, PREG_OFFSET_CAPTURE)) {
            return $result;
        }

        foreach ($matches[0] as $idx => $match) {
            $matchStart = $match[1];
            $bracePos = strpos($this->schema, '{', $matchStart);
            if ($bracePos === false) {
                continue;
            }

            $body = $this->extractBlockBody($bracePos);
            if ($body === null) {
                continue;
            }

            $result[] = [
                'name' => $matches[1][$idx][0],
                'body' => $body,
            ];
        }

        return $result;
    }

    private function findDefinitionBody(string $keyword, string $name): ?string
    {
        $pattern = '/(?:extend\s+)?' . $keyword . '\s+' . preg_quote($name, '/') . '[^{]*\{/m';
        if (!preg_match($pattern, $this->schema, $match, PREG_OFFSET_CAPTURE)) {
            return null;
        }

        $bracePos = strpos($this->schema, '{', $match[0][1]);
        if ($bracePos === false) {
            return null;
        }

        return $this->extractBlockBody($bracePos);
    }

    private function extractBlockBody(int $bracePos): ?string
    {
        $len = strlen($this->schema);
        $depth = 0;
        $start = null;

        for ($i = $bracePos; $i < $len; $i++) {
            $ch = $this->schema[$i];

            if ($ch === '{') {
                $depth++;
                if ($depth === 1) {
                    $start = $i + 1;
                }
                continue;
            }

            if ($ch === '}') {
                $depth--;
                if ($depth === 0) {
                    return trim(substr($this->schema, $start, $i - $start));
                }
            }
        }

        return null;
    }
}
