<?php

declare(strict_types=1);

namespace AiSdk\XAI\Models;

use AiSdk\Capability;
use AiSdk\CapabilitySupport;
use AiSdk\Contracts\BaseModel;
use AiSdk\Contracts\SpeechModelInterface;
use AiSdk\Requests\SpeechRequest;
use AiSdk\Responses\SpeechResponse;
use AiSdk\Results\AudioData;
use AiSdk\Support\ModelCatalog;
use AiSdk\Support\ModelRegistry;
use AiSdk\Support\Usage;
use AiSdk\Utils\Support\Url;
use AiSdk\XAI\XAIOptions;

final class XAISpeechModel extends BaseModel implements SpeechModelInterface
{
    public function __construct(
        private readonly string $modelId,
        private readonly XAIOptions $options,
        private readonly ?ModelRegistry $registry = null,
    ) {}

    public function provider(): string
    {
        return XAIOptions::PROVIDER_NAME;
    }

    public function modelId(): string
    {
        return $this->modelId;
    }

    /**
     * @return array<int, Capability>
     */
    public function capabilities(): array
    {
        $definition = $this->registry?->resolve($this->provider(), $this->modelId);
        if ($definition !== null) {
            return $this->configuredCapabilities($definition->capabilities);
        }

        return $this->configuredCapabilities($this->catalog()->capabilities($this->modelId));
    }

    public function capability(Capability $capability): CapabilitySupport
    {
        $configured = $this->configuredCapability($capability);
        if ($configured !== null) {
            return $configured;
        }

        $registered = $this->registry?->capability($this->provider(), $this->modelId, $capability);
        if ($registered !== null) {
            return $registered;
        }

        return $this->catalog()->capability($this->modelId, $capability);
    }

    public function generate(SpeechRequest $request): SpeechResponse
    {
        $body = $this->buildBody($request);
        $format = $request->format ?? (string) ($body['output_format']['codec'] ?? 'mp3');
        $url = Url::joinPath($this->options->baseUrl, '/tts');

        $response = $this->runner($this->options->sdk)
            ->postRaw($url, $body, array_replace(['Accept' => $this->expectedMimeType($format)], $this->options->authHeaders()), $this->provider());

        return new SpeechResponse(
            audio: new AudioData(
                data: (string) $response->getBody(),
                mimeType: $response->getHeaderLine('Content-Type') ?: $this->expectedMimeType($format),
            ),
            usage: Usage::empty(),
            rawResponse: [],
            providerMetadata: [$this->provider() => ['model' => $this->modelId, 'format' => $format]],
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function buildBody(SpeechRequest $request): array
    {
        $body = [
            'text' => $request->input,
            'voice_id' => $request->voice ?? 'eve',
            'language' => 'en',
        ];

        if ($request->format !== null) {
            $body['output_format'] = ['codec' => $request->format];
        }

        return array_replace($body, $request->providerOptionsFor($this->provider()));
    }

    private function expectedMimeType(string $format): string
    {
        return match ($format) {
            'wav' => 'audio/wav',
            'opus' => 'audio/opus',
            'flac' => 'audio/flac',
            'pcm' => 'audio/pcm',
            'mulaw' => 'audio/basic',
            'alaw' => 'audio/basic',
            default => 'audio/mpeg',
        };
    }

    private function catalog(): ModelCatalog
    {
        return ModelCatalog::fromFile(dirname(__DIR__, 2).'/resources/models.json');
    }
}
