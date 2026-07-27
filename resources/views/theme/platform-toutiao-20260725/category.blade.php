@extends('theme.platform-toutiao-20260725.layout')

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
            'url' => $canonicalUrl ?? route('site.category', $category->slug),
            'mainEntity' => [
                $schemaAtType => 'ItemList',
                'itemListElement' => $schemaItems,
            ],
        ];
    @endphp
    <x-json-ld :data="$collectionSchema" />
@endpush

@section('content')
    <div class="pf-shell pf-home-grid pf-category-grid">
        @include('theme.platform-toutiao-20260725.partials.channel-rail')
        <section class="pf-feed-column">
            <header class="pf-page-head">
                <span class="pf-kicker">{{ $siteTitle }} · {{ __('front.nav.categories') }}</span>
                <h1>{{ $category->name }}</h1>
                <p>{{ trim((string) $category->description) !== '' ? $category->description : $pageDescription }}</p>
                <nav class="pf-category-tabs" aria-label="{{ __('front.nav.categories') }}">
                    @foreach((isset($navCategories) ? collect($navCategories) : collect([$category])) as $categoryItem)
                        <a href="{{ route('site.category', $categoryItem->slug) }}" class="{{ $categoryItem->slug === $category->slug ? 'is-active' : '' }}">{{ $categoryItem->name }}</a>
                    @endforeach
                </nav>
            </header>
            <section class="pf-feed-section">
                <div class="pf-section-head"><h2>{{ $category->name }}</h2></div>
                <div class="pf-feed-list">
                    @forelse($articles as $article)
                        @include('theme.platform-toutiao-20260725.partials.article-card', ['article' => $article])
                    @empty
                        <div class="pf-empty-state">{{ __('site.home_empty_title') }}</div>
                    @endforelse
                </div>
            </section>
            @if($articles->hasPages())
                <div class="pf-pagination">{{ $articles->onEachSide(1)->links() }}</div>
            @endif
        </section>
        @include('theme.platform-toutiao-20260725.partials.sidebar')
    </div>
@endsection
