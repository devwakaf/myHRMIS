<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Global reference data (no organisation_id; Super Admin only): banks, public holiday catalogue.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('banks', function (Blueprint $table) {
            $table->id();
            $table->string('code', 20)->unique();
            $table->string('name');
            $table->string('swift_code', 11)->nullable();
            $table->string('bank_file_code', 20)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('public_holidays', function (Blueprint $table) {
            $table->id();
            $table->unsignedSmallInteger('year');
            $table->date('date');
            $table->string('name_ms');
            $table->string('name_en')->nullable();
            $table->string('scope', 10); // 'federal' or a MalaysianState code
            $table->boolean('is_mandatory')->default(false);
            $table->string('source_ref')->nullable();
            $table->timestamps();
            $table->unique(['date', 'scope', 'name_ms']);
            $table->index(['year', 'scope']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('public_holidays');
        Schema::dropIfExists('banks');
    }
};
