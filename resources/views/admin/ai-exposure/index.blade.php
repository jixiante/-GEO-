@extends('admin.layouts.app')

@php
    $platformLookup = collect($platforms)->keyBy('key');
    $statusClasses = [
        'queued' => 'bg-gray-100 text-gray-700',
        'running' => 'bg-amber-100 text-amber-800',
        'completed' => 'bg-emerald-100 text-emerald-800',
        'partial' => 'bg-orange-100 text-orange-800',
        'failed' => 'bg-red-100 text-red-800',
    ];
@endphp

@section('content')
<div
    class="px-4 sm:px-0"
    data-ai-exposure-page
    data-store-url="{{ route('admin.ai-exposure.monitors.store') }}"
    data-update-url="{{ \App\Support\AdminWeb::routePath('admin.ai-exposure.monitors.update', ['monitorId' => '__MONITOR_ID__']) }}"
    data-create-title="{{ __('admin.ai_exposure.monitor.create_title') }}"
    data-edit-title="{{ __('admin.ai_exposure.monitor.edit_title') }}"
    data-create-label="{{ __('admin.ai_exposure.action.create') }}"
    data-save-label="{{ __('admin.ai_exposure.action.save') }}"
>
    <header class="mb-7 flex flex-col gap-4 lg:flex-row lg:items-end lg:justify-between">
        <div>
            <div class="flex items-center gap-2">
                <h1 class="text-2xl font-bold text-gray-900">{{ __('admin.ai_exposure.heading') }}</h1>
                <span class="inline-flex h-6 w-6 items-center justify-center rounded-full border border-gray-200 bg-white text-gray-400" title="{{ __('admin.ai_exposure.scope_tooltip') }}">
                    <i data-lucide="info" class="h-3.5 w-3.5"></i>
                </span>
            </div>
            <p class="mt-1 text-sm text-gray-600">{{ __('admin.ai_exposure.subtitle') }}</p>
        </div>
        <div class="flex flex-wrap items-center gap-2">
            <a href="{{ route('admin.ai-models.index') }}" class="inline-flex h-9 items-center rounded-md border border-gray-300 bg-white px-3 text-sm font-semibold text-gray-700 hover:bg-gray-50">
                <i data-lucide="settings-2" class="mr-2 h-4 w-4"></i>
                {{ __('admin.ai_exposure.action.models') }}
            </a>
            <button type="button" data-open-monitor-modal class="inline-flex h-9 items-center rounded-md bg-blue-600 px-3 text-sm font-semibold text-white hover:bg-blue-700">
                <i data-lucide="plus" class="mr-2 h-4 w-4"></i>
                {{ __('admin.ai_exposure.action.new_monitor') }}
            </button>
        </div>
    </header>

    <section class="mb-7 grid grid-cols-2 gap-3 md:grid-cols-5" aria-label="{{ __('admin.ai_exposure.metrics.label') }}">
        @foreach ([
            ['key' => 'active_monitors', 'label' => __('admin.ai_exposure.metrics.active_monitors'), 'value' => $metrics['active_monitors'], 'icon' => 'radar', 'class' => 'text-cyan-700 bg-cyan-50'],
            ['key' => 'sample_count', 'label' => __('admin.ai_exposure.metrics.samples'), 'value' => $metrics['sample_count'], 'icon' => 'messages-square', 'class' => 'text-gray-700 bg-gray-100'],
            ['key' => 'mentioned_count', 'label' => __('admin.ai_exposure.metrics.mentions'), 'value' => $metrics['mentioned_count'], 'icon' => 'at-sign', 'class' => 'text-emerald-700 bg-emerald-50'],
            ['key' => 'cited_count', 'label' => __('admin.ai_exposure.metrics.citations'), 'value' => $metrics['cited_count'], 'icon' => 'link-2', 'class' => 'text-red-700 bg-red-50'],
            ['key' => 'citation_rate', 'label' => __('admin.ai_exposure.metrics.citation_rate'), 'value' => number_format($metrics['citation_rate'], 1).'%', 'icon' => 'percent', 'class' => 'text-amber-700 bg-amber-50'],
        ] as $metric)
            <div class="rounded-lg border border-gray-200 bg-white p-4 shadow-sm">
                <div class="flex items-start justify-between gap-3">
                    <div>
                        <div class="text-xs font-medium text-gray-500">{{ $metric['label'] }}</div>
                        <div class="mt-1 text-2xl font-semibold text-gray-900" data-ai-exposure-metric="{{ $metric['key'] }}">{{ $metric['value'] }}</div>
                    </div>
                    <span class="flex h-8 w-8 shrink-0 items-center justify-center rounded-md {{ $metric['class'] }}">
                        <i data-lucide="{{ $metric['icon'] }}" class="h-4 w-4"></i>
                    </span>
                </div>
            </div>
        @endforeach
    </section>

    <section class="mb-8">
        <div class="mb-3 flex items-end justify-between gap-4">
            <div>
                <h2 class="text-lg font-semibold text-gray-900">{{ __('admin.ai_exposure.platform.title') }}</h2>
                <p class="mt-1 text-sm text-gray-500">{{ __('admin.ai_exposure.platform.period') }}</p>
            </div>
        </div>
        <div class="grid grid-cols-1 gap-3 sm:grid-cols-2 xl:grid-cols-3">
            @foreach ($platforms as $platform)
                <div class="rounded-lg border border-gray-200 bg-white p-4 shadow-sm" data-ai-exposure-platform="{{ $platform['key'] }}">
                    <div class="flex items-start justify-between gap-3">
                        <div class="flex min-w-0 items-center gap-3">
                            <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-md bg-gray-900 text-xs font-bold text-white">{{ $platform['short_name'] }}</span>
                            <div class="min-w-0">
                                <div class="truncate text-sm font-semibold text-gray-900">{{ $platform['name'] }}</div>
                                <div class="mt-0.5 truncate text-xs text-gray-500">{{ $platform['model_name'] ?: __('admin.ai_exposure.platform.unconfigured') }}</div>
                            </div>
                        </div>
                        <span class="inline-flex shrink-0 items-center rounded-full px-2 py-0.5 text-xs font-medium {{ $platform['enabled'] ? 'bg-emerald-50 text-emerald-700' : 'bg-gray-100 text-gray-500' }}">
                            {{ $platform['enabled'] ? __('admin.ai_exposure.platform.enabled') : __('admin.ai_exposure.platform.disabled') }}
                        </span>
                    </div>
                    <dl class="mt-4 grid grid-cols-3 divide-x divide-gray-100 border-t border-gray-100 pt-3 text-center">
                        <div><dt class="text-xs text-gray-400">{{ __('admin.ai_exposure.platform.samples') }}</dt><dd class="mt-1 text-lg font-semibold text-gray-900" data-ai-exposure-platform-metric="sample_count">{{ $platform['sample_count'] }}</dd></div>
                        <div><dt class="text-xs text-gray-400">{{ __('admin.ai_exposure.platform.mentions') }}</dt><dd class="mt-1 text-lg font-semibold text-emerald-700" data-ai-exposure-platform-metric="mentioned_count">{{ $platform['mentioned_count'] }}</dd></div>
                        <div><dt class="text-xs text-gray-400">{{ __('admin.ai_exposure.platform.citations') }}</dt><dd class="mt-1 text-lg font-semibold text-red-700" data-ai-exposure-platform-metric="cited_count">{{ $platform['cited_count'] }}</dd></div>
                    </dl>
                </div>
            @endforeach
        </div>
    </section>

    <section class="mb-8 border-t border-gray-200 pt-7">
        <div class="mb-4 flex flex-col gap-2 sm:flex-row sm:items-end sm:justify-between">
            <div>
                <h2 class="text-lg font-semibold text-gray-900">{{ __('admin.ai_exposure.config.title') }}</h2>
                <p class="mt-1 text-sm text-gray-500">{{ __('admin.ai_exposure.config.subtitle') }}</p>
            </div>
            <a href="{{ route('admin.ai-models.index') }}" class="inline-flex w-fit items-center text-sm font-semibold text-blue-600 hover:text-blue-700">
                {{ __('admin.ai_exposure.config.manage_models') }}
                <i data-lucide="arrow-up-right" class="ml-1 h-4 w-4"></i>
            </a>
        </div>
        <form method="POST" action="{{ route('admin.ai-exposure.platforms.update') }}" class="overflow-hidden rounded-lg border border-gray-200 bg-white shadow-sm">
            @csrf
            @method('PUT')
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-gray-200">
                    <thead class="bg-gray-50">
                        <tr>
                            <th class="px-4 py-3 text-left text-xs font-semibold uppercase text-gray-500">{{ __('admin.ai_exposure.config.platform') }}</th>
                            <th class="px-4 py-3 text-left text-xs font-semibold uppercase text-gray-500">{{ __('admin.ai_exposure.config.chat_model') }}</th>
                            <th class="px-4 py-3 text-right text-xs font-semibold uppercase text-gray-500">{{ __('admin.ai_exposure.config.status') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        @foreach ($platforms as $platform)
                            <tr>
                                <td class="whitespace-nowrap px-4 py-3">
                                    <div class="text-sm font-semibold text-gray-900">{{ $platform['name'] }}</div>
                                </td>
                                <td class="min-w-72 px-4 py-3">
                                    <select name="platforms[{{ $platform['key'] }}][ai_model_id]" class="block h-9 w-full rounded-md border-gray-300 text-sm focus:border-blue-500 focus:ring-blue-500">
                                        <option value="">{{ __('admin.ai_exposure.config.select_model') }}</option>
                                        @foreach ($chatModels as $model)
                                            <option value="{{ $model->id }}" @selected((int) old('platforms.'.$platform['key'].'.ai_model_id', $platform['ai_model_id']) === (int) $model->id)>
                                                {{ $model->name }} · {{ $model->model_id }}
                                            </option>
                                        @endforeach
                                    </select>
                                </td>
                                <td class="px-4 py-3 text-right">
                                    <input type="hidden" name="platforms[{{ $platform['key'] }}][enabled]" value="0">
                                    <label class="inline-flex cursor-pointer items-center gap-2">
                                        <input type="checkbox" name="platforms[{{ $platform['key'] }}][enabled]" value="1" class="h-4 w-4 rounded border-gray-300 text-blue-600 focus:ring-blue-500" @checked((bool) old('platforms.'.$platform['key'].'.enabled', $platform['enabled']))>
                                        <span class="text-sm text-gray-700">{{ __('admin.ai_exposure.config.enable') }}</span>
                                    </label>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            <div class="flex justify-end border-t border-gray-200 bg-gray-50 px-4 py-3">
                <button type="submit" class="inline-flex h-9 items-center rounded-md bg-blue-600 px-3 text-sm font-semibold text-white hover:bg-blue-700">
                    <i data-lucide="save" class="mr-2 h-4 w-4"></i>
                    {{ __('admin.ai_exposure.action.save_platforms') }}
                </button>
            </div>
        </form>
    </section>

    <section class="mb-8">
        <div class="mb-4 flex items-end justify-between gap-4">
            <div>
                <h2 class="text-lg font-semibold text-gray-900">{{ __('admin.ai_exposure.source.title') }}</h2>
                <p class="mt-1 text-sm text-gray-500">{{ __('admin.ai_exposure.source.subtitle') }}</p>
            </div>
        </div>
        <div class="overflow-hidden rounded-lg border border-gray-200 bg-white shadow-sm">
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-gray-200">
                    <thead class="bg-gray-50">
                        <tr>
                            <th class="px-4 py-3 text-left text-xs font-semibold uppercase text-gray-500">{{ __('admin.ai_exposure.source.website') }}</th>
                            <th class="px-4 py-3 text-right text-xs font-semibold uppercase text-gray-500">{{ __('admin.ai_exposure.source.articles') }}</th>
                            <th class="px-4 py-3 text-right text-xs font-semibold uppercase text-gray-500">{{ __('admin.ai_exposure.source.samples') }}</th>
                            <th class="px-4 py-3 text-right text-xs font-semibold uppercase text-gray-500">{{ __('admin.ai_exposure.source.citations') }}</th>
                            <th class="px-4 py-3 text-right text-xs font-semibold uppercase text-gray-500">{{ __('admin.ai_exposure.source.platforms') }}</th>
                            <th class="px-4 py-3 text-right text-xs font-semibold uppercase text-gray-500">{{ __('admin.ai_exposure.source.last_cited') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        @forelse ($sourceRows as $source)
                            <tr>
                                <td class="max-w-md px-4 py-3">
                                    <a href="{{ $source['url'] }}" target="_blank" rel="noopener noreferrer" class="block truncate text-sm font-semibold text-gray-900 hover:text-blue-600">{{ $source['label'] }}</a>
                                    <div class="mt-0.5 truncate text-xs text-gray-400">{{ $source['host'] }}</div>
                                </td>
                                <td class="px-4 py-3 text-right text-sm text-gray-700">{{ $source['article_count'] }}</td>
                                <td class="px-4 py-3 text-right text-sm text-gray-700">{{ $source['sample_count'] }}</td>
                                <td class="px-4 py-3 text-right text-sm font-semibold text-red-700">{{ $source['citation_count'] }}</td>
                                <td class="px-4 py-3 text-right text-sm text-gray-700">{{ $source['platform_count'] }}</td>
                                <td class="whitespace-nowrap px-4 py-3 text-right text-xs text-gray-500">{{ $source['last_cited_at']?->format('Y-m-d H:i') ?? '—' }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="6" class="px-4 py-10 text-center text-sm text-gray-500">{{ __('admin.ai_exposure.source.empty') }}</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </section>

    <section class="mb-8">
        <div class="mb-4 flex items-center justify-between gap-4">
            <div>
                <h2 class="text-lg font-semibold text-gray-900">{{ __('admin.ai_exposure.monitor.title') }}</h2>
                <p class="mt-1 text-sm text-gray-500">{{ __('admin.ai_exposure.monitor.subtitle') }}</p>
            </div>
            <button type="button" data-open-monitor-modal class="inline-flex h-9 shrink-0 items-center rounded-md border border-gray-300 bg-white px-3 text-sm font-semibold text-gray-700 hover:bg-gray-50">
                <i data-lucide="plus" class="mr-2 h-4 w-4"></i>
                {{ __('admin.ai_exposure.action.add') }}
            </button>
        </div>
        <div class="overflow-hidden rounded-lg border border-gray-200 bg-white shadow-sm">
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-gray-200">
                    <thead class="bg-gray-50">
                        <tr>
                            <th class="px-4 py-3 text-left text-xs font-semibold uppercase text-gray-500">{{ __('admin.ai_exposure.monitor.article_query') }}</th>
                            <th class="px-4 py-3 text-left text-xs font-semibold uppercase text-gray-500">{{ __('admin.ai_exposure.monitor.websites') }}</th>
                            <th class="px-4 py-3 text-left text-xs font-semibold uppercase text-gray-500">{{ __('admin.ai_exposure.monitor.schedule') }}</th>
                            <th class="px-4 py-3 text-left text-xs font-semibold uppercase text-gray-500">{{ __('admin.ai_exposure.monitor.totals') }}</th>
                            <th class="px-4 py-3 text-left text-xs font-semibold uppercase text-gray-500">{{ __('admin.ai_exposure.monitor.latest') }}</th>
                            <th class="px-4 py-3 text-right text-xs font-semibold uppercase text-gray-500">{{ __('admin.ai_exposure.monitor.actions') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        @forelse ($monitors as $monitor)
                            @php $latestRun = $monitor->latestRun; @endphp
                            <tr data-ai-exposure-monitor="{{ $monitor->id }}">
                                <td class="max-w-sm px-4 py-3 align-top">
                                    <div class="truncate text-sm font-semibold text-gray-900">{{ $monitor->article?->title ?? __('admin.ai_exposure.monitor.deleted_article') }}</div>
                                    <div class="mt-1 line-clamp-2 text-xs leading-5 text-gray-500">{{ $monitor->query }}</div>
                                </td>
                                <td class="max-w-xs px-4 py-3 align-top">
                                    <div class="flex flex-wrap gap-1">
                                        @forelse ($monitorSources[$monitor->id] ?? [] as $source)
                                            <span class="inline-flex max-w-40 truncate rounded-full bg-gray-100 px-2 py-0.5 text-xs text-gray-600" title="{{ $source['url'] }}">{{ $source['label'] }}</span>
                                        @empty
                                            <span class="text-xs text-red-600">{{ __('admin.ai_exposure.monitor.no_source') }}</span>
                                        @endforelse
                                    </div>
                                </td>
                                <td class="whitespace-nowrap px-4 py-3 align-top">
                                    <div class="flex items-center gap-2">
                                        <span class="h-2 w-2 rounded-full {{ $monitor->status === 'active' ? 'bg-emerald-500' : 'bg-gray-300' }}"></span>
                                        <span class="text-sm text-gray-700">{{ __('admin.ai_exposure.frequency.'.$monitor->frequency) }}</span>
                                    </div>
                                    <div class="mt-1 text-xs text-gray-400">{{ $monitor->next_run_at?->format('Y-m-d H:i') ?? '—' }}</div>
                                </td>
                                <td class="whitespace-nowrap px-4 py-3 align-top">
                                    <dl class="grid min-w-40 grid-cols-3 gap-3 text-center">
                                        <div><dt class="text-xs text-gray-400">{{ __('admin.ai_exposure.monitor.runs') }}</dt><dd class="mt-1 text-sm font-semibold text-gray-900" data-ai-exposure-monitor-metric="run_count">{{ (int) $monitor->run_count }}</dd></div>
                                        <div><dt class="text-xs text-gray-400">{{ __('admin.ai_exposure.monitor.mentions') }}</dt><dd class="mt-1 text-sm font-semibold text-emerald-700" data-ai-exposure-monitor-metric="mentioned_count">{{ (int) $monitor->mentioned_count }}</dd></div>
                                        <div><dt class="text-xs text-gray-400">{{ __('admin.ai_exposure.monitor.citations') }}</dt><dd class="mt-1 text-sm font-semibold text-red-700" data-ai-exposure-monitor-metric="cited_count">{{ (int) $monitor->cited_count }}</dd></div>
                                    </dl>
                                </td>
                                <td class="whitespace-nowrap px-4 py-3 align-top">
                                    @if ($latestRun)
                                        <span class="inline-flex rounded-full px-2 py-0.5 text-xs font-medium {{ $statusClasses[$latestRun->status] ?? 'bg-gray-100 text-gray-700' }}">{{ __('admin.ai_exposure.status.'.$latestRun->status) }}</span>
                                        <div class="mt-1 text-xs text-gray-400">{{ $latestRun->completed_at?->format('Y-m-d H:i') ?? $latestRun->created_at?->format('Y-m-d H:i') }}</div>
                                    @else
                                        <span class="text-xs text-gray-400">{{ __('admin.ai_exposure.monitor.never_run') }}</span>
                                    @endif
                                </td>
                                <td class="px-4 py-3 align-top">
                                    <div class="flex justify-end gap-1">
                                        <form method="POST" action="{{ route('admin.ai-exposure.monitors.run', ['monitorId' => $monitor->id]) }}">@csrf
                                            <button type="submit" class="inline-flex h-8 w-8 items-center justify-center rounded-md text-gray-500 hover:bg-emerald-50 hover:text-emerald-700 disabled:cursor-not-allowed disabled:opacity-40" title="{{ __('admin.ai_exposure.action.run') }}" @disabled($monitor->status !== 'active')><i data-lucide="play" class="h-4 w-4"></i></button>
                                        </form>
                                        <button
                                            type="button"
                                            data-open-monitor-modal
                                            data-monitor-id="{{ $monitor->id }}"
                                            data-article-id="{{ $monitor->article_id }}"
                                            data-query="{{ $monitor->query }}"
                                            data-frequency="{{ $monitor->frequency }}"
                                            class="inline-flex h-8 w-8 items-center justify-center rounded-md text-gray-500 hover:bg-gray-100 hover:text-gray-800"
                                            title="{{ __('admin.ai_exposure.action.edit') }}"
                                        ><i data-lucide="pencil" class="h-4 w-4"></i></button>
                                        <form method="POST" action="{{ route('admin.ai-exposure.monitors.toggle', ['monitorId' => $monitor->id]) }}">@csrf
                                            <button type="submit" class="inline-flex h-8 w-8 items-center justify-center rounded-md text-gray-500 hover:bg-amber-50 hover:text-amber-700" title="{{ $monitor->status === 'active' ? __('admin.ai_exposure.action.pause') : __('admin.ai_exposure.action.resume') }}"><i data-lucide="{{ $monitor->status === 'active' ? 'pause' : 'rotate-ccw' }}" class="h-4 w-4"></i></button>
                                        </form>
                                        <form method="POST" action="{{ route('admin.ai-exposure.monitors.destroy', ['monitorId' => $monitor->id]) }}">@csrf @method('DELETE')
                                            <button type="button" data-confirm-submit="{{ __('admin.ai_exposure.monitor.delete_confirm') }}" class="inline-flex h-8 w-8 items-center justify-center rounded-md text-gray-500 hover:bg-red-50 hover:text-red-700" title="{{ __('admin.ai_exposure.action.delete') }}"><i data-lucide="trash-2" class="h-4 w-4"></i></button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="6" class="px-4 py-10 text-center text-sm text-gray-500">{{ __('admin.ai_exposure.monitor.empty') }}</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </section>

    <section>
        <div class="mb-4 flex flex-col gap-3 lg:flex-row lg:items-end lg:justify-between">
            <div>
                <h2 class="text-lg font-semibold text-gray-900">{{ __('admin.ai_exposure.result.title') }}</h2>
                <p class="mt-1 text-sm text-gray-500">{{ __('admin.ai_exposure.result.subtitle') }}</p>
            </div>
            <form method="GET" action="{{ route('admin.ai-exposure.index') }}" class="grid grid-cols-1 gap-2 sm:grid-cols-3">
                <select name="platform" class="h-9 rounded-md border-gray-300 text-sm focus:border-blue-500 focus:ring-blue-500">
                    <option value="">{{ __('admin.ai_exposure.filter.all_platforms') }}</option>
                    @foreach ($platforms as $platform)<option value="{{ $platform['key'] }}" @selected($filters['platform'] === $platform['key'])>{{ $platform['name'] }}</option>@endforeach
                </select>
                <select name="article_id" class="h-9 max-w-64 rounded-md border-gray-300 text-sm focus:border-blue-500 focus:ring-blue-500">
                    <option value="">{{ __('admin.ai_exposure.filter.all_articles') }}</option>
                    @foreach ($articleOptions as $article)<option value="{{ $article->id }}" @selected($filters['article_id'] === (int) $article->id)>{{ $article->title }}</option>@endforeach
                </select>
                <div class="flex gap-2">
                    <select name="exposure" class="h-9 min-w-36 rounded-md border-gray-300 text-sm focus:border-blue-500 focus:ring-blue-500">
                        <option value="">{{ __('admin.ai_exposure.filter.all_results') }}</option>
                        @foreach (['mentioned', 'cited', 'not_exposed', 'failed'] as $value)<option value="{{ $value }}" @selected($filters['exposure'] === $value)>{{ __('admin.ai_exposure.filter.'.$value) }}</option>@endforeach
                    </select>
                    <button type="submit" class="inline-flex h-9 w-9 shrink-0 items-center justify-center rounded-md border border-gray-300 bg-white text-gray-600 hover:bg-gray-50" title="{{ __('admin.ai_exposure.action.filter') }}"><i data-lucide="search" class="h-4 w-4"></i></button>
                </div>
            </form>
        </div>
        <div class="overflow-hidden rounded-lg border border-gray-200 bg-white shadow-sm">
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-gray-200">
                    <thead class="bg-gray-50">
                        <tr>
                            <th class="px-4 py-3 text-left text-xs font-semibold uppercase text-gray-500">{{ __('admin.ai_exposure.result.platform') }}</th>
                            <th class="px-4 py-3 text-left text-xs font-semibold uppercase text-gray-500">{{ __('admin.ai_exposure.result.article') }}</th>
                            <th class="px-4 py-3 text-left text-xs font-semibold uppercase text-gray-500">{{ __('admin.ai_exposure.result.exposure') }}</th>
                            <th class="px-4 py-3 text-left text-xs font-semibold uppercase text-gray-500">{{ __('admin.ai_exposure.result.sources') }}</th>
                            <th class="px-4 py-3 text-right text-xs font-semibold uppercase text-gray-500">{{ __('admin.ai_exposure.result.checked_at') }}</th>
                            <th class="relative px-4 py-3 text-right text-xs font-semibold uppercase text-gray-500"><span class="sr-only">{{ __('admin.ai_exposure.result.details') }}</span></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        @forelse ($recentResults as $result)
                            @php $platform = $platformLookup->get($result->platform); @endphp
                            <tr>
                                <td class="whitespace-nowrap px-4 py-3 text-sm font-semibold text-gray-900">{{ $platform['name'] ?? $result->platform }}</td>
                                <td class="max-w-xs px-4 py-3"><div class="truncate text-sm text-gray-800">{{ $result->run?->monitor?->article?->title ?? '—' }}</div><div class="mt-0.5 truncate text-xs text-gray-400">{{ $result->run?->monitor?->query }}</div></td>
                                <td class="px-4 py-3">
                                    @if ($result->status === 'failed')
                                        <span class="inline-flex rounded-full bg-red-100 px-2 py-0.5 text-xs font-medium text-red-800">{{ __('admin.ai_exposure.result.failed') }}</span>
                                    @else
                                        <div class="flex flex-wrap gap-1">
                                            @if ($result->mentioned)
                                                <span class="inline-flex rounded-full bg-emerald-50 px-2 py-0.5 text-xs font-medium text-emerald-700">{{ __('admin.ai_exposure.result.mentioned') }}</span>
                                            @endif
                                            @if ($result->cited)
                                                <span class="inline-flex rounded-full bg-red-50 px-2 py-0.5 text-xs font-medium text-red-700">{{ __('admin.ai_exposure.result.cited') }}</span>
                                            @endif
                                            @if (! $result->mentioned && ! $result->cited)
                                                <span class="inline-flex rounded-full bg-gray-100 px-2 py-0.5 text-xs font-medium text-gray-600">{{ __('admin.ai_exposure.result.not_exposed') }}</span>
                                            @endif
                                        </div>
                                    @endif
                                </td>
                                <td class="max-w-xs px-4 py-3">
                                    <div class="flex flex-wrap gap-1">
                                        @forelse ((array) $result->matched_sources as $source)
                                            <span class="inline-flex max-w-40 truncate rounded-full bg-gray-100 px-2 py-0.5 text-xs text-gray-600">{{ $source['label'] ?? $source['host'] ?? '—' }}</span>
                                        @empty
                                            <span class="text-xs text-gray-400">—</span>
                                        @endforelse
                                    </div>
                                </td>
                                <td class="whitespace-nowrap px-4 py-3 text-right text-xs text-gray-500">{{ $result->checked_at?->format('Y-m-d H:i') ?? '—' }}</td>
                                <td class="px-4 py-3 text-right"><a href="{{ route('admin.ai-exposure.results.show', ['resultId' => $result->id]) }}" class="inline-flex h-8 w-8 items-center justify-center rounded-md text-gray-500 hover:bg-gray-100 hover:text-gray-800" title="{{ __('admin.ai_exposure.result.details') }}"><i data-lucide="square-arrow-out-up-right" class="h-4 w-4"></i></a></td>
                            </tr>
                        @empty
                            <tr><td colspan="6" class="px-4 py-10 text-center text-sm text-gray-500">{{ __('admin.ai_exposure.result.empty') }}</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            @if ($recentResults->hasPages())
                <div class="border-t border-gray-200 px-4 py-3">{{ $recentResults->links() }}</div>
            @endif
        </div>
    </section>

    <div data-monitor-modal class="fixed inset-0 z-50 hidden" aria-hidden="true" role="dialog" aria-modal="true">
        <button type="button" data-close-monitor-modal class="absolute inset-0 bg-gray-950/45" aria-label="{{ __('admin.ai_exposure.action.close') }}"></button>
        <div class="relative mx-auto mt-16 w-[calc(100%-2rem)] max-w-xl rounded-lg bg-white shadow-2xl sm:mt-24">
            <div class="flex items-center justify-between border-b border-gray-200 px-5 py-4">
                <h2 data-monitor-modal-title class="text-base font-semibold text-gray-900">{{ __('admin.ai_exposure.monitor.create_title') }}</h2>
                <button type="button" data-close-monitor-modal class="inline-flex h-8 w-8 items-center justify-center rounded-md text-gray-400 hover:bg-gray-100 hover:text-gray-700" title="{{ __('admin.ai_exposure.action.close') }}"><i data-lucide="x" class="h-5 w-5"></i></button>
            </div>
            <form method="POST" action="{{ route('admin.ai-exposure.monitors.store') }}" class="space-y-5 p-5">
                @csrf
                <input type="hidden" name="_method" value="POST">
                <div>
                    <label for="ai-exposure-article" class="block text-sm font-medium text-gray-700">{{ __('admin.ai_exposure.monitor.article') }}</label>
                    <select id="ai-exposure-article" name="article_id" required class="mt-1 block h-10 w-full rounded-md border-gray-300 text-sm focus:border-blue-500 focus:ring-blue-500">
                        <option value="">{{ __('admin.ai_exposure.monitor.select_article') }}</option>
                        @foreach ($articleOptions as $article)
                            <option value="{{ $article->id }}" data-default-query="{{ $article->original_keyword ?: $article->title }}">{{ $article->title }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label for="ai-exposure-query" class="block text-sm font-medium text-gray-700">{{ __('admin.ai_exposure.monitor.query') }}</label>
                    <textarea id="ai-exposure-query" name="query" required maxlength="500" rows="3" class="mt-1 block w-full rounded-md border-gray-300 text-sm focus:border-blue-500 focus:ring-blue-500" placeholder="{{ __('admin.ai_exposure.monitor.query_placeholder') }}"></textarea>
                </div>
                <div>
                    <label for="ai-exposure-frequency" class="block text-sm font-medium text-gray-700">{{ __('admin.ai_exposure.monitor.frequency') }}</label>
                    <select id="ai-exposure-frequency" name="frequency" class="mt-1 block h-10 w-full rounded-md border-gray-300 text-sm focus:border-blue-500 focus:ring-blue-500">
                        @foreach (\App\Models\AiExposureMonitor::frequencies() as $frequency)<option value="{{ $frequency }}" @selected($frequency === \App\Models\AiExposureMonitor::FREQUENCY_DAILY)>{{ __('admin.ai_exposure.frequency.'.$frequency) }}</option>@endforeach
                    </select>
                </div>
                <div class="flex justify-end gap-2 border-t border-gray-100 pt-4">
                    <button type="button" data-close-monitor-modal class="inline-flex h-9 items-center rounded-md border border-gray-300 bg-white px-3 text-sm font-semibold text-gray-700 hover:bg-gray-50">{{ __('admin.ai_exposure.action.cancel') }}</button>
                    <button type="submit" class="inline-flex h-9 items-center rounded-md bg-blue-600 px-3 text-sm font-semibold text-white hover:bg-blue-700"><i data-lucide="save" class="mr-2 h-4 w-4"></i><span data-monitor-submit-label>{{ __('admin.ai_exposure.action.create') }}</span></button>
                </div>
            </form>
        </div>
    </div>
</div>
@endsection
