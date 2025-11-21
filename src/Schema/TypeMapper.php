<?php

namespace GraphQLCodegen\Schema;

class TypeMapper
{
    private array $scalarMap = [
        'UUID' => 'string',
        'ID' => 'string',
        'String' => 'string',
        'Int' => 'int',
        'Float' => 'float',
        'Boolean' => 'bool',
        'Time' => 'string',
        'Any' => 'mixed',
        'Upload' => 'string',
        'Cursor' => 'string',
    ];

    private array $scalars;

    public function __construct()
    {
        $this->scalars = array_keys($this->scalarMap);
    }

    public function isScalar(string $gqlType): bool
    {
        return in_array($gqlType, $this->scalars, true);
    }

    /**
     * @return array{php:string,nullable:bool,isList:bool,base:string}
     */
    public function map(string $raw): array
    {
        $nullable = ! str_ends_with($raw, '!');
        $clean = rtrim($raw, '!');
        $isList = false;

        if (str_starts_with($clean, '[')) {
            $isList = true;
            $clean = trim($clean, '[]');
            $clean = rtrim($clean, '!');
        }

        $base = $clean;
        $php = $this->scalarMap[$base] ?? $base;

        if ($isList) {
            $phpType = 'array';
        } else {
            $phpType = $php;
        }

        return [
            'php' => $phpType,
            'nullable' => $nullable,
            'isList' => $isList,
            'base' => $base,
        ];
    }

    public function scalarMap(): array
    {
        return $this->scalarMap;
    }
}
