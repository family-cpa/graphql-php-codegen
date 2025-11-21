<?php

namespace GraphQLCodegen\Support;

class StringHelpers
{
    public static function indent(string $text, int $level = 1, string $indent = '    '): string
    {
        $prefix = str_repeat($indent, $level);
        $lines = preg_split('/\R/', $text);
        $lines = array_map(
            fn ($line) => $line !== '' ? $prefix.$line : $line,
            $lines
        );

        return implode("\n", $lines);
    }

    public static function camelCase(string $name): string
    {
        return lcfirst(self::studly($name));
    }

    public static function studly(string $name): string
    {
        return str_replace(' ', '', ucwords(str_replace(['-', '_'], ' ', $name)));
    }
}
