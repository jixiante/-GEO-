@extends('theme.platform-xiaohongshu-20260725.layout')

@push('head')
    @php
        $schemaAtContext = chr(64).'context';
        $schemaAtType = chr(64).'type';
        $schemaAtId = chr(64).'id';
        $articleSchema = [
            $schemaAtContext => 'https://schema.org',
            $schemaAtType => 'NewsArticle',
            'headline' => $article->title,
            'description' => $pageDescription,
            'datePublished' => optional($article->published_at ?? $article->created_at)->toAtomString(),
            'dateModified' => optional($article->updated_at ?? $article->published_at ?? $article->created_at)->toAtomString(),
            'mainEntityOfPage' => [
                $schemaAtType => 'WebPage',
                $schemaAtId => $canonicalUrl ?? route('site.article', $article->slug),
            ],
            'author' => [$schemaAtType => 'Person', 'name' => $article->author?->name ?? $siteTitle],
            'publisher' => [$schemaAtType => 'Organization', 'name' => $siteTitle],
            'articleSection' => $article->category?->name,
            'keywords' => $tags,
        ];
    @endphp
    @if($article->category)
        <meta property="article:section" content="{{ $article->category->name }}">
    @endif
    <x-json-ld :data="$articleSchema" />
@endpush

@section('content')
    <div class="pf-shell pf-article-layout">
        <main class="pf-post-column">
            <nav class="pf-breadcrumb" aria-label="Breadcrumb">
                <a href="{{ route('site.home') }}">{{ __('front.nav.home') }}</a>
                @if($article->category)
                    <i data-lucide="chevron-right" aria-hidden="true"></i>
                    <a href="{{ route('site.category', $article->category->slug) }}">{{ $article->category->name }}</a>
                @endif
            </nav>
            <article class="pf-article-main">
                <h1>{{ $article->title }}</h1>
                <div class="pf-post-meta">
                    @if($article->author)
                        <span class="pf-author-mark">{{ mb_substr($article->author->name, 0, 1) }}</span>
                        <strong>{{ $article->author->name }}</strong>
                    @endif
                    <time datetime="{{ ($article->published_at ?? $article->created_at)?->toAtomString() }}">{{ ($article->published_at ?? $article->created_at)?->format('Y-m-d H:i') }}</time>
                    <span>{{ __('site.article_views', ['count' => (int) $article->view_count]) }}</span>
                </div>
                @if($excerptPlain !== '')
                    <p class="pf-article-excerpt">{{ $excerptPlain }}</p>
                @endif
                <div class="pf-prose">{!! $contentHtml !!}</div>
                @if(!empty($tags))
                    <div class="pf-tag-list">
                        @foreach($tags as $tag)<span>{{ $tag }}</span>@endforeach
                    </div>
                @endif
                @if($stickyAd)
                    <section class="pf-ad-slot">
                        @php
                            $stickyAdTitle = is_array($stickyAd) ? trim((string) ($stickyAd['title'] ?? '')) : trim((string) ($stickyAd->title ?? ''));
                        @endphp
                        @if($stickyAdTitle !== '')<h2>{{ $stickyAdTitle }}</h2>@endif
                        @if(is_array($stickyAd))
                            <p>{{ $stickyAd['copy'] ?? '' }}</p>
                            @if(trim((string) ($stickyAd['button_text'] ?? '')) !== '')
                                <a href="{{ $stickyAd['button_url'] ?? '#' }}">{{ $stickyAd['button_text'] }}</a>
                            @endif
                        @else
                            {!! $stickyAd->content_html !!}
                        @endif
                    </section>
                @endif
            </article>
            @if($relatedArticles->isNotEmpty())
                <section class="pf-related">
                    <div class="pf-section-head"><h2>{{ __('site.article_related') }}</h2></div>
                    <div class="pf-related-grid">
                        @foreach($relatedArticles as $related)
                            <a href="{{ route('site.article', $related->slug) }}"><span>{{ $loop->iteration }}</span>{{ $related->title }}</a>
                        @endforeach
                    </div>
                </section>
            @endif
        </main>
        <aside class="pf-post-aside">
            <section class="pf-profile-panel">
                <span class="pf-profile-mark">{{ mb_substr($siteTitle, 0, 1) }}</span>
                <div><h2>{{ $siteTitle }}</h2><p>{{ $siteDescription }}</p></div>
            </section>
            @if($relatedArticles->isNotEmpty())
                <section class="pf-ranking">
                    <div class="pf-section-head"><h2>{{ __('site.article_related') }}</h2></div>
                    <div class="pf-ranking-list">
                        @foreach($relatedArticles as $related)
                            <a href="{{ route('site.article', $related->slug) }}" class="pf-ranking-item"><span>{{ $loop->iteration }}</span><strong>{{ $related->title }}</strong></a>
                        @endforeach
                    </div>
                </section>
            @endif
        </aside>
    </div>
@endsection
