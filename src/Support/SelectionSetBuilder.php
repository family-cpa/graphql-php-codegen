<?php

namespace GraphQLCodegen\Support;

class SelectionSetBuilder
{
    public static function fromFields(array $fields): string
    {
        if (empty($fields)) {
            return ' {}';
        }

        $lines = [];
        foreach ($fields as $key => $value) {
            // Если ключ - строка (константа) и значение - массив, значит это вложенное поле
            // Например: User::COUNTRY => [Country::ID, Country::NAME]
            if (is_string($key) && is_array($value)) {
                $fieldName = $key;
                $nestedFields = $value;
                $nestedSelection = self::fromFields($nestedFields);
                $lines[] = '    '.$fieldName.trim($nestedSelection);
            }
            // Если значение - строка (константа или имя поля), это простое поле
            // Например: User::ID, User::NAME
            elseif (is_string($value)) {
                $lines[] = '    '.$value;
            }
        }

        return " {\n".implode("\n", $lines)."\n    }";
    }
}

