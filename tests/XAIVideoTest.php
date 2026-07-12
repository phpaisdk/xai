<?php

declare(strict_types=1);
use AiSdk\Requests\VideoOutputOptions;
use AiSdk\Requests\VideoRequest;
use AiSdk\Responses\VideoJob;
use AiSdk\Responses\VideoJobStatus;
use AiSdk\Support\Sdk;
use AiSdk\XAI\Models\XAIVideoModel;
use AiSdk\XAI\Tests\Fakes\FakeHttpClient;
use AiSdk\XAI\XAIOptions;
use Nyholm\Psr7\Factory\Psr17Factory;

function xaiVideoOptions(FakeHttpClient $client): XAIOptions
{
    $f = new Psr17Factory;

    return new XAIOptions('test', 'https://api.x.ai/v1', sdk: new Sdk($client, $f, $f));
}
it('starts xAI video generation jobs', function () {
    $client = new FakeHttpClient(200, json_encode(['request_id' => 'req-1']));
    $model = new XAIVideoModel('grok-imagine-video', xaiVideoOptions($client));
    $job = $model->generate(new VideoRequest('A rocket', output: new VideoOutputOptions('16:9', '720p', 8)));
    expect($job->id)->toBe('req-1')->and($client->lastRequest?->getUri()->getPath())->toBe('/v1/videos/generations')->and($client->sentBody())->toMatchArray(['model' => 'grok-imagine-video', 'duration' => 8.0, 'aspect_ratio' => '16:9', 'resolution' => '720p']);
});
it('polls completed xAI video jobs', function () {
    $client = new FakeHttpClient(200, json_encode(['status' => 'done', 'video' => ['url' => 'https://x.ai/video.mp4', 'duration' => 8, 'respect_moderation' => true]]));
    $model = new XAIVideoModel('grok-imagine-video', xaiVideoOptions($client));
    $job = $model->poll(new VideoJob('req-1', 'xai', 'grok-imagine-video'));
    expect($job->status)->toBe(VideoJobStatus::Succeeded)->and($job->result?->url)->toBe('https://x.ai/video.mp4')->and($client->lastRequest?->getUri()->getPath())->toBe('/v1/videos/req-1');
});
