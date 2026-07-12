<?php

declare(strict_types=1);

use AiSdk\Contracts\EmbeddingProviderInterface;
use AiSdk\Contracts\ImageProviderInterface;
use AiSdk\Contracts\SpeechProviderInterface;
use AiSdk\Contracts\TextProviderInterface;
use AiSdk\Contracts\VideoProviderInterface;
use AiSdk\XAI;

afterEach(function () {
    XAI::reset();
});

it('declares every currently supported xAI provider modality', function () {
    $provider = XAI::create(['apiKey' => 'xai-test']);

    expect($provider)->toBeInstanceOf(TextProviderInterface::class)
        ->toBeInstanceOf(ImageProviderInterface::class)
        ->toBeInstanceOf(SpeechProviderInterface::class)
        ->toBeInstanceOf(EmbeddingProviderInterface::class)
        ->toBeInstanceOf(VideoProviderInterface::class)
        ->and($provider->textModel('grok-4.3')->modelId())->toBe('grok-4.3')
        ->and($provider->imageModel('grok-imagine-image-quality')->modelId())->toBe('grok-imagine-image-quality')
        ->and($provider->speechModel('grok-voice')->modelId())->toBe('grok-voice')
        ->and($provider->embeddingModel('v1')->modelId())->toBe('v1')
        ->and(XAI::embedding('v1')->modelId())->toBe('v1');
});
