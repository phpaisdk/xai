<?php

declare(strict_types=1);

namespace AiSdk\XAI\Models;

use AiSdk\Contracts\BaseModel;
use AiSdk\Contracts\SpeechModelInterface;
use AiSdk\Exceptions\InvalidArgumentException;
use AiSdk\Exceptions\InvalidResponseException;
use AiSdk\Requests\SpeechRequest;
use AiSdk\Responses\SpeechResponse;
use AiSdk\Results\AudioData;
use AiSdk\Support\Json;
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
        $format = is_string($body['output_format']['codec'] ?? null) ? $body['output_format']['codec'] : ($request->format ?? 'mp3');
        $url = Url::joinPath($this->options->baseUrl, '/tts');

        $response = $this->runner($this->options->sdk)
            ->postRaw($url, $body, array_replace(['Accept' => ($body['with_timestamps'] ?? false) === true ? 'application/json' : $this->expectedMimeType($format)], $this->options->authHeaders()), $this->provider());

        $data = (string) $response->getBody();
        $mimeType = $response->getHeaderLine('Content-Type') ?: $this->expectedMimeType($format);
        $duration = null;
        $raw = [];
        $metadata = ['model' => $this->modelId, 'format' => $format];
        if (str_contains(strtolower($mimeType), 'application/json')) {
            $raw = Json::decode($data, 'xAI speech timestamps');
            $encoded = $raw['audio'] ?? null;
            $decoded = is_string($encoded) ? base64_decode($encoded, true) : false;
            if ($decoded === false || $decoded === '') {
                throw InvalidResponseException::forProvider($this->provider(), 'xAI returned no valid audio in its speech timestamp response.');
            }
            $data = $decoded;
            $mimeType = is_string($raw['content_type'] ?? null) ? $raw['content_type'] : $this->expectedMimeType($format);
            $duration = is_numeric($raw['duration'] ?? null) ? (float) $raw['duration'] : null;
            if (is_array($raw['audio_timestamps'] ?? null)) {
                $metadata['audioTimestamps'] = $raw['audio_timestamps'];
            }
        }

        return new SpeechResponse(
            audio: new AudioData(
                data: $data,
                mimeType: $mimeType,
                duration: $duration,
            ),
            usage: Usage::empty(),
            rawResponse: $raw,
            providerMetadata: [$this->provider() => $metadata],
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function buildBody(SpeechRequest $request): array
    {
        if ($request->format !== null && ! in_array($request->format, ['mp3', 'wav', 'pcm'], true)) {
            throw new InvalidArgumentException('xAI speech supports mp3, wav, and pcm through format(); use providerOptions output_format for mulaw or alaw.');
        }

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
            'alaw' => 'audio/alaw',
            default => 'audio/mpeg',
        };
    }
}
