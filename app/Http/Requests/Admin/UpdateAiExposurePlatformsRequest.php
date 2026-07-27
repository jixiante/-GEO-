<?php

namespace App\Http\Requests\Admin;

use App\Support\AiExposure\AiExposurePlatformCatalog;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateAiExposurePlatformsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user('admin')?->canManageProtectedWorkflows() === true;
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'platforms' => ['required', 'array', 'size:'.count(AiExposurePlatformCatalog::keys())],
            'platforms.*' => ['required', 'array:enabled,ai_model_id'],
            'platforms.*.enabled' => ['required', 'boolean'],
            'platforms.*.ai_model_id' => [
                'nullable',
                'integer',
                Rule::exists('ai_models', 'id')->where(function ($query): void {
                    $query->where('status', 'active')
                        ->where(function ($typeQuery): void {
                            $typeQuery->whereNull('model_type')
                                ->orWhere('model_type', '')
                                ->orWhere('model_type', 'chat');
                        });
                }),
            ],
        ];
    }

    /** @return list<callable> */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $platforms = $this->input('platforms', []);
                if (! is_array($platforms)) {
                    return;
                }

                $allowed = AiExposurePlatformCatalog::keys();
                foreach (array_keys($platforms) as $platform) {
                    if (! in_array((string) $platform, $allowed, true)) {
                        $validator->errors()->add('platforms', 'Unsupported AI platform.');
                    }
                }

                foreach ($allowed as $platform) {
                    $config = $platforms[$platform] ?? null;
                    if (! is_array($config)) {
                        $validator->errors()->add('platforms.'.$platform, 'Platform configuration is required.');

                        continue;
                    }
                    if ((bool) ($config['enabled'] ?? false) && empty($config['ai_model_id'])) {
                        $validator->errors()->add(
                            'platforms.'.$platform.'.ai_model_id',
                            'Select a chat model before enabling this platform.'
                        );
                    }
                }
            },
        ];
    }
}
