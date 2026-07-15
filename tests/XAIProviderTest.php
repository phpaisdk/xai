<?php

declare(strict_types=1);

use AiSdk\Contracts\EmbeddingProviderInterface;
use AiSdk\Contracts\ImageProviderInterface;
use AiSdk\Contracts\LiveProviderInterface;
use AiSdk\Contracts\SpeechProviderInterface;
use AiSdk\Contracts\TextProviderInterface;
use AiSdk\Contracts\TranscriptionProviderInterface;
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
        ->toBeInstanceOf(TranscriptionProviderInterface::class)
        ->toBeInstanceOf(EmbeddingProviderInterface::class)
        ->toBeInstanceOf(VideoProviderInterface::class)
        ->toBeInstanceOf(LiveProviderInterface::class)
        ->and($provider->model('grok-4.3')->modelId())->toBe('grok-4.3')
        ->and(XAI::model('v1')->modelId())->toBe('v1');

    expect(is_callable([$provider, 'textModel']))->toBeFalse()
        ->and(is_callable([$provider, 'imageModel']))->toBeFalse()
        ->and(is_callable([$provider, 'speechModel']))->toBeFalse()
        ->and(is_callable([$provider, 'transcriptionModel']))->toBeFalse()
        ->and(is_callable([$provider, 'embeddingModel']))->toBeFalse()
        ->and(is_callable([$provider, 'videoModel']))->toBeFalse()
        ->and(is_callable([$provider, 'liveModel']))->toBeFalse();
});
