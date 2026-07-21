@php
    $adminBrandName = \App\Support\AdminWeb::siteName();
@endphp
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@isset($pageTitle){{ $pageTitle }} — @endisset{{ $adminBrandName }}</title>
    <script src="{{ asset('js/tailwindcss.play-cdn.js') }}"></script>
    <script src="{{ asset('js/lucide.min.js') }}"></script>
    <style>
        .admin-shell {
            background: #f4f5f6;
            color: #202124;
        }

        .admin-shell .admin-brand-seal {
            background: #a6322a;
            border-radius: 4px;
            box-shadow: inset 0 0 0 1px rgba(255, 255, 255, .22);
        }

        .admin-shell .text-blue-600,
        .admin-shell .text-blue-700 { color: #922c24 !important; }
        .admin-shell .bg-blue-50 { background-color: #fbf4f3 !important; }
        .admin-shell .bg-blue-100 { background-color: #f5e5e2 !important; }
        .admin-shell .bg-blue-600 { background-color: #a6322a !important; }
        .admin-shell .border-blue-100 { border-color: #f1d9d5 !important; }
        .admin-shell .border-blue-200 { border-color: #e8c1bc !important; }
        .admin-shell .hover\:bg-blue-50:hover { background-color: #fbf4f3 !important; }
        .admin-shell .hover\:bg-blue-700:hover { background-color: #842820 !important; }
        .admin-shell .hover\:text-blue-700:hover { color: #842820 !important; }
        .admin-shell .focus\:border-blue-500:focus { border-color: #a6322a !important; }
        .admin-shell .focus\:ring-blue-500:focus { --tw-ring-color: rgba(166, 50, 42, .28) !important; }
    </style>
    @stack('styles')
</head>
<body class="admin-shell bg-gray-50">
@include('admin.partials.header', [
    'adminBrandName' => $adminBrandName,
    'adminSiteName' => $adminSiteName ?? $adminBrandName,
    'pageTitle' => $pageTitle ?? '',
    'activeMenu' => $activeMenu ?? '',
])
    <main class="max-w-7xl mx-auto py-6 sm:px-6 lg:px-8">
        @if (session('message'))
            <div class="admin-flash-alert mb-4 bg-green-100 border border-green-400 text-green-700 px-4 py-3 rounded relative">
                <span class="block sm:inline">{{ session('message') }}</span>
            </div>
        @endif
        @if ($errors->any())
            <div class="admin-flash-alert mb-4 bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded relative">
                @foreach ($errors->all() as $err)
                    <div>{{ $err }}</div>
                @endforeach
            </div>
        @endif
        @yield('content')
    </main>
@include('admin.partials.footer')
@include('admin.partials.welcome-modal')
@vite('resources/js/app.js')
@stack('scripts')
</body>
</html>
