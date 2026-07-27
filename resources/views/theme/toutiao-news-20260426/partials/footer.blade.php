<footer class="tt-footer">
    <div class="tt-shell">
        <div class="tt-footer-inner">
            {{ $footerCopyright !== '' ? $footerCopyright : __('site.footer_copyright', ['year' => date('Y'), 'site' => $siteName]) }}
        </div>
    </div>
</footer>
