<?php

namespace GraphQLCodegen;

use GraphQLCodegen\Support\SelectionSetBuilder;

abstract class Operation
{
    private ?string $customSelectionSet = null;

    public string $type;

    public string $graphqlType;

    public string $operation;

    abstract public function document(): string;

    abstract public function variables(): array;

    abstract protected function getDefaultSelectionSet(): string;

    public function withSelectionSet(array $fields): self
    {
        $this->customSelectionSet = SelectionSetBuilder::fromFields($fields);

        return $this;
    }

    public function selectionSet(): string
    {
        if ($this->customSelectionSet !== null) {
            return $this->customSelectionSet;
        }

        return $this->getDefaultSelectionSet();
    }

    public function hasCustomSelectionSet(): bool
    {
        return $this->customSelectionSet !== null;
    }

    /**
     * Рекурсивно удаляет пустые объекты (ассоциативные массивы) из структуры данных
     * Пустые списки (индексированные массивы) также удаляются
     * 
     * @param mixed $data
     * @return mixed
     */
    protected function filterEmptyObjects(mixed $data): mixed
    {
        if (! is_array($data)) {
            return $data;
        }

        $result = [];
        foreach ($data as $key => $value) {
            if ($value === null) {
                continue;
            }

            if (is_array($value)) {
                $filtered = $this->filterEmptyObjects($value);
                // Пропускаем пустые массивы (и объекты, и списки)
                if (! empty($filtered)) {
                    $result[$key] = $filtered;
                }
            } else {
                $result[$key] = $value;
            }
        }

        return $result;
    }
}
