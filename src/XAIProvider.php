<?php

declare(strict_types=1);

namespace AiSdk\XAI;

use AiSdk\Contracts\BaseProvider;
use AiSdk\Contracts\TextModelInterface;
use AiSdk\XAI\Models\XAITextModel;

final class XAIProvider extends BaseProvider
{
    public function __construct(public readonly XAIOptions $options) {}

    public function name(): string
    {
        return XAIOptions::PROVIDER_NAME;
    }

    public function textModel(string $modelId): TextModelInterface
    {
        return new XAITextModel($modelId, $this->options, $this->modelRegistry());
    }
}
