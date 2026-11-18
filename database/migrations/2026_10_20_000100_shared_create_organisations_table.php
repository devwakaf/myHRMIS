<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('organisations', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->string('short_code', 10)->unique();
            $table->string('registration_type', 32);
            $table->string('ssm_no', 20)->nullable();
            $table->string('ssm_old_no', 20)->nullable();
            $table->string('kwsp_employer_no', 20)->nullable();
            $table->string('perkeso_employer_code', 12)->nullable();
            $table->string('lhdn_employer_no', 10)->nullable();
            $table->string('lhdn_hq_employer_no', 10)->nullable();
            $table->string('tax_identification_no', 20)->nullable();
            $table->boolean('hrd_corp_registered')->default(false);
            $table->decimal('hrd_corp_levy_rate', 9, 4)->nullable();
            $table->string('address_line1');
            $table->string('address_line2')->nullable();
            $table->string('address_line3')->nullable();
            $table->char('postcode', 5);
            $table->string('city');
            $table->string('state', 32);
            $table->string('phone')->nullable();
            $table->string('email')->nullable();
            $table->string('website')->nullable();
            $table->string('logo_path')->nullable();
            $table->string('daily_rate_basis', 32)->default('fixed_divisor');
            $table->decimal('daily_rate_divisor', 5, 2)->default(26);
            $table->boolean('is_active')->default(true);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::table('users', function (Blueprint $table) {
            $table->foreignId('current_organisation_id')->nullable()->after('locale')
                ->constrained('organisations')->nullOnDelete();
        });

        Schema::create('document_sequences', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organisation_id')->constrained()->restrictOnDelete();
            $table->string('sequence_key', 40);
            $table->unsignedSmallInteger('year');
            $table->unsignedInteger('next_value')->default(1);
            $table->timestamps();
            $table->unique(['organisation_id', 'sequence_key', 'year']);
        });

        Schema::create('reminder_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organisation_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('employee_id')->default(0); // 0 = not employee-specific (keeps the unique key effective)
            $table->string('kind', 60);
            $table->string('reference', 60)->default('');
            $table->date('reference_date');
            $table->timestamp('created_at')->nullable();
            $table->unique(['organisation_id', 'employee_id', 'kind', 'reference', 'reference_date'], 'reminder_logs_unique');
        });

        Schema::table(config('activitylog.table_name', 'activity_log'), function (Blueprint $table) {
            $table->unsignedBigInteger('organisation_id')->nullable()->after('id')->index();
        });

        Schema::create('notifications', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('type');
            $table->morphs('notifiable');
            $table->text('data');
            $table->timestamp('read_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notifications');
        Schema::table(config('activitylog.table_name', 'activity_log'), function (Blueprint $table) {
            $table->dropColumn('organisation_id');
        });
        Schema::dropIfExists('reminder_logs');
        Schema::dropIfExists('document_sequences');
        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('current_organisation_id');
        });
        Schema::dropIfExists('organisations');
    }
};
