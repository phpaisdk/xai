<?php

declare(strict_types=1);

namespace AiSdk\XAI;

use AiSdk\Contracts\BaseProvider;
use AiSdk\Contracts\EmbeddingModelInterface;
use AiSdk\Contracts\EmbeddingProviderInterface;
use AiSdk\Contracts\ImageModelInterface;
use AiSdk\Contracts\SpeechModelInterface;
use AiSdk\Contracts\TextModelInterface;
use AiSdk\XAI\Models\XAIEmbeddingModel;
use AiSdk\XAI\Models\XAIImageModel;
use AiSdk\XAI\Models\XAISpeechModel;
use AiSdk\XAI\Models\XAITextModel;

final class XAIProvider extends BaseProvider implements EmbeddingProviderInterface
{
    public function __construct(public readonly XAIOptions $options) {}

    public function name(): string
    {
        return XAIOptions::PROVIDER_NAME;
    }

    public function textModel(string $modelId): TextModelInterface
    {
        return new XAITextModel($modelId, $this->options);
    }

    public function imageModel(string $modelId): ImageModelInterface
    {
        return new XAIImageModel($modelId, $this->options);
    }

    public function speechModel(string $modelId): SpeechModelInterface
    {
        return new XAISpeechModel($modelId, $this->options);
    }

    public function embeddingModel(string $modelId): EmbeddingModelInterface
    {
        return new XAIEmbeddingModel($modelId, $this->options);
    }
}
