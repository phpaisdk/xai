<?php

declare(strict_types=1);

use AiSdk\Content;
use AiSdk\Exceptions\CapabilityNotSupportedException;
use AiSdk\Generate;
use AiSdk\InputEncoding;
use AiSdk\Message;
use AiSdk\Reasoning;
use AiSdk\Support\Sdk;
use AiSdk\XAI;
use AiSdk\XAI\Tests\Fakes\FakeHttpClient;
use Nyholm\Psr7\Factory\Psr17Factory;

afterEach(function () {
    Generate::reset();
    XAI::reset();
});

function configureXAIWith(FakeHttpClient $client): void
{
    $factory = new Psr17Factory;
    Generate::configure(new Sdk(
        httpClient: $client,
        requestFactory: $factory,
        streamFactory: $factory,
    ));
}

it('generates text end to end through the XAI vertical', function () {
    $client = new FakeHttpClient(200, json_encode([
        'id' => 'chatcmpl_xai',
        'object' => 'chat.completion',
        'created' => 1710000000,
        'model' => 'grok-4.3',
        'choices' => [['index' => 0, 'message' => ['content' => 'Hello from xAI'], 'finish_reason' => 'stop']],
        'usage' => ['prompt_tokens' => 8, 'completion_tokens' => 4],
    ]));
    configureXAIWith($client);

    XAI::create(['apiKey' => 'xai-test']);

    $result = Generate::text('Hi')->model(XAI::model('grok-4.3'))->run();

    expect($result->text)->toBe('Hello from xAI')
        ->and($result->usage->inputTokens)->toBe(8)
        ->and($result->providerMetadata['xai']['id'])->toBe('chatcmpl_xai')
        ->and($result->providerMetadata['xai']['model'])->toBe('grok-4.3');

    $body = $client->sentBody();
    expect($body['model'])->toBe('grok-4.3')
        ->and($body['messages'][0]['role'])->toBe('user')
        ->and($body['stream'])->toBeFalse();

    expect($client->lastRequest->getUri()->getPath())->toBe('/v1/chat/completions')
        ->and($client->lastRequest->getHeaderLine('Authorization'))->toBe('Bearer xai-test');
});

it('generates images through the XAI vertical', function () {
    $client = new FakeHttpClient(200, json_encode([
        'created' => 1710000000,
        'data' => [['b64_json' => base64_encode('jpeg-bytes')]],
        'usage' => ['prompt_tokens' => 6, 'completion_tokens' => 8, 'total_tokens' => 14],
    ]));
    configureXAIWith($client);

    XAI::create(['apiKey' => 'xai-test']);

    $result = Generate::image()
        ->model(XAI::model('grok-imagine-image-quality'))
        ->prompt('A futuristic skyline')
        ->count(2)
        ->aspectRatio('16:9')
        ->providerOptions('xai', ['raw' => ['resolution' => '2k']])
        ->run();

    expect($result->output->base64)->toBe(base64_encode('jpeg-bytes'))
        ->and($result->usage->totalTokens)->toBe(14);

    $body = $client->sentBody();
    expect($body)->toMatchArray([
        'model' => 'grok-imagine-image-quality',
        'prompt' => 'A futuristic skyline',
        'n' => 2,
        'response_format' => 'b64_json',
        'aspect_ratio' => '16:9',
        'resolution' => '2k',
    ])->and($body)->not->toHaveKey('size');

    expect($client->lastRequest->getUri()->getPath())->toBe('/v1/images/generations')
        ->and($client->lastRequest->getHeaderLine('Authorization'))->toBe('Bearer xai-test');
});

it('generates speech through the XAI vertical', function () {
    $client = new FakeHttpClient(200, 'audio-bytes', 'audio/mpeg');
    configureXAIWith($client);

    XAI::create(['apiKey' => 'xai-test']);

    $result = Generate::speech()
        ->model(XAI::model('grok-voice'))
        ->input('Hello from xAI voice.')
        ->voice('eve')
        ->format('mp3')
        ->providerOptions('xai', ['language' => 'auto', 'speed' => 1.1])
        ->run();

    expect($result->output->data)->toBe('audio-bytes')
        ->and($result->output->mimeType)->toBe('audio/mpeg')
        ->and($result->providerMetadata['xai']['model'])->toBe('grok-voice');

    $body = $client->sentBody();
    expect($body)->toMatchArray([
        'text' => 'Hello from xAI voice.',
        'voice_id' => 'eve',
        'language' => 'auto',
        'output_format' => ['codec' => 'mp3'],
        'speed' => 1.1,
    ]);

    expect($client->lastRequest->getUri()->getPath())->toBe('/v1/tts')
        ->and($client->lastRequest->getHeaderLine('Accept'))->toBe('audio/mpeg')
        ->and($client->lastRequest->getHeaderLine('Authorization'))->toBe('Bearer xai-test');
});

it('maps portable reasoning effort onto the OpenAI-compatible request shape', function () {
    $client = new FakeHttpClient(200, json_encode([
        'choices' => [['message' => ['content' => 'Done'], 'finish_reason' => 'stop']],
    ]));
    configureXAIWith($client);
    XAI::create(['apiKey' => 'xai-test']);

    Generate::text('Think briefly.')
        ->model(XAI::model('grok-4.3'))
        ->reasoning(Reasoning::effort('low'))
        ->run();

    expect($client->sentBody()['reasoning_effort'])->toBe('low');
});

it('accepts opaque model ids for every implemented modality', function () {
    XAI::create(['apiKey' => 'xai-test']);

    expect(XAI::model('future-text-model')->modelId())->toBe('future-text-model')
        ->and(XAI::model('future-image-model')->modelId())->toBe('future-image-model')
        ->and(XAI::model('future-speech-model')->modelId())->toBe('future-speech-model');
});

it('rejects unsupported audio input before sending an xAI request', function () {
    $client = new FakeHttpClient(200, json_encode([]));
    configureXAIWith($client);
    XAI::create(['apiKey' => 'xai-test']);

    expect(fn () => Generate::text()
        ->messages([
            Message::user([
                Content::text('Transcribe this.'),
                Content::audio('UklGRg==', mimeType: 'audio/wav', encoding: InputEncoding::Base64),
            ]),
        ])
        ->model(XAI::model('grok-4.3'))
        ->run())
        ->toThrow(CapabilityNotSupportedException::class);

    expect($client->lastRequest)->toBeNull();
});
