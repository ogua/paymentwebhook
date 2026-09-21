<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('webhook_events', function (Blueprint $table) {
            $table->id();
            $table->string('provider')->default('paystack');
            $table->string('event');
            $table->string('paystack_id')->nullable();
            $table->string('reference')->nullable()->index();
            $table->string('prefix')->nullable();
            $table->string('project')->nullable();
            $table->boolean('signature_valid')->default(false);
            $table->json('payload');
            $table->string('status')->default('pending');
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->text('last_error')->nullable();
            $table->timestamp('forwarded_at')->nullable();
            $table->timestamps();

            $table->unique(['event', 'paystack_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('webhook_events');
    }
};
