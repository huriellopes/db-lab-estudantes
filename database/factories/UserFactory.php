<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Entities\User;
use App\Services\UserManager;
use App\Support\Role;
use Faker\Factory as FakerFactory;
use Faker\Generator;

/**
 * Factory no estilo do Laravel — gera (e, em create(), persiste de verdade) usuários
 * fake para seeders/testes. create() usa UserManager::provisionNewUser(), então o
 * resultado é um usuário "de verdade": linha em users + conta MySQL real.
 */
final class UserFactory
{
    private readonly Generator $faker;

    public function __construct()
    {
        $this->faker = FakerFactory::create('pt_BR');
    }

    /** @return array{name:string,email:string,username:string,password:string,role:Role} */
    public function definition(array $overrides = []): array
    {
        $username = strtolower((string) preg_replace('/[^a-z0-9]/', '', $this->faker->userName()));
        if ($username === '' || strlen($username) < 3) {
            $username = 'user' . random_int(1000, 9999);
        }

        return array_merge([
            'name' => $this->faker->name(),
            'email' => $this->faker->unique()->safeEmail(),
            'username' => substr($username, 0, 32),
            'password' => 'senha123',
            'role' => Role::Aluno,
        ], $overrides);
    }

    /** @param array{name?:string,email?:string,username?:string,password?:string,role?:Role} $overrides */
    public function create(array $overrides = []): User
    {
        $attributes = $this->definition($overrides);

        return UserManager::provisionNewUser(
            $attributes['name'],
            $attributes['email'],
            $attributes['username'],
            $attributes['password'],
            $attributes['role'],
        );
    }

    /**
     * @param array{name?:string,email?:string,username?:string,password?:string,role?:Role} $overrides
     * @return list<User>
     */
    public function createMany(int $count, array $overrides = []): array
    {
        return array_map(fn (): User => $this->create($overrides), range(1, $count));
    }
}
