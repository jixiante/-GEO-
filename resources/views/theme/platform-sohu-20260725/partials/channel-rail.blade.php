@php
    $activeCategoryId = $category->id ?? null;
@endphp
<aside class="pf-channel-rail {{ !empty($mobile) ? 'pf-channel-rail--mobile' : '' }}" aria-label="{{ __('front.nav.categories') }}">
    <a href="{{ route('site.home') }}" class="pf-channel {{ empty($activeCategoryId) && (($activeNav ?? '') === 'home') ? 'is-active' : '' }}">
        <i data-lucide="compass" aria-hidden="true"></i>
        <span>{{ __('front.nav.all_articles') }}</span>
    </a>
    @foreach($navCategories as $categoryItem)
        <a href="{{ route('site.category', $categoryItem->slug) }}" class="pf-channel {{ (int) $activeCategoryId === (int) $categoryItem->id ? 'is-active' : '' }}">
            <span>{{ $categoryItem->name }}</span>
            @if((int) ($categoryItem->published_count ?? 0) > 0)
                <small>{{ (int) $categoryItem->published_count }}</small>
            @endif
        </a>
    @endforeach
</aside>
