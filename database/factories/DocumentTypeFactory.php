<?php

namespace Database\Factories;

use App\Enums\Confidentiality;
use Illuminate\Database\Eloquent\Factories\Factory;

class DocumentTypeFactory extends Factory
{
    public function definition(): array
    {
        return [
            'code' => strtoupper(fake()->unique()->lexify('???')),
            'name' => 'Type '.fake()->unique()->word(),
            'retention_years' => 10,
            'default_confidentiality' => Confidentiality::Department,
        ];
    }
}
