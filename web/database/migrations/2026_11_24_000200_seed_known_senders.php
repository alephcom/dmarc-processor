<?php

use Database\Seeders\KnownSenderSeeder;
use Illuminate\Database\Migrations\Migration;

// Seed the starter catalogue as part of migrating, so every install has it.
return new class extends Migration
{
    public function up(): void
    {
        (new KnownSenderSeeder)->run();
    }

    public function down(): void {}
};
