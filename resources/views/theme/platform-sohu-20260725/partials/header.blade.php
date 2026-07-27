@php
    $path = request()->path();
    $isHome = $path === '' || $path === '/';
    $brandText = trim((string) ($siteName ?? $siteTitle ?? config('app.name')));
    $subtitleText = trim((string) ($siteSubtitle ?? ''));
@endphp
<header class="pf-header">
    <div class="pf-top-strip">
        <div class="pf-shell pf-top-strip__inner">
            <span>{{ $subtitleText !== '' ? $subtitleText : $brandText }}</span>
            <span>{{ $brandText }}</span>
        </div>
    </div>
    <div class="pf-shell pf-header-main">
        <a href="{{ route('site.home') }}" class="pf-brand" aria-label="{{ $brandText }}">
            @if(!empty($siteLogo))
                <img src="{{ $siteLogo }}" alt="{{ $brandText }}" class="pf-brand__logo">
            @else
                <span class="pf-brand__mark">{{ mb_substr($brandText, 0, 1) }}</span>
                <span class="pf-brand__name">{{ $brandText }}</span>
            @endif
        </a>

        <form method="get" action="{{ route('site.home') }}" class="pf-search" role="search">
            <input type="search" name="search" value="{{ request('search') }}" placeholder="{{ __('site.search_placeholder') }}">
            <button type="submit" aria-label="{{ __('site.search_button') }}">
                <i data-lucide="search" aria-hidden="true"></i>
            </button>
        </form>

        <button type="button" class="pf-mobile-menu" onclick="document.getElementById('pfMobileNav')?.classList.toggle('hidden')" aria-label="{{ __('front.nav.categories') }}">
            <i data-lucide="menu" aria-hidden="true"></i>
        </button>
    </div>
    <div class="pf-nav-band">
        <nav class="pf-shell pf-topnav" aria-label="Primary">
            <a href="{{ route('site.home') }}" data-nav-item="home" class="{{ $isHome ? 'is-active' : '' }}">{{ __('front.nav.home') }}</a>
            @foreach($navCategories->take(10) as $categoryItem)
                <a href="{{ route('site.category', $categoryItem->slug) }}" class="{{ request()->is('category/'.$categoryItem->slug) ? 'is-active' : '' }}">{{ $categoryItem->name }}</a>
            @endforeach
        </nav>
    </div>
    <div id="pfMobileNav" class="pf-mobile-nav hidden">
        <div class="pf-shell">
            @include('theme.platform-sohu-20260725.partials.channel-rail', ['mobile' => true])
        </div>
    </div>
</header>
