<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('article_duplicate_scans', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('article_id')->constrained('articles')->cascadeOnDelete();
            $table->string('status', 20)->index();
            $table->decimal('max_similarity', 6, 5)->default(0);
            $table->foreignId('matched_article_id')->nullable()->constrained('articles')->nullOnDelete();
            $table->json('matches');
            $table->char('content_hash', 64);
            $table->string('algorithm_version', 30);
            $table->string('trigger', 30);
            $table->foreignId('admin_id')->nullable()->constrained('admins')->nullOnDelete();
            $table->boolean('is_overridden')->default(false)->index();
            $table->text('override_reason')->nullable();
            $table->foreignId('overridden_by_admin_id')->nullable()->constrained('admins')->nullOnDelete();
            $table->timestamp('overridden_at')->nullable();
            $table->timestamp('scanned_at');
            $table->timestamps();

            $table->index(['article_id', 'scanned_at']);
            $table->index(['status', 'scanned_at']);
            $table->index('content_hash');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('article_duplicate_scans');
    }
};
