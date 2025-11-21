<?php

namespace GraphQLCodegen\Contracts;

interface OperationInterface
{
    public function document(): string;
    public function variables(): array;
    public function returnType(): string;
    public function graphQLReturnType(): string;
    public function baseNamespace(): string;
    public function fieldName(): string;
}

