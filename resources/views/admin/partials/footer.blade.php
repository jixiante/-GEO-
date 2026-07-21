@php
    $appVersion = (string) config('geoflow.app_version', '2.0');
    $adminBrandName = \App\Support\AdminWeb::siteName();
    $reverbApp = config('reverb.apps.apps.0', []);
    $reverbHost = (string) (config('reverb.servers.reverb.hostname') ?: config('app.url'));
    $reverbParsedHost = parse_url($reverbHost, PHP_URL_HOST);
    $reverbPath = trim((string) config('reverb.servers.reverb.path', ''));
    if ($reverbPath !== '' && ! str_starts_with($reverbPath, '/')) {
        $reverbPath = '/'.$reverbPath;
    }
    $reverbRuntimeConfig = [
        'enabled' => (string) config('broadcasting.default') === 'reverb',
        'key' => (string) ($reverbApp['key'] ?? ''),
        'host' => $reverbParsedHost ? (string) $reverbParsedHost : $reverbHost,
        'port' => (int) (config('reverb.apps.apps.0.options.port') ?: 443),
        'scheme' => (string) (config('reverb.apps.apps.0.options.scheme') ?: 'https'),
        'path' => rtrim($reverbPath, '/'),
        'authEndpoint' => \App\Support\AdminWeb::appPath('/broadcasting/auth'),
    ];
@endphp
<footer class="bg-white border-t border-gray-200 mt-12">
    <div class="max-w-7xl mx-auto py-6 px-4 sm:px-6 lg:px-8">
        <div class="flex flex-col items-center justify-center gap-3 text-center text-sm text-gray-500 md:flex-row md:flex-wrap md:gap-4">
            <span>© {{ date('Y') }} {{ $adminBrandName }}</span>
            <span class="hidden text-gray-300 md:inline">|</span>
            <span>{{ __('admin.footer.version', ['version' => $appVersion]) }}</span>
            <span class="hidden text-gray-300 md:inline">|</span>
            <button type="button" data-open-admin-welcome class="font-medium text-blue-600 underline-offset-2 hover:text-blue-700 hover:underline">
                {{ __('admin.footer.project_intro_link') }}
            </button>
        </div>
    </div>
</footer>
<script>
    window.ADMIN_BASE_PATH = @json('/'.\App\Support\AdminWeb::basePath());
    window.GEOFLOW_REVERB_CONFIG = @json($reverbRuntimeConfig, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
    window.adminUrl = function (path) {
        const base = window.ADMIN_BASE_PATH || '';
        if (!path) return base + '/';
        return base + '/' + String(path).replace(/^\/+/, '');
    };
    if (typeof lucide !== 'undefined') {
        lucide.createIcons();
    }
</script>
