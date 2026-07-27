<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_exposure_platform_configs', function (Blueprint $table): void {
            $table->id();
            $table->string('platform', 32)->unique();
            $table->foreignId('ai_model_id')->nullable()->constrained('ai_models')->nullOnDelete();
            $table->boolean('enabled')->default(false)->index();
            $table->foreignId('updated_by_admin_id')->nullable()->constrained('admins')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('ai_exposure_monitors', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('article_id')->constrained('articles')->cascadeOnDelete();
            $table->string('query', 500);
            $table->string('frequency', 20)->default('daily')->index();
            $table->string('status', 20)->default('active')->index();
            $table->timestamp('last_queued_at')->nullable();
            $table->timestamp('last_completed_at')->nullable();
            $table->timestamp('next_run_at')->nullable()->index();
            $table->foreignId('created_by_admin_id')->nullable()->constrained('admins')->nullOnDelete();
            $table->timestamps();

            $table->index(['status', 'next_run_at'], 'ai_exposure_monitors_due_index');
        });

        Schema::create('ai_exposure_runs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('ai_exposure_monitor_id')->constrained('ai_exposure_monitors')->cascadeOnDelete();
            $table->string('dispatch_key', 160)->unique();
            $table->string('status', 20)->default('queued')->index();
            $table->unsignedSmallInteger('platform_total')->default(0);
            $table->unsignedSmallInteger('platform_succeeded')->default(0);
            $table->unsignedSmallInteger('mentioned_count')->default(0);
            $table->unsignedSmallInteger('cited_count')->default(0);
            $table->text('error_message')->nullable();
            $table->timestamp('scheduled_for')->nullable()->index();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->index(['ai_exposure_monitor_id', 'status'], 'ai_exposure_runs_monitor_status_index');
            $table->index(['status', 'started_at'], 'ai_exposure_runs_status_started_index');
        });

        Schema::create('ai_exposure_results', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('ai_exposure_run_id')->constrained('ai_exposure_runs')->cascadeOnDelete();
            $table->string('platform', 32);
            $table->foreignId('ai_model_id')->nullable()->constrained('ai_models')->nullOnDelete();
            $table->string('status', 20)->default('pending')->index();
            $table->boolean('mentioned')->default(false)->index();
            $table->boolean('cited')->default(false)->index();
            $table->longText('answer_text')->nullable();
            $table->json('cited_urls')->nullable();
            $table->json('matched_sources')->nullable();
            $table->json('response_meta')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamp('checked_at')->nullable()->index();
            $table->timestamps();

            $table->unique(['ai_exposure_run_id', 'platform'], 'ai_exposure_results_run_platform_unique');
            $table->index(['platform', 'checked_at'], 'ai_exposure_results_platform_checked_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_exposure_results');
        Schema::dropIfExists('ai_exposure_runs');
        Schema::dropIfExists('ai_exposure_monitors');
        Schema::dropIfExists('ai_exposure_platform_configs');
    }
};
