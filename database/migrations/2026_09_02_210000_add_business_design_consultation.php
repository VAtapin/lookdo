<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $planId = DB::table('plans')->where('code', 'business')->value('id');
        if ($planId) {
            DB::table('plan_entitlements')->updateOrInsert(
                ['plan_id' => $planId, 'key' => 'design_consultation_included'],
                ['value' => '1', 'created_at' => now(), 'updated_at' => now()],
            );
        }
    }

    public function down(): void
    {
        DB::table('plan_entitlements')->where('key', 'design_consultation_included')->delete();
    }
};
