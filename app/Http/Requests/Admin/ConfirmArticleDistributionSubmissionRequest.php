<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class ConfirmArticleDistributionSubmissionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user('admin')?->canManageProtectedWorkflows() === true;
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            'remote_id' => ['nullable', 'string', 'max:80'],
            'management_url' => ['nullable', 'string', 'max:500', 'url:https'],
            'confirmed' => ['accepted'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'remote_id.max' => __('admin.distribution.submission_confirmation.validation.remote_id'),
            'management_url.max' => __('admin.distribution.submission_confirmation.validation.management_url'),
            'management_url.url' => __('admin.distribution.submission_confirmation.validation.management_url'),
            'confirmed.accepted' => __('admin.distribution.submission_confirmation.validation.confirmed'),
        ];
    }

    protected function prepareForValidation(): void
    {
        foreach (['remote_id', 'management_url'] as $field) {
            if (is_string($this->input($field))) {
                $this->merge([$field => trim((string) $this->input($field))]);
            }
        }
    }
}
