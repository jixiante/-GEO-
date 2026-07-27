<?php

namespace App\Http\Requests\Admin;

use App\Models\TitleLibrary;
use App\Services\GeoFlow\TaskDistributionChannelSelector;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SaveTaskRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user('admin') !== null;
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'task_name' => ['required', 'string', 'max:200'],
            'title_library_id' => ['required', 'integer', 'min:1', 'exists:title_libraries,id'],
            'prompt_id' => ['required', 'integer', 'min:1'],
            'ai_model_id' => ['required', 'integer', 'min:1'],
            'author_id' => ['nullable', 'integer', 'min:0'],
            'image_library_id' => ['nullable', 'integer', 'min:1'],
            'image_count' => ['nullable', 'integer', 'min:0', 'max:5'],
            'knowledge_base_id' => ['nullable', 'integer', 'min:1', 'exists:knowledge_bases,id'],
            'knowledge_base_ids' => ['nullable', 'array', 'max:5'],
            'knowledge_base_ids.*' => ['integer', 'min:1', 'distinct', 'exists:knowledge_bases,id'],
            'fixed_category_id' => ['nullable', 'integer', 'min:1'],
            'status' => ['required', 'string', 'in:active,paused'],
            'article_limit' => ['nullable', 'integer', 'min:1', 'max:99999'],
            'draft_limit' => ['nullable', 'integer', 'min:1', 'max:9999'],
            'publish_interval' => ['nullable', 'integer', 'min:1'],
            'category_mode' => ['nullable', 'string', 'in:smart,fixed,random'],
            'model_selection_mode' => ['nullable', 'string', 'in:fixed,smart_failover'],
            'publish_scope' => ['nullable', 'string', 'in:local_and_distribution,distribution_only,local_only'],
            'distribution_strategy' => ['nullable', 'string', Rule::in(TaskDistributionChannelSelector::strategies())],
            'distribution_channel_ids' => ['nullable', 'array'],
            'distribution_channel_ids.*' => ['integer', 'min:1'],
        ];
    }

    /** @return list<callable> */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if ($validator->errors()->has('title_library_id')) {
                    return;
                }

                $hasTitles = TitleLibrary::query()
                    ->whereKey($this->integer('title_library_id'))
                    ->whereHas('titles')
                    ->exists();

                if (! $hasTitles) {
                    $validator->errors()->add(
                        'title_library_id',
                        __('admin.task_create.error.title_library_empty')
                    );
                }
            },
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'title_library_id.required' => __('admin.task_create.error.title_library_required'),
            'title_library_id.exists' => __('admin.task_create.error.title_library_missing'),
        ];
    }
}
