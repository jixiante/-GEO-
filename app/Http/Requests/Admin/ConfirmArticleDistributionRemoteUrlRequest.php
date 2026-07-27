<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class ConfirmArticleDistributionRemoteUrlRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user('admin')?->canManageProtectedWorkflows() === true;
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            'remote_url' => ['required', 'string', 'max:500', 'url:http,https'],
            'confirmed' => ['accepted'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'remote_url.required' => __('admin.distribution.remote_url_confirmation.validation.required'),
            'remote_url.max' => __('admin.distribution.remote_url_confirmation.validation.max'),
            'remote_url.url' => __('admin.distribution.remote_url_confirmation.validation.url'),
            'confirmed.accepted' => __('admin.distribution.remote_url_confirmation.validation.confirmed'),
        ];
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('remote_url'))) {
            $this->merge(['remote_url' => trim((string) $this->input('remote_url'))]);
        }
    }
}
