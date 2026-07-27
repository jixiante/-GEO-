@extends('admin.layouts.app')

@php
    $platformName = \App\Support\AiExposure\AiExposurePlatformCatalog::name($result->platform);
@endphp

@section('content')
<div class="px-4 sm:px-0">
    <div class="mb-6 flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between">
        <div>
            <a href="{{ route('admin.ai-exposure.index') }}" class="inline-flex items-center text-sm font-semibold text-blue-600 hover:text-blue-700"><i data-lucide="arrow-left" class="mr-1.5 h-4 w-4"></i>{{ __('admin.ai_exposure.result.back') }}</a>
            <h1 class="mt-3 text-2xl font-bold text-gray-900">{{ __('admin.ai_exposure.result.evidence_title') }}</h1>
            <p class="mt-1 text-sm text-gray-500">{{ $platformName }} · {{ $result->checked_at?->format('Y-m-d H:i:s') ?? '—' }}</p>
        </div>
        <div class="flex flex-wrap gap-2">
            @if ($result->status === 'failed')
                <span class="inline-flex items-center rounded-full bg-red-100 px-3 py-1 text-sm font-semibold text-red-800"><i data-lucide="circle-alert" class="mr-1.5 h-4 w-4"></i>{{ __('admin.ai_exposure.result.failed') }}</span>
            @else
                @if ($result->mentioned)
                    <span class="inline-flex items-center rounded-full bg-emerald-50 px-3 py-1 text-sm font-semibold text-emerald-700"><i data-lucide="at-sign" class="mr-1.5 h-4 w-4"></i>{{ __('admin.ai_exposure.result.mentioned') }}</span>
                @endif
                @if ($result->cited)
                    <span class="inline-flex items-center rounded-full bg-red-50 px-3 py-1 text-sm font-semibold text-red-700"><i data-lucide="link-2" class="mr-1.5 h-4 w-4"></i>{{ __('admin.ai_exposure.result.cited') }}</span>
                @endif
                @if (! $result->mentioned && ! $result->cited)
                    <span class="inline-flex items-center rounded-full bg-gray-100 px-3 py-1 text-sm font-semibold text-gray-600">{{ __('admin.ai_exposure.result.not_exposed') }}</span>
                @endif
            @endif
        </div>
    </div>

    <dl class="mb-6 grid grid-cols-1 gap-px overflow-hidden rounded-lg border border-gray-200 bg-gray-200 sm:grid-cols-3">
        <div class="bg-white p-4"><dt class="text-xs font-medium text-gray-500">{{ __('admin.ai_exposure.result.article') }}</dt><dd class="mt-1 text-sm font-semibold text-gray-900">{{ $result->run?->monitor?->article?->title ?? '—' }}</dd></div>
        <div class="bg-white p-4"><dt class="text-xs font-medium text-gray-500">{{ __('admin.ai_exposure.monitor.query') }}</dt><dd class="mt-1 text-sm text-gray-900">{{ $result->run?->monitor?->query ?? '—' }}</dd></div>
        <div class="bg-white p-4"><dt class="text-xs font-medium text-gray-500">{{ __('admin.ai_exposure.result.model') }}</dt><dd class="mt-1 text-sm text-gray-900">{{ $result->response_meta['model_name'] ?? $result->aiModel?->name ?? '—' }}</dd></div>
    </dl>

    @if ($result->status === 'failed')
        <section class="mb-6 rounded-lg border border-red-200 bg-red-50 p-5">
            <h2 class="text-sm font-semibold text-red-900">{{ __('admin.ai_exposure.result.error') }}</h2>
            <p class="mt-2 whitespace-pre-wrap text-sm leading-6 text-red-800">{{ $result->error_message }}</p>
        </section>
    @else
        <section class="mb-6">
            <h2 class="mb-3 text-lg font-semibold text-gray-900">{{ __('admin.ai_exposure.result.answer') }}</h2>
            <div class="rounded-lg border border-gray-200 bg-white p-5 shadow-sm">
                <div class="whitespace-pre-wrap break-words text-sm leading-7 text-gray-800">{{ $result->answer_text }}</div>
            </div>
        </section>
    @endif

    <div class="grid grid-cols-1 gap-6 lg:grid-cols-2">
        <section>
            <h2 class="mb-3 text-lg font-semibold text-gray-900">{{ __('admin.ai_exposure.result.matched_websites') }}</h2>
            <div class="divide-y divide-gray-100 overflow-hidden rounded-lg border border-gray-200 bg-white shadow-sm">
                @forelse ((array) $result->matched_sources as $source)
                    <a href="{{ $source['url'] }}" target="_blank" rel="noopener noreferrer" class="flex items-center justify-between gap-3 px-4 py-3 hover:bg-gray-50">
                        <div class="min-w-0"><div class="truncate text-sm font-semibold text-gray-900">{{ $source['label'] ?? $source['host'] }}</div><div class="mt-0.5 truncate text-xs text-gray-400">{{ $source['url'] }}</div></div>
                        <i data-lucide="external-link" class="h-4 w-4 shrink-0 text-gray-400"></i>
                    </a>
                @empty
                    <div class="px-4 py-8 text-center text-sm text-gray-500">{{ __('admin.ai_exposure.result.no_matched_websites') }}</div>
                @endforelse
            </div>
        </section>
        <section>
            <h2 class="mb-3 text-lg font-semibold text-gray-900">{{ __('admin.ai_exposure.result.cited_urls') }}</h2>
            <div class="divide-y divide-gray-100 overflow-hidden rounded-lg border border-gray-200 bg-white shadow-sm">
                @forelse ((array) $result->cited_urls as $url)
                    <a href="{{ $url }}" target="_blank" rel="noopener noreferrer" class="flex items-center justify-between gap-3 px-4 py-3 hover:bg-gray-50"><span class="min-w-0 truncate text-sm text-gray-700">{{ $url }}</span><i data-lucide="external-link" class="h-4 w-4 shrink-0 text-gray-400"></i></a>
                @empty
                    <div class="px-4 py-8 text-center text-sm text-gray-500">{{ __('admin.ai_exposure.result.no_cited_urls') }}</div>
                @endforelse
            </div>
        </section>
    </div>
</div>
@endsection
