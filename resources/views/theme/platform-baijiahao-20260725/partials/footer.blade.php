<footer class="pf-footer">
    <div class="pf-shell pf-footer__inner">
        <span>{{ $footerCopyright !== '' ? $footerCopyright : __('site.footer_copyright', ['year' => date('Y'), 'site' => $siteName]) }}</span>
        <a href="{{ route('site.home') }}">{{ $siteName }}</a>
    </div>
</footer>
