<?php

namespace GraphQLCodegen;

abstract class Operation
{
    private ?string $customSelectionSet = null;

    abstract public function document(): string;

    abstract public function variables(): array;

    abstract public function type(): string;

    abstract public function graphqlType(): string;

    abstract public function namespace(): string;

    abstract public function operation(): string;

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

