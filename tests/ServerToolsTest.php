<?php

declare(strict_types=1);

use AiSdk\Content;
use AiSdk\Files\FileReference;
use AiSdk\Generate;
use AiSdk\Message;
use AiSdk\Support\Sdk;
use AiSdk\Tool;
use AiSdk\XAI;
use AiSdk\XAI\Tests\Fakes\FakeHttpClient;
use AiSdk\XAI\Tools;
use Nyholm\Psr7\Factory\Psr17Factory;

it('combines hosted and local tools on Responses without leaking internal options', function () {
    $http = new FakeHttpClient(200, json_encode(['status' => 'completed', 'output' => [['type' => 'message', 'content' => [['type' => 'output_text', 'text' => 'Found it']]]]]));
    $factory = new Psr17Factory();
    XAI::create(['apiKey' => 'test', 'sdk' => new Sdk($http, $factory, $factory)]);
    $result = Generate::text('Find')->model(XAI::model('opaque'))->tools(Tool::make('local')->run(fn() => 'ok'))
        ->providerOptions('xai', ['serverTools' => [Tools::webSearch(), Tools::xSearch(), Tools::codeExecution(), Tools::collectionsSearch(['collection'])]])->run();
    expect($result->text)->toBe('Found it')->and($http->lastRequest->getUri()->getPath())->toBe('/v1/responses')
        ->and($http->sentBody()['tools'])->toHaveCount(5)->and($http->sentBody())->not->toHaveKeys(['serverTools', 'api']);
    XAI::reset();
});

it('routes stored file attachments through Responses automatically', function () {
    $http = new FakeHttpClient(200, json_encode(['status' => 'completed', 'output' => []]));
    $factory = new Psr17Factory();
    XAI::create(['apiKey' => 'test', 'sdk' => new Sdk($http, $factory, $factory)]);
    Generate::text()->messages([Message::user([Content::storedFile(new FileReference('xai', 'file-a'))])])->model(XAI::model('opaque'))->run();
    expect($http->lastRequest->getUri()->getPath())->toBe('/v1/responses')->and($http->sentBody()['input'][0]['content'][0]['file_id'])->toBe('file-a');
    XAI::reset();
});
