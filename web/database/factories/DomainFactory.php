<?php

namespace Database\Factories;

use App\Models\Domain;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Domain> */
class DomainFactory extends Factory
{
    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'name' => $this->faker->unique()->domainName(),
            'status' => Domain::STATUS_PENDING,
        ];
    }
}
