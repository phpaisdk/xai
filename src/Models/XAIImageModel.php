<?php

declare(strict_types=1);

namespace AiSdk\XAI\Models;

use AiSdk\Contracts\BaseModel;
use AiSdk\Contracts\ImageModelInterface;
use AiSdk\OpenAICompatible\ImageRequestBuilder;
use AiSdk\OpenAICompatible\ImageResponseParser;
use AiSdk\Requests\ImageRequest;
use AiSdk\Responses\ImageResponse;
use AiSdk\Utils\Support\Url;
use AiSdk\XAI\XAIOptions;

final class XAIImageModel extends BaseModel implements ImageModelInterface
{
    public function __construct(
        private readonly string $modelId,
        private readonly XAIOptions $options,
    ) {}

    public function provider(): string
    {
        return XAIOptions::PROVIDER_NAME;
    }

    public function modelId(): string
    {
        return $this->modelId;
    }

    public function generate(ImageRequest $request): ImageResponse
    {
        $body = ImageRequestBuilder::build(
            $this->modelId,
            $this->provider(),
            $request,
            [
                'aspectRatioParameter' => 'aspect_ratio',
                'inferSizeFromAspectRatio' => false,
                'sizeParameter' => null,
                'seedParameter' => null,
            ],
        );
        $url = Url::joinPath($this->options->baseUrl, '/images/generations');

        $payload = $this->runner($this->options->sdk)
            ->postJson($url, $body, $this->options->authHeaders(), $this->provider());

        return ImageResponseParser::parse($payload, $this->provider());
    }
}
