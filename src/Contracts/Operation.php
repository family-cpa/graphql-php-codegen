<?php

namespace GraphQLCodegen\Contracts;

interface Operation
{
    public function document(): string;

    public function variables(): array;

    public function type(): string;

    public function graphqlType(): string;

    public function namespace(): string;

    public function operation(): string;
}
