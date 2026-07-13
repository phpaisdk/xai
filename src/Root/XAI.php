<?php

declare(strict_types=1);

namespace AiSdk;

use AiSdk\Contracts\EmbeddingModelInterface;
use AiSdk\Contracts\ImageModelInterface;
use AiSdk\Contracts\SpeechModelInterface;
use AiSdk\Contracts\TextModelInterface;
use AiSdk\Contracts\TranscriptionModelInterface;
use AiSdk\Contracts\VideoModelInterface;
use AiSdk\XAI\XAIOptions;
use AiSdk\XAI\XAIProvider;

final class XAI
{
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

    public static function speech(string $modelId): SpeechModelInterface
    {
        return self::default()->speechModel($modelId);
    }

    public static function transcription(string $modelId = 'grok-transcribe'): TranscriptionModelInterface
    {
        return self::default()->transcriptionModel($modelId);
    }

    public static function video(string $modelId): VideoModelInterface
    {
        return self::default()->videoModel($modelId);
    }

    public static function embedding(string $modelId): EmbeddingModelInterface
    {
        return self::default()->embeddingModel($modelId);
    }
}
