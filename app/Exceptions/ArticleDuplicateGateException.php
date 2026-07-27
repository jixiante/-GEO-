<?php

namespace App\Exceptions;

use App\Models\ArticleDuplicateScan;
use RuntimeException;

class ArticleDuplicateGateException extends RuntimeException
{
    public readonly string $duplicateStatus;

    public function __construct(public readonly ArticleDuplicateScan $scan)
    {
        $this->duplicateStatus = (string) $scan->status;

        parent::__construct("Article duplicate gate rejected status [{$this->duplicateStatus}].");
    }
}
