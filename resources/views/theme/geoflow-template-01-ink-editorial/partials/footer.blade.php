<footer class="ne-footer">
    <div class="ne-shell">
        <div class="ne-footer-inner">
            {{ $footerCopyright !== '' ? $footerCopyright : __('site.footer_copyright', ['year' => date('Y'), 'site' => $siteName]) }}
        </div>
    </div>
</footer>
