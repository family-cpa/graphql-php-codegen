<?php

namespace GraphQLCodegen\Tests;

use GraphQLCodegen\Console\App;
use PHPUnit\Framework\TestCase;

class DebugSchemaTest extends TestCase
{
    public function testGeneratorRuns(): void
    {
        $schema = __DIR__ . '/fixtures/debug.graphql';
        @mkdir(__DIR__ . '/output', 0777, true);

        $cli = new App();
        $cli->generate($schema, __DIR__ . '/output');
    }
}
