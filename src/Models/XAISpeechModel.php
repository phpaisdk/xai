<?php

declare(strict_types=1);

namespace AiSdk\XAI\Models;

use AiSdk\Contracts\BaseModel;
use AiSdk\Contracts\SpeechModelInterface;
use AiSdk\Requests\SpeechRequest;
use AiSdk\Responses\SpeechResponse;
use AiSdk\Results\AudioData;
use AiSdk\Support\Usage;
use AiSdk\Utils\Support\Url;
use AiSdk\XAI\XAIOptions;

final class XAISpeechModel extends BaseModel implements SpeechModelInterface
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
}
