<?php

namespace App\Contracts\AiExposure;

use App\Models\AiModel;

interface AiExposureAnswerProvider
{
    public function answer(AiModel $model, string $platform, string $question): string;
}
