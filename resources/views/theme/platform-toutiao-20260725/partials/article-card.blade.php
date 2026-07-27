@php
    /** @var \App\Models\Article $article */
    $summaryRaw = (string) ($cardSummaries[$article->id] ?? '');
    $summary = trim(preg_replace([
        '/!\[[^\]]*]\([^)]+\)/u',
        '/\[[^\]]+]\([^)]+\)/u',
        '/[`*_>#|~-]+/u',
        '/\s+/u',
    ], [' ', ' ', ' ', ' '], strip_tags($summaryRaw)) ?? '');
    $pub = $article->published_at ?? $article->created_at;
    $categoryName = $article->category?->name ?? __('front.nav.all_articles');
    $initial = mb_substr($categoryName, 0, 1);
    $coverImage = '';
    $articleContent = (string) ($article->content ?? '');
    if (preg_match('/!\[[^\]]*]\(([^)\s]+)(?:\s+["\'][^"\']*["\'])?\)/u', $articleContent, $coverMatch) === 1
        || preg_match('/<img[^>]+src=["\']([^"\']+)["\']/iu', $articleContent, $coverMatch) === 1) {
        $coverCandidate = html_entity_decode(trim((string) ($coverMatch[1] ?? '')), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        if (preg_match('#^(https?://|/)#i', $coverCandidate) === 1) {
            $coverImage = $coverCandidate;
        }
    }
@endphp
<article class="pf-article-card {{ $coverImage !== '' ? 'has-cover' : 'no-cover' }}">
    <a href="{{ route('site.article', $article->slug) }}" class="pf-card-cover" aria-label="{{ $article->title }}">
        @if($coverImage !== '')
            <img src="{{ $coverImage }}" alt="" loading="lazy">
        @else
            <span>{{ $initial }}</span>
        @endif
    </a>
    <div class="pf-card-body">
        <div class="pf-card-meta">
            @if(!empty($showFeaturedBadge))
                <span class="pf-pill">{{ __('site.home_featured_badge') }}</span>
            @endif
            @if($article->category)
                <a href="{{ route('site.category', $article->category->slug) }}" class="pf-category-link">{{ $article->category->name }}</a>
            @endif
            <time datetime="{{ $pub?->toAtomString() }}">{{ $pub?->format('Y-m-d') }}</time>
        </div>
        <h2 class="pf-article-title">
            <a href="{{ route('site.article', $article->slug) }}">{{ $article->title }}</a>
        </h2>
        @if($summary !== '')
            <p class="pf-article-summary">{{ $summary }}</p>
        @endif
        <div class="pf-card-footer">
            <span>{{ $article->author?->name ?? $siteTitle }}</span>
            <span class="pf-card-stat"><i data-lucide="eye" aria-hidden="true"></i>{{ (int) $article->view_count }}</span>
        </div>
    </div>
</article>
