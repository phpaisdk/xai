<?php

declare(strict_types=1);

namespace AiSdk\XAI;

use AiSdk\Contracts\BaseProvider;
use AiSdk\Contracts\EmbeddingModelInterface;
use AiSdk\Contracts\EmbeddingProviderInterface;
use AiSdk\Contracts\ImageModelInterface;
use AiSdk\Contracts\ImageProviderInterface;
use AiSdk\Contracts\SpeechModelInterface;
use AiSdk\Contracts\SpeechProviderInterface;
use AiSdk\Contracts\TextModelInterface;
use AiSdk\Contracts\TextProviderInterface;
use AiSdk\Contracts\TranscriptionModelInterface;
use AiSdk\Contracts\TranscriptionProviderInterface;
use AiSdk\Contracts\VideoModelInterface;
use AiSdk\Contracts\VideoProviderInterface;
use AiSdk\XAI\Models\XAIEmbeddingModel;
use AiSdk\XAI\Models\XAIImageModel;
use AiSdk\XAI\Models\XAISpeechModel;
use AiSdk\XAI\Models\XAITextModel;
use AiSdk\XAI\Models\XAITranscriptionModel;
use AiSdk\XAI\Models\XAIVideoModel;

final class XAIProvider extends BaseProvider implements EmbeddingProviderInterface, ImageProviderInterface, SpeechProviderInterface, TextProviderInterface, TranscriptionProviderInterface, VideoProviderInterface
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

    public function transcriptionModel(string $modelId): TranscriptionModelInterface
    {
        return new XAITranscriptionModel($modelId, $this->options);
    }

    public function videoModel(string $modelId): VideoModelInterface
    {
        return new XAIVideoModel($modelId, $this->options);
    }

    public function embeddingModel(string $modelId): EmbeddingModelInterface
    {
        return new XAIEmbeddingModel($modelId, $this->options);
    }
}
