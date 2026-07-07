<?php

declare(strict_types=1);

use AiSdk\Capability;
use AiSdk\Generate;
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

it('loads model capabilities from resources models json', function () {
    XAI::create(['apiKey' => 'xai-test']);

    expect(XAI::model('grok-4.3')->supports(Capability::Reasoning))->toBeTrue()
        ->and(XAI::model('grok-2-vision')->supports(Capability::ImageInput))->toBeTrue();
});
