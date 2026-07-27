<?php

namespace App\Http\Requests\Admin;

use App\Models\AiExposureMonitor;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreAiExposureMonitorRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user('admin')?->canManageProtectedWorkflows() === true;
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'article_id' => [
                'required',
                'integer',
                Rule::exists('articles', 'id')->whereNull('deleted_at'),
            ],
            'query' => ['required', 'string', 'max:500'],
            'frequency' => ['required', Rule::in(AiExposureMonitor::frequencies())],
        ];
    }
}
