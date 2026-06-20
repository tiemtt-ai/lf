<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('saas_customers', function (Blueprint $table) {
            $table->string('organization_type', 50)->nullable()->after('phone');
        });

        $allowedOrganizationTypes = [
            'training_center',
            'school',
            'corporate',
            'individual',
        ];

        DB::table('saas_customers')
            ->select(['id', 'metadata'])
            ->orderBy('id')
            ->chunkById(100, function ($customers) use ($allowedOrganizationTypes) {
                foreach ($customers as $customer) {
                    $metadata = json_decode($customer->metadata ?? '[]', true) ?: [];
                    $organizationType = $metadata['organization_type'] ?? null;

                    unset($metadata['organization_type']);

                    DB::table('saas_customers')
                        ->where('id', $customer->id)
                        ->update([
                            'organization_type' => in_array($organizationType, $allowedOrganizationTypes, true)
                                ? $organizationType
                                : null,
                            'metadata' => $metadata === [] ? null : json_encode($metadata),
                        ]);
                }
            });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::table('saas_customers')
            ->select(['id', 'organization_type', 'metadata'])
            ->whereNotNull('organization_type')
            ->orderBy('id')
            ->chunkById(100, function ($customers) {
                foreach ($customers as $customer) {
                    $metadata = json_decode($customer->metadata ?? '[]', true) ?: [];
                    $metadata['organization_type'] = $customer->organization_type;

                    DB::table('saas_customers')
                        ->where('id', $customer->id)
                        ->update([
                            'metadata' => json_encode($metadata),
                        ]);
                }
            });

        Schema::table('saas_customers', function (Blueprint $table) {
            $table->dropColumn('organization_type');
        });
    }
};
