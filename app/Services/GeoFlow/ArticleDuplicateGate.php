<?php

namespace App\Services\GeoFlow;

use App\Exceptions\ArticleDuplicateGateException;
use App\Models\Admin;
use App\Models\Article;
use App\Models\ArticleDuplicateScan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ArticleDuplicateGate
{
    public function __construct(private readonly ArticleDuplicateDetector $detector) {}

    public function check(
        Article $article,
        string $trigger,
        ?int $adminId = null,
        ?string $overrideReason = null,
        bool $allowExistingOverride = true,
    ): ArticleDuplicateScan {
        $result = DB::transaction(function () use ($article, $trigger, $adminId, $overrideReason, $allowExistingOverride): ArticleDuplicateScan|ArticleDuplicateGateException {
            $lockedArticle = Article::query()
                ->whereKey($article->getKey())
                ->lockForUpdate()
                ->firstOrFail();
            $scan = $lockedArticle->latestDuplicateScan()->first();

            if ($scan === null || ! $this->detector->isFresh($lockedArticle, $scan)) {
                $scan = $this->detector->record($lockedArticle, $trigger, $adminId);
            }

            $lockedArticle->setRelation('latestDuplicateScan', $scan);

            if ($scan->status === 'clean') {
                return $scan;
            }

            if ($allowExistingOverride && $scan->status === 'warning' && $scan->is_overridden) {
                return $scan;
            }

            $reason = $this->normalizeOverrideReason($overrideReason);
            $admin = $adminId === null ? null : Admin::query()->find($adminId);

            if ($allowExistingOverride && $scan->status === 'warning' && $reason !== '' && $admin !== null) {
                ArticleDuplicateScan::query()
                    ->whereKey($scan->getKey())
                    ->where('is_overridden', false)
                    ->update([
                        'is_overridden' => true,
                        'override_reason' => $reason,
                        'overridden_by_admin_id' => $admin->getKey(),
                        'overridden_at' => now(),
                    ]);

                $latestScan = $lockedArticle->latestDuplicateScan()->first();
                if ($latestScan === null || ! $latestScan->is($scan) || ! $this->detector->isFresh($lockedArticle, $latestScan) || ! $latestScan->is_overridden) {
                    throw new ArticleDuplicateGateException($scan);
                }

                return $latestScan;
            }

            return new ArticleDuplicateGateException($scan);
        });

        if ($result instanceof ArticleDuplicateGateException) {
            throw $result;
        }

        return $result;
    }

    private function normalizeOverrideReason(?string $reason): string
    {
        $reason = $reason ?? '';
        if (class_exists(\Normalizer::class)) {
            $reason = \Normalizer::normalize($reason, \Normalizer::FORM_KC) ?: $reason;
        }
        $reason = preg_replace('/[\p{Default_Ignorable_Code_Point}\p{Cc}\p{Cf}]+/u', '', $reason) ?? $reason;
        $reason = Str::squish($reason);

        return preg_match('/[\p{L}\p{N}\p{P}\p{S}]/u', $reason) === 1 ? $reason : '';
    }
}
