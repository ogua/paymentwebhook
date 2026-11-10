<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('webhook_events', function (Blueprint $table) {
            // "live" or "test": which Paystack key signed the event. Null when
            // neither did (the row is then a failed signature).
            $table->string('mode', 8)->nullable()->after('signature_valid');
        });
    }

    public function down(): void
    {
        Schema::table('webhook_events', function (Blueprint $table) {
            $table->dropColumn('mode');
        });
    }
};
