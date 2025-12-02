<?php

namespace Tests;

use GraphQLCodegen\Console\App;
use PHPUnit\Framework\TestCase;

class GenerateSchemaTest extends TestCase
{
    private string $outputDir;

    protected function setUp(): void
    {
        $this->outputDir = __DIR__.'/Output';
        @mkdir($this->outputDir, 0777, true);
    }

    public function test_generator_runs(): void
    {
        $schema = __DIR__.'/fixtures/schema_old.graphql';

        $cli = new App;
        $cli->generate($schema, $this->outputDir);

        $this->assertFileExists($this->outputDir.'/Types/User.php');
    }

    public function test_types_are_generated(): void
    {
        $schema = __DIR__.'/fixtures/schema_old.graphql';
        $cli = new App;
        $cli->generate($schema, $this->outputDir);

        $userTypePath = $this->outputDir.'/Types/User.php';
        $this->assertFileExists($userTypePath);

        $content = file_get_contents($userTypePath);
        $this->assertStringContainsString('class User', $content);
        $this->assertStringContainsString('public static function tryFrom', $content);
        $this->assertStringContainsString('@property', $content);
        $this->assertStringContainsString('@property string $id', $content);
        $this->assertStringContainsString('@property string $name', $content);
        $this->assertStringContainsString('@property string|null $picture', $content);
        $this->assertStringContainsString('use Tests\Output\Enums\Role', $content);
    }

    public function test_enums_are_generated(): void
    {
        $schema = __DIR__.'/fixtures/schema_old.graphql';
        $cli = new App;
        $cli->generate($schema, $this->outputDir);

        $roleEnumPath = $this->outputDir.'/Enums/Role.php';
        $this->assertFileExists($roleEnumPath);

        $content = file_get_contents($roleEnumPath);
        $this->assertStringContainsString('enum Role', $content);
        $this->assertStringContainsString('case DEFAULT', $content);
        $this->assertStringContainsString('case ADMIN', $content);
    }

    public function test_inputs_are_generated(): void
    {
        $schema = __DIR__.'/fixtures/schema_old.graphql';
        $cli = new App;
        $cli->generate($schema, $this->outputDir);

        $inputPath = $this->outputDir.'/Inputs/CreateUserInput.php';
        $this->assertFileExists($inputPath);

        $content = file_get_contents($inputPath);
        $this->assertStringContainsString('class CreateUserInput', $content);
        $this->assertStringContainsString('public function __construct', $content);
        $this->assertStringContainsString('public function toArray', $content);
        $this->assertStringContainsString('public string $name', $content);
        $this->assertStringContainsString('public string $email', $content);
        $this->assertStringContainsString('public ?string $picture', $content);
        $this->assertStringContainsString('use Tests\Output\Enums\Role', $content);
    }

    public function test_queries_are_generated(): void
    {
        $schema = __DIR__.'/fixtures/schema_old.graphql';
        $cli = new App;
        $cli->generate($schema, $this->outputDir);

        $userQueryPath = $this->outputDir.'/Operations/Query/UserQuery.php';
        $this->assertFileExists($userQueryPath);

        $content = file_get_contents($userQueryPath);
        $this->assertStringContainsString('class UserQuery', $content);
        $this->assertStringContainsString('extends Operation', $content);
        $this->assertStringContainsString('public function document', $content);
        $this->assertStringContainsString('public function variables', $content);
        $this->assertStringContainsString('public string $type', $content);
        $this->assertStringContainsString('public string $graphqlType', $content);
        $this->assertStringContainsString('public string $operation', $content);
        $this->assertStringContainsString('query User', $content);
        $this->assertStringContainsString('user(id: $id)', $content);

        $usersQueryPath = $this->outputDir.'/Operations/Query/UsersQuery.php';
        $this->assertFileExists($usersQueryPath);

        $usersContent = file_get_contents($usersQueryPath);
        $this->assertStringContainsString('class UsersQuery', $usersContent);
        $this->assertStringContainsString("public string \$type = 'array'", $usersContent);
        $this->assertStringContainsString("public string \$graphqlType = '[User!]'", $usersContent);
    }

    public function test_mutations_are_generated(): void
    {
        $schema = __DIR__.'/fixtures/schema_old.graphql';
        $cli = new App;
        $cli->generate($schema, $this->outputDir);

        $mutationPath = $this->outputDir.'/Operations/Mutation/CreateUserMutation.php';
        $this->assertFileExists($mutationPath);

        $content = file_get_contents($mutationPath);
        $this->assertStringContainsString('class CreateUserMutation', $content);
        $this->assertStringContainsString('extends Operation', $content);
        $this->assertStringContainsString('mutation CreateUser', $content);
        $this->assertStringContainsString('createUser(input: $input)', $content);
        $this->assertStringContainsString('use Tests\Output\Inputs\CreateUserInput', $content);
    }

    public function test_client_is_generated(): void
    {
        $schema = __DIR__.'/fixtures/schema_old.graphql';
        $cli = new App;
        $cli->generate($schema, $this->outputDir);

        $clientPath = $this->outputDir.'/Client.php';
        $this->assertFileExists($clientPath);

        $content = file_get_contents($clientPath);
        $this->assertStringContainsString('class Client', $content);
        $this->assertStringContainsString('extends GraphQLClient', $content);
        $this->assertStringContainsString('public function __construct', $content);
    }

    public function test_operation_methods_work(): void
    {
        $schema = __DIR__.'/fixtures/schema_old.graphql';
        $cli = new App;
        $cli->generate($schema, $this->outputDir);

        require_once $this->outputDir.'/Operations/Query/UserQuery.php';

        $query = new \Tests\Output\Operations\Query\UserQuery('123');
        $this->assertInstanceOf(\GraphQLCodegen\Operation::class, $query);
        $this->assertStringContainsString('query User', $query->document());
        $this->assertStringContainsString('user(id: $id)', $query->document());
        $this->assertEquals(['id' => '123'], $query->variables());
        $this->assertEquals(\Tests\Output\Types\User::class, $query->type);
        $this->assertEquals('User!', $query->graphqlType);
        $this->assertEquals('user', $query->operation);
    }

    public function test_type_from_array_works(): void
    {
        $schema = __DIR__.'/fixtures/schema_old.graphql';
        $cli = new App;
        $cli->generate($schema, $this->outputDir);

        require_once $this->outputDir.'/Types/User.php';
        require_once $this->outputDir.'/Enums/Role.php';

        $data = [
            'id' => '123',
            'name' => 'John Doe',
            'email' => 'john@example.com',
            'picture' => 'https://example.com/pic.jpg',
            'role' => 'ADMIN',
        ];

        $user = \Tests\Output\Types\User::tryFrom($data);
        $this->assertEquals('123', $user->id);
        $this->assertEquals('John Doe', $user->name);
        $this->assertEquals('john@example.com', $user->email);
        $this->assertEquals('https://example.com/pic.jpg', $user->picture);
        $this->assertInstanceOf(\Tests\Output\Enums\Role::class, $user->role);
        $this->assertEquals(\Tests\Output\Enums\Role::ADMIN, $user->role);
    }

    public function test_type_from_array_with_null(): void
    {
        $schema = __DIR__.'/fixtures/schema_old.graphql';
        $cli = new App;
        $cli->generate($schema, $this->outputDir);

        require_once $this->outputDir.'/Types/User.php';
        require_once $this->outputDir.'/Enums\Role.php';

        $data = [
            'id' => '123',
            'name' => 'John Doe',
            'email' => 'john@example.com',
            'role' => 'DEFAULT',
        ];

        $user = \Tests\Output\Types\User::tryFrom($data);
        $this->assertNull($user->picture);
    }

    public function test_input_to_array_works(): void
    {
        $schema = __DIR__.'/fixtures/schema_old.graphql';
        $cli = new App;
        $cli->generate($schema, $this->outputDir);

        require_once $this->outputDir.'/Inputs/CreateUserInput.php';
        require_once $this->outputDir.'/Enums/Role.php';

        $input = new \Tests\Output\Inputs\CreateUserInput(
            'John Doe',
            'john@example.com',
            'https://example.com/pic.jpg',
            \Tests\Output\Enums\Role::ADMIN
        );

        $array = $input->toArray();
        $this->assertEquals('John Doe', $array['name']);
        $this->assertEquals('john@example.com', $array['email']);
        $this->assertEquals('https://example.com/pic.jpg', $array['picture']);
        $this->assertEquals(\Tests\Output\Enums\Role::ADMIN, $array['role']);
    }

    public function test_input_to_array_filters_nulls(): void
    {
        $schema = __DIR__.'/fixtures/schema_old.graphql';
        $cli = new App;
        $cli->generate($schema, $this->outputDir);

        require_once $this->outputDir.'/Inputs/CreateUserInput.php';
        require_once $this->outputDir.'/Enums/Role.php';

        $input = new \Tests\Output\Inputs\CreateUserInput(
            'John Doe',
            'john@example.com',
            null,
            \Tests\Output\Enums\Role::ADMIN
        );

        $array = $input->toArray();
        $this->assertArrayNotHasKey('picture', $array);
    }

    public function test_list_type_operation(): void
    {
        $schema = __DIR__.'/fixtures/schema_old.graphql';
        $cli = new App;
        $cli->generate($schema, $this->outputDir);

        require_once $this->outputDir.'/Operations/Query/UsersQuery.php';

        $query = new \Tests\Output\Operations\Query\UsersQuery(10, null);
        $this->assertEquals('array', $query->type);
        $this->assertEquals('[User!]', $query->graphqlType);
    }

    public function test_mutation_with_input(): void
    {
        $schema = __DIR__.'/fixtures/schema_old.graphql';
        $cli = new App;
        $cli->generate($schema, $this->outputDir);

        require_once $this->outputDir.'/Operations/Mutation/CreateUserMutation.php';
        require_once $this->outputDir.'/Inputs/CreateUserInput.php';
        require_once $this->outputDir.'/Enums/Role.php';

        $input = new \Tests\Output\Inputs\CreateUserInput(
            'John Doe',
            'john@example.com',
            'https://example.com/pic.jpg',
            \Tests\Output\Enums\Role::ADMIN
        );

        $mutation = new \Tests\Output\Operations\Mutation\CreateUserMutation($input);
        $this->assertInstanceOf(\GraphQLCodegen\Operation::class, $mutation);
        $this->assertStringContainsString('mutation CreateUser', $mutation->document());
        $this->assertStringContainsString('createUser(input: $input)', $mutation->document());

        $variables = $mutation->variables();
        $this->assertArrayHasKey('input', $variables);
        $this->assertIsArray($variables['input']);
        $this->assertEquals('John Doe', $variables['input']['name']);
        $this->assertEquals('john@example.com', $variables['input']['email']);
        $this->assertEquals('https://example.com/pic.jpg', $variables['input']['picture']);
        $this->assertInstanceOf(\Tests\Output\Enums\Role::class, $variables['input']['role']);
        $this->assertEquals(\Tests\Output\Enums\Role::ADMIN, $variables['input']['role']);

        $this->assertEquals(\Tests\Output\Types\User::class, $mutation->type);
        $this->assertEquals('User', $mutation->graphqlType);
        $this->assertEquals('createUser', $mutation->operation);
    }

    public function test_type_from_array_with_list(): void
    {
        $schema = __DIR__.'/fixtures/schema_old.graphql';
        $cli = new App;
        $cli->generate($schema, $this->outputDir);

        require_once $this->outputDir.'/Types/User.php';
        require_once $this->outputDir.'/Enums/Role.php';

        $data = [
            [
                'id' => '1',
                'name' => 'User 1',
                'email' => 'user1@example.com',
                'role' => 'ADMIN',
            ],
            [
                'id' => '2',
                'name' => 'User 2',
                'email' => 'user2@example.com',
                'picture' => 'pic.jpg',
                'role' => 'DEFAULT',
            ],
        ];

        $users = array_map(fn ($item) => \Tests\Output\Types\User::tryFrom($item), $data);
        $this->assertCount(2, $users);
        $this->assertEquals('User 1', $users[0]->name);
        $this->assertEquals('User 2', $users[1]->name);
        $this->assertInstanceOf(\Tests\Output\Enums\Role::class, $users[0]->role);
        $this->assertEquals(\Tests\Output\Enums\Role::ADMIN, $users[0]->role);
        $this->assertEquals(\Tests\Output\Enums\Role::DEFAULT, $users[1]->role);
    }

    public function test_enum_values(): void
    {
        $schema = __DIR__.'/fixtures/schema_old.graphql';
        $cli = new App;
        $cli->generate($schema, $this->outputDir);

        require_once $this->outputDir.'/Enums/Role.php';

        $this->assertEquals('DEFAULT', \Tests\Output\Enums\Role::DEFAULT->value);
        $this->assertEquals('ADMIN', \Tests\Output\Enums\Role::ADMIN->value);
        $this->assertEquals(\Tests\Output\Enums\Role::DEFAULT, \Tests\Output\Enums\Role::tryFrom('DEFAULT'));
        $this->assertEquals(\Tests\Output\Enums\Role::ADMIN, \Tests\Output\Enums\Role::tryFrom('ADMIN'));
        $this->assertNull(\Tests\Output\Enums\Role::tryFrom('INVALID'));
    }

    public function test_query_with_nullable_argument(): void
    {
        $schema = __DIR__.'/fixtures/schema_old.graphql';
        $cli = new App;
        $cli->generate($schema, $this->outputDir);

        require_once $this->outputDir.'/Operations/Query/UsersQuery.php';

        $query = new \Tests\Output\Operations\Query\UsersQuery(10, null);
        $variables = $query->variables();
        $this->assertEquals(['first' => 10], $variables);
        $this->assertArrayNotHasKey('perPage', $variables);

        $queryWithPerPage = new \Tests\Output\Operations\Query\UsersQuery(10, 20);
        $variablesWithPerPage = $queryWithPerPage->variables();
        $this->assertEquals(['first' => 10, 'perPage' => 20], $variablesWithPerPage);
    }

    public function test_generated_code_is_valid(): void
    {
        $schema = __DIR__.'/fixtures/schema_old.graphql';
        $cli = new App;
        $cli->generate($schema, $this->outputDir);

        $files = [
            $this->outputDir.'/Types/User.php',
            $this->outputDir.'/Enums/Role.php',
            $this->outputDir.'/Inputs/CreateUserInput.php',
            $this->outputDir.'/Operations/Query/UserQuery.php',
            $this->outputDir.'/Operations/Query/UsersQuery.php',
            $this->outputDir.'/Operations/Mutation/CreateUserMutation.php',
            // $this->outputDir.'/Client.php',
        ];

        foreach ($files as $file) {
            $this->assertFileExists($file);
            $content = file_get_contents($file);
            $this->assertStringContainsString('This file is auto-generated', $content);
        }
    }
}
