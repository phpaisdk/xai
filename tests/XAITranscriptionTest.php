<?php

declare(strict_types=1);

use AiSdk\Content;
use AiSdk\Generate;
use AiSdk\Support\Sdk;
use AiSdk\XAI;
use AiSdk\XAI\Tests\Fakes\FakeHttpClient;
use Nyholm\Psr7\Factory\Psr17Factory;

afterEach(function () {
    Generate::reset();
    XAI::reset();
});

it('uses the xAI STT wire format with the file part last', function () {
    $client = new FakeHttpClient(200, '{"text":"Speaker transcript.","duration":2.4}');
    $factory = new Psr17Factory;
    Generate::configure(new Sdk($client, $factory, $factory));
    XAI::create(['apiKey' => 'xai-test']);

    $result = Generate::transcription(Content::audio('mp3-bytes', 'audio/mpeg', 'clip.mp3'))
        ->model(XAI::model('grok-transcribe'))
        ->providerOptions('xai', ['diarize' => true, 'keyterm' => ['PHP', 'SDK']])
        ->run();

    $body = (string) $client->lastRequest?->getBody();
    $filePosition = strrpos($body, 'name="file"');
    $keytermPosition = strrpos($body, 'name="keyterm"');
    if ($filePosition === false || $keytermPosition === false) {
        throw new RuntimeException('Expected multipart fields were not found.');
    }

    expect($result->output->text)->toBe('Speaker transcript.')
        ->and((string) $client->lastRequest?->getUri())->toBe('https://api.x.ai/v1/stt')
        ->and($body)->not->toContain('name="model"')
        ->and($body)->toContain('name="diarize"', 'name="keyterm"', 'clip.mp3')
        ->and($filePosition)->toBeGreaterThan($keytermPosition);
});
