<?php

declare(strict_types=1);

namespace AiSdk\XAI;

use AiSdk\Contracts\BaseProvider;
use AiSdk\Contracts\EmbeddingModelInterface;
use AiSdk\Contracts\EmbeddingProviderInterface;
use AiSdk\Contracts\ImageModelInterface;
use AiSdk\Contracts\ImageProviderInterface;
use AiSdk\Contracts\LiveProviderInterface;
use AiSdk\Contracts\SpeechModelInterface;
use AiSdk\Contracts\SpeechProviderInterface;
use AiSdk\Contracts\TextModelInterface;
use AiSdk\Contracts\TextProviderInterface;
use AiSdk\Contracts\TranscriptionModelInterface;
use AiSdk\Contracts\TranscriptionProviderInterface;
use AiSdk\Contracts\VideoModelInterface;
use AiSdk\Contracts\VideoProviderInterface;
use AiSdk\Live\Contracts\LiveModelInterface;
use AiSdk\XAI\Models\XAIEmbeddingModel;
use AiSdk\XAI\Models\XAIImageModel;
use AiSdk\XAI\Models\XAILiveModel;
use AiSdk\XAI\Models\XAISpeechModel;
use AiSdk\XAI\Models\XAITextModel;
use AiSdk\XAI\Models\XAITranscriptionModel;
use AiSdk\XAI\Models\XAIVideoModel;

final class XAIProvider extends BaseProvider implements EmbeddingProviderInterface, ImageProviderInterface, LiveProviderInterface, SpeechProviderInterface, TextProviderInterface, TranscriptionProviderInterface, VideoProviderInterface
{
    public function __construct(public readonly XAIOptions $options) {}

    public function name(): string
    {
        return XAIOptions::PROVIDER_NAME;
    }

    protected function textModel(string $modelId): TextModelInterface
    {
        return new XAITextModel($modelId, $this->options);
    }

    protected function imageModel(string $modelId): ImageModelInterface
    {
        return new XAIImageModel($modelId, $this->options);
    }

    protected function speechModel(string $modelId): SpeechModelInterface
    {
        return new XAISpeechModel($modelId, $this->options);
    }

    protected function transcriptionModel(string $modelId): TranscriptionModelInterface
    {
        return new XAITranscriptionModel($modelId, $this->options);
    }

    protected function videoModel(string $modelId): VideoModelInterface
    {
        return new XAIVideoModel($modelId, $this->options);
    }

    protected function embeddingModel(string $modelId): EmbeddingModelInterface
    {
        return new XAIEmbeddingModel($modelId, $this->options);
    }

    protected function liveModel(string $modelId): LiveModelInterface
    {
        return new XAILiveModel($modelId, $this->options);
    }
}
