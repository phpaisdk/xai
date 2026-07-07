<?php

declare(strict_types=1);

namespace AiSdk;

use AiSdk\Contracts\ImageModelInterface;
use AiSdk\Contracts\TextModelInterface;
use AiSdk\Support\Concerns\RegistersModels;
use AiSdk\XAI\XAIOptions;
use AiSdk\XAI\XAIProvider;

final class XAI
{
    use RegistersModels;

    private static ?XAIProvider $default = null;

    /**
     * @param  array<string, mixed>  $config
     */
    public static function create(array $config = []): XAIProvider
    {
        return self::$default = new XAIProvider(XAIOptions::fromArray($config));
    }

    public static function default(): XAIProvider
    {
        return self::$default ??= self::create();
    }

    public static function reset(): void
    {
        self::$default = null;
    }

    public static function model(string $modelId): TextModelInterface
    {
        return self::default()->textModel($modelId);
    }

    public static function image(string $modelId): ImageModelInterface
    {
        return self::default()->imageModel($modelId);
    }
}
