<?php

namespace GraphQLCodegen;

abstract class Operation
{
    private ?string $customSelectionSet = null;

    public string $type;

    public string $graphqlType;

    public string $namespace;

    public string $operation;

    abstract public function document(): string;

    abstract public function variables(): array;

    abstract protected function getDefaultSelectionSet(): string;

    public function withSelectionSet(string $selectionSet): self
    {
        $this->customSelectionSet = $selectionSet;

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
}

