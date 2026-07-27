<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('article_duplicate_scans', function (Blueprint $table): void {
            // 旧扫描留空并自动视为过期，下一次审批或发送时会重新生成。
            $table->char('corpus_hash', 64)->nullable()->after('content_hash');
        });
    }

    public function down(): void
    {
        Schema::table('article_duplicate_scans', function (Blueprint $table): void {
            $table->dropColumn('corpus_hash');
        });
    }
};
