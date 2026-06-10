<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $customerId = DB::table('saas_customers')->insertGetId([
            'name' => 'Test Customer',
            'slug' => 'test-customer',
            'subdomain' => 'test-customer',
            'custom_domain' => null,
            'theme_key' => 'default',
            'layout_key' => 'default',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        User::factory()->create([
            'customer_id' => $customerId,
            'name' => 'Test User',
            'email' => 'test@example.com',
        ]);
    }
}
