<?php

namespace App\Support\AiExposure;

final class AiExposurePlatformCatalog
{
    /** @var array<string, array{name:string, short_name:string}> */
    private const PLATFORMS = [
        'deepseek' => ['name' => 'DeepSeek', 'short_name' => 'DS'],
        'doubao' => ['name' => '豆包', 'short_name' => '豆包'],
        'kimi' => ['name' => 'Kimi', 'short_name' => 'Kimi'],
        'qwen' => ['name' => '千问', 'short_name' => '千问'],
        'ernie' => ['name' => '文心一言', 'short_name' => '文心'],
        'zhipu' => ['name' => '智谱', 'short_name' => 'GLM'],
    ];

    /** @return array<string, array{name:string, short_name:string}> */
    public static function all(): array
    {
        return self::PLATFORMS;
    }

    /** @return list<string> */
    public static function keys(): array
    {
        return array_keys(self::PLATFORMS);
    }

    public static function has(string $platform): bool
    {
        return array_key_exists($platform, self::PLATFORMS);
    }

    public static function name(string $platform): string
    {
        return self::PLATFORMS[$platform]['name'] ?? $platform;
    }
}
