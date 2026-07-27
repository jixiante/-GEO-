@extends('theme.platform-sohu-20260725.layout')

@push('head')
    @php
        $schemaAtContext = chr(64).'context';
        $schemaAtType = chr(64).'type';
        $schemaItems = [];
        foreach ((is_object($articles ?? null) && method_exists($articles, 'getCollection') ? $articles->getCollection() : collect($articles ?? []))->take(10) as $schemaArticle) {
            $schemaItems[] = [
                $schemaAtType => 'ListItem',
                'position' => count($schemaItems) + 1,
                'url' => route('site.article', $schemaArticle->slug),
                'name' => $schemaArticle->title,
            ];
        }
        $collectionSchema = [
            $schemaAtContext => 'https://schema.org',
            $schemaAtType => 'CollectionPage',
            'name' => $pageTitle,
            'description' => $pageDescription,
            'url' => $canonicalUrl ?? route('site.home'),
            'mainEntity' => [
                $schemaAtType => 'ItemList',
                'itemListElement' => $schemaItems,
            ],
        ];
    @endphp
    <x-json-ld :data="$collectionSchema" />
@endpush

@section('content')
    @include('site.partials.homepage-modules', [
        'homepageModules' => $homepageModules ?? [],
        'homepageStyle' => $homepageStyle ?? [],
        'showHomepageModules' => $showHomepageModules ?? false,
        'articles' => $articles ?? collect(),
        'featuredArticles' => $featuredArticles ?? collect(),
        'hotArticles' => $hotArticles ?? collect(),
    ])

    @php
        $homeArticles = is_object($articles ?? null) && method_exists($articles, 'getCollection') ? $articles->getCollection() : collect($articles ?? []);
        $isDefaultHome = $search === '' && !$category && !$categoryMissing;
        $headlineArticles = collect($hotArticles ?? [])->isNotEmpty()
            ? collect($hotArticles)->take(5)
            : collect($featuredArticles ?? [])->concat($homeArticles)->filter()->unique(fn ($item) => $item->id)->take(5);
    @endphp

    <div class="pf-shell pf-home-grid">
        @include('theme.platform-sohu-20260725.partials.channel-rail')

        <section class="pf-feed-column">
            <header class="pf-home-intro">
                <div class="pf-intro-copy">
                    <span class="pf-kicker">{{ $siteSubtitle !== '' ? $siteSubtitle : $siteTitle }}</span>
                    <h1>{{ $isDefaultHome ? $siteTitle : $viewTitle }}</h1>
                    @if($pageDescription !== '')
                        <p>{{ $pageDescription }}</p>
                    @endif
                </div>
                @if($headlineArticles->isNotEmpty())
                    <div class="pf-headline-list">
                        <strong>{{ __('site.home_hot') }}</strong>
                        @foreach($headlineArticles as $headlineArticle)
                            <a href="{{ route('site.article', $headlineArticle->slug) }}">{{ $headlineArticle->title }}</a>
                        @endforeach
                    </div>
                @endif
            </header>

            @if($featuredArticles->isNotEmpty() && $isDefaultHome)
                <section class="pf-feed-section pf-featured-section">
                    <div class="pf-section-head">
                        <h2>{{ __('site.home_featured') }}</h2>
                    </div>
                    <div class="pf-feed-list">
                        @foreach($featuredArticles->take(3) as $article)
                            @include('theme.platform-sohu-20260725.partials.article-card', ['article' => $article, 'showFeaturedBadge' => true])
                        @endforeach
                    </div>
                </section>
            @endif

            <section class="pf-feed-section">
                <div class="pf-section-head">
                    <h2>{{ $viewTitle }}</h2>
                </div>
                <div class="pf-feed-list">
                    @forelse($articles as $article)
                        @include('theme.platform-sohu-20260725.partials.article-card', ['article' => $article])
                    @empty
                        <div class="pf-empty-state">{{ __('site.home_empty_title') }}</div>
                    @endforelse
                </div>
            </section>

            @if($articles->hasPages())
                <div class="pf-pagination">{{ $articles->onEachSide(1)->links() }}</div>
            @endif
        </section>

        @include('theme.platform-sohu-20260725.partials.sidebar', ['showFeedPanel' => $isDefaultHome])
    </div>
@endsection
