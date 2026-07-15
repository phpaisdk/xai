<?php

declare(strict_types=1);

use AiSdk\Generate;
use AiSdk\Support\Sdk;
use AiSdk\XAI;
use AiSdk\XAI\Tests\Fakes\FakeHttpClient;
use Nyholm\Psr7\Factory\Psr17Factory;

afterEach(function () {
    Generate::reset();
    XAI::reset();
});

it('generates embeddings with a model id discovered from xAI model metadata', function () {
    $discoveredEmbeddingModelId = 'v1';
    $client = new FakeHttpClient(200, json_encode([
        'object' => 'list',
        'model' => $discoveredEmbeddingModelId,
        'data' => [['object' => 'embedding', 'index' => 0, 'embedding' => [0.1, 0.2]]],
        'usage' => ['prompt_tokens' => 5, 'total_tokens' => 5],
    ]));
    $factory = new Psr17Factory;
    Generate::configure(new Sdk($client, $factory, $factory));
    XAI::create(['apiKey' => 'xai-test']);

    $result = Generate::embedding('query: PHP AI SDK')
        ->model(XAI::model($discoveredEmbeddingModelId))
        ->dimensions(512)
        ->providerOptions('xai', ['user' => 'user-123'])
        ->run();

    expect($result->output->vector)->toBe([0.1, 0.2])
        ->and($result->usage->inputTokens)->toBe(5)
        ->and($client->lastRequest?->getUri()->getPath())->toBe('/v1/embeddings')
        ->and($client->sentBody())->toMatchArray([
            'model' => $discoveredEmbeddingModelId,
            'input' => ['query: PHP AI SDK'],
            'encoding_format' => 'float',
            'dimensions' => 512,
            'user' => 'user-123',
        ]);
});
