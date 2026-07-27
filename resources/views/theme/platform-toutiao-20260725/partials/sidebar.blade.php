@php
    $sidebarHotArticles = collect($hotArticles ?? [])->take(8);
    $latestArticles = is_object($articles ?? null) && method_exists($articles, 'getCollection')
        ? $articles->getCollection()->take(8)
        : collect($articles ?? [])->take(8);
    $sidebarArticles = $sidebarHotArticles->isNotEmpty() ? $sidebarHotArticles : $latestArticles;
@endphp
<aside class="pf-sidebar">
    @if(!empty($showFeedPanel))
        <section class="pf-profile-panel">
            <span class="pf-profile-mark">{{ mb_substr($siteTitle, 0, 1) }}</span>
            <div>
                <h2>{{ $siteTitle }}</h2>
                @if(trim((string) ($siteDescription ?? '')) !== '')
                    <p>{{ $siteDescription }}</p>
                @endif
            </div>
        </section>
    @endif

    <section class="pf-ranking">
        <div class="pf-section-head">
            <h2>{{ $sidebarHotArticles->isNotEmpty() ? __('site.home_hot') : __('site.home_latest') }}</h2>
        </div>
        <div class="pf-ranking-list">
            @forelse($sidebarArticles as $hotArticle)
                <a href="{{ route('site.article', $hotArticle->slug) }}" class="pf-ranking-item">
                    <span>{{ $loop->iteration }}</span>
                    <strong>{{ $hotArticle->title }}</strong>
                </a>
            @empty
                <p class="pf-empty-copy">{{ __('site.home_empty_title') }}</p>
            @endforelse
        </div>
    </section>
</aside>
