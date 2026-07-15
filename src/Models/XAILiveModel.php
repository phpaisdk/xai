<?php

declare(strict_types=1);

namespace AiSdk\XAI\Models;

use AiSdk\Contracts\BaseModel;
use AiSdk\Exceptions\InvalidArgumentException;
use AiSdk\Live\ClientSecret;
use AiSdk\Live\Contracts\LiveCallModelInterface;
use AiSdk\Live\Contracts\LiveClientSecretModelInterface;
use AiSdk\Live\Contracts\LiveSessionDriverInterface;
use AiSdk\Live\Contracts\ProviderLiveCallInterface;
use AiSdk\Live\Contracts\TransportInterface;
use AiSdk\Live\LiveOperation;
use AiSdk\Live\LiveRequest;
use AiSdk\XAI\Live\XAILiveCall;
use AiSdk\XAI\Live\XAILiveConfiguration;
use AiSdk\XAI\Live\XAILiveHttp;
use AiSdk\XAI\Live\XAILiveSessionDriver;
use AiSdk\XAI\Live\XAIStreamingTranscriptionDriver;
use AiSdk\XAI\XAIOptions;

final class XAILiveModel extends BaseModel implements LiveCallModelInterface, LiveClientSecretModelInterface
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

    public function createLiveSession(LiveRequest $request, TransportInterface $transport): LiveSessionDriverInterface
    {
        return match ($request->operation) {
            LiveOperation::Voice => new XAILiveSessionDriver(
                modelId: $this->modelId,
                options: $this->options,
                request: $request,
                transport: $transport,
            ),
            LiveOperation::Transcribe => new XAIStreamingTranscriptionDriver(
                options: $this->options,
                request: $request,
                transport: $transport,
            ),
            LiveOperation::Translate => throw new InvalidArgumentException('xAI does not provide a dedicated Live translation session.'),
        };
    }

    public function clientSecret(LiveRequest $request): ClientSecret
    {
        if ($request->operation !== LiveOperation::Voice) {
            throw new InvalidArgumentException('xAI client secrets are supported only for Voice Agent sessions.');
        }

        $payload = XAILiveHttp::postJson(
            $this->options,
            '/realtime/client_secrets',
            XAILiveConfiguration::clientSecretBody($request),
        );
        $secret = $payload['value'] ?? null;

        if (! is_string($secret) || $secret === '') {
            throw new InvalidArgumentException('xAI did not return a usable Voice Agent client secret.');
        }

        return new ClientSecret(
            value: $secret,
            expiresAt: is_int($payload['expires_at'] ?? null) ? $payload['expires_at'] : null,
            raw: $payload,
        );
    }

    public function call(LiveRequest $request, string $callId): ProviderLiveCallInterface
    {
        if ($request->operation !== LiveOperation::Voice) {
            throw new InvalidArgumentException('xAI provider-hosted call control is available only for voice sessions.');
        }

        return new XAILiveCall($callId, $this->modelId, $this->options, $request);
    }
}
