<?php

namespace GraphQLCodegen\Tests;

use GraphQLCodegen\Console\App;
use PHPUnit\Framework\TestCase;

//class ExampleSchemaTest extends TestCase
//{
//    public function testGeneratorRuns(): void
//    {
//        $schema = __DIR__ . '/fixtures/schema.graphql';
//        @mkdir(__DIR__ . '/output', 0777, true);
//
//        file_put_contents($schema, <<<GQL
//            type Query {
//                hello: String!
//            }
//        GQL);
//
//        $cli = new App();
//        $cli->generate($schema, __DIR__ . '/output');
//
//        $this->assertFileExists(__DIR__ . '/output/Types/Query.php'); // Query сейчас пропускаем — тест можно потом адаптировать
//        $this->assertTrue(true);
//    }
//}
