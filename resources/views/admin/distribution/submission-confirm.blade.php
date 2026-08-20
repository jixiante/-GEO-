@extends('admin.layouts.app')

@section('content')
    <div class="px-4 sm:px-0">
        <div class="mb-8 flex items-center gap-4">
            <a href="{{ route('admin.distribution.show', ['channelId' => (int) $channel->id]) }}" class="text-gray-400 hover:text-gray-600">
                <i data-lucide="arrow-left" class="h-5 w-5"></i>
            </a>
            <div>
                <h1 class="text-2xl font-bold text-gray-900">{{ __('admin.distribution.submission_confirmation.title') }}</h1>
                <p class="mt-1 text-sm text-gray-600">{{ __('admin.distribution.submission_confirmation.desc') }}</p>
            </div>
        </div>

        <div class="rounded-lg bg-white shadow">
            <div class="border-b border-gray-200 px-6 py-5">
                <dl class="grid grid-cols-1 gap-4 text-sm md:grid-cols-3">
                    <div>
                        <dt class="text-gray-500">{{ __('admin.distribution.field.article') }}</dt>
                        <dd class="mt-1 break-words font-medium text-gray-900">{{ $article->title }}</dd>
                    </div>
                    <div>
                        <dt class="text-gray-500">{{ __('admin.distribution.field.channel') }}</dt>
                        <dd class="mt-1 font-medium text-gray-900">{{ $channel->name }}</dd>
                    </div>
                    <div>
                        <dt class="text-gray-500">{{ __('admin.distribution.submission_confirmation.platform_status') }}</dt>
                        <dd class="mt-1 font-medium text-amber-700">{{ $platform === 'toutiao' ? '审核中' : '已发布' }}</dd>
                    </div>
                </dl>
            </div>

            <form method="POST" action="{{ route('admin.distribution.article.submission.update', ['distributionId' => (int) $distribution->id]) }}" class="space-y-6 px-6 py-6">
                @csrf
                @method('PUT')

                @if ($platform === 'toutiao')
                    <div>
                        <label for="remote_id" class="block text-sm font-medium text-gray-700">{{ __('admin.distribution.submission_confirmation.remote_id_label') }}</label>
                        <input id="remote_id" name="remote_id" type="text" required maxlength="80" inputmode="numeric" value="{{ old('remote_id') }}" class="mt-1 block w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500">
                    </div>
                    <div>
                        <label for="management_url" class="block text-sm font-medium text-gray-700">{{ __('admin.distribution.submission_confirmation.management_url_label') }}</label>
                        <input id="management_url" name="management_url" type="url" required maxlength="500" value="{{ old('management_url') }}" placeholder="https://mp.toutiao.com/profile_v4/graphic/preview?pgc_id=..." class="mt-1 block w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500">
                        <p class="mt-2 text-sm text-gray-500">{{ __('admin.distribution.submission_confirmation.management_url_help') }}</p>
                    </div>
                @else
                    <p class="rounded-md border border-blue-200 bg-blue-50 p-4 text-sm text-blue-900">{{ __('admin.distribution.submission_confirmation.sohu_help') }}</p>
                @endif

                @error('submission')
                    <p class="text-sm text-red-600">{{ $message }}</p>
                @enderror
                @error('remote_id')
                    <p class="text-sm text-red-600">{{ $message }}</p>
                @enderror
                @error('management_url')
                    <p class="text-sm text-red-600">{{ $message }}</p>
                @enderror

                <label class="flex items-start gap-3 rounded-md border border-amber-200 bg-amber-50 p-4">
                    <input name="confirmed" type="checkbox" value="1" required @checked(old('confirmed')) class="mt-0.5 rounded border-gray-300 text-blue-600 focus:ring-blue-500">
                    <span class="text-sm text-amber-900">{{ __('admin.distribution.submission_confirmation.confirm_label') }}</span>
                </label>
                @error('confirmed')
                    <p class="text-sm text-red-600">{{ $message }}</p>
                @enderror

                <div class="flex justify-end gap-3">
                    <a href="{{ route('admin.distribution.show', ['channelId' => (int) $channel->id]) }}" class="inline-flex items-center rounded-md border border-gray-300 bg-white px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50">{{ __('admin.button.cancel') }}</a>
                    <button type="submit" class="inline-flex items-center rounded-md border border-transparent bg-blue-600 px-4 py-2 text-sm font-medium text-white hover:bg-blue-700">{{ __('admin.distribution.submission_confirmation.submit') }}</button>
                </div>
            </form>
        </div>
    </div>
@endsection
