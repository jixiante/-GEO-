<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreAiExposureMonitorRequest;
use App\Http\Requests\Admin\UpdateAiExposureMonitorRequest;
use App\Http\Requests\Admin\UpdateAiExposurePlatformsRequest;
use App\Models\AiExposureMonitor;
use App\Models\AiExposurePlatformConfig;
use App\Models\AiExposureResult;
use App\Models\Article;
use App\Services\Admin\AiExposureDashboardService;
use App\Services\AiExposure\AiExposureRunDispatcher;
use App\Services\AiExposure\AiExposureSourceResolver;
use App\Support\AdminWeb;
use App\Support\AiExposure\AiExposurePlatformCatalog;
use DomainException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class AiExposureController extends Controller
{
    public function __construct(
        private readonly AiExposureDashboardService $dashboardService,
        private readonly AiExposureSourceResolver $sourceResolver,
        private readonly AiExposureRunDispatcher $runDispatcher,
    ) {}

    public function index(Request $request): View
    {
        return view('admin.ai-exposure.index', [
            'pageTitle' => __('admin.ai_exposure.page_title'),
            'activeMenu' => 'ai-exposure',
            'adminSiteName' => AdminWeb::siteName(),
        ] + $this->dashboardService->build($request));
    }

    public function showResult(int $resultId): View
    {
        $result = AiExposureResult::query()
            ->with([
                'aiModel:id,name,model_id',
                'run.monitor.article:id,title,slug',
            ])
            ->findOrFail($resultId);

        return view('admin.ai-exposure.show', [
            'pageTitle' => __('admin.ai_exposure.result.page_title'),
            'activeMenu' => 'ai-exposure',
            'adminSiteName' => AdminWeb::siteName(),
            'result' => $result,
        ]);
    }

    public function store(StoreAiExposureMonitorRequest $request): RedirectResponse
    {
        $payload = $request->validated();
        $article = Article::query()->findOrFail((int) $payload['article_id']);
        if ($this->sourceResolver->forArticle($article) === []) {
            return back()->withInput()->withErrors(['article_id' => __('admin.ai_exposure.validation.no_source')]);
        }

        $monitor = new AiExposureMonitor([
            'article_id' => $article->id,
            'query' => trim((string) $payload['query']),
            'frequency' => (string) $payload['frequency'],
            'status' => AiExposureMonitor::STATUS_ACTIVE,
            'created_by_admin_id' => auth('admin')->id(),
        ]);
        $monitor->next_run_at = $monitor->nextScheduledAt();
        $monitor->save();

        return redirect()->route('admin.ai-exposure.index')->with('message', __('admin.ai_exposure.flash.monitor_created'));
    }

    public function update(UpdateAiExposureMonitorRequest $request, int $monitorId): RedirectResponse
    {
        $monitor = AiExposureMonitor::query()->findOrFail($monitorId);
        $payload = $request->validated();
        $article = Article::query()->findOrFail((int) $payload['article_id']);
        if ($this->sourceResolver->forArticle($article) === []) {
            return back()->withInput()->withErrors(['article_id' => __('admin.ai_exposure.validation.no_source')]);
        }

        $monitor->fill([
            'article_id' => $article->id,
            'query' => trim((string) $payload['query']),
            'frequency' => (string) $payload['frequency'],
        ]);
        $monitor->next_run_at = $monitor->status === AiExposureMonitor::STATUS_ACTIVE
            ? $monitor->nextScheduledAt()
            : null;
        $monitor->save();

        return back()->with('message', __('admin.ai_exposure.flash.monitor_updated'));
    }

    public function toggle(int $monitorId): RedirectResponse
    {
        $monitor = AiExposureMonitor::query()->findOrFail($monitorId);
        $monitor->status = $monitor->status === AiExposureMonitor::STATUS_ACTIVE
            ? AiExposureMonitor::STATUS_PAUSED
            : AiExposureMonitor::STATUS_ACTIVE;
        $monitor->next_run_at = $monitor->status === AiExposureMonitor::STATUS_ACTIVE
            ? $monitor->nextScheduledAt()
            : null;
        $monitor->save();

        return back()->with('message', __('admin.ai_exposure.flash.monitor_toggled'));
    }

    public function destroy(int $monitorId): RedirectResponse
    {
        AiExposureMonitor::query()->findOrFail($monitorId)->delete();

        return back()->with('message', __('admin.ai_exposure.flash.monitor_deleted'));
    }

    public function run(int $monitorId): RedirectResponse
    {
        $monitor = AiExposureMonitor::query()->findOrFail($monitorId);

        try {
            $run = $this->runDispatcher->dispatch($monitor);
        } catch (DomainException $exception) {
            return back()->withErrors(['run' => $exception->getMessage()]);
        }

        return back()->with('message', __('admin.ai_exposure.flash.run_queued', ['id' => $run->id]));
    }

    public function updatePlatforms(UpdateAiExposurePlatformsRequest $request): RedirectResponse
    {
        $platforms = $request->validated('platforms');
        $adminId = auth('admin')->id();

        DB::transaction(function () use ($platforms, $adminId): void {
            foreach (AiExposurePlatformCatalog::keys() as $platform) {
                $payload = $platforms[$platform];
                AiExposurePlatformConfig::query()->updateOrCreate(
                    ['platform' => $platform],
                    [
                        'ai_model_id' => $payload['ai_model_id'] ?: null,
                        'enabled' => (bool) $payload['enabled'],
                        'updated_by_admin_id' => $adminId,
                    ]
                );
            }
        });

        return back()->with('message', __('admin.ai_exposure.flash.platforms_updated'));
    }
}
