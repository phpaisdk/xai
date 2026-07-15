<?php

declare(strict_types=1);

use AiSdk\Exceptions\InvalidArgumentException;
use AiSdk\Generate;
use AiSdk\Live;
use AiSdk\Live\AudioDelta;
use AiSdk\Live\LiveClosed;
use AiSdk\Live\LiveError;
use AiSdk\Live\ProviderEvent;
use AiSdk\Live\ResponseCompleted;
use AiSdk\Live\SpeechStopped;
use AiSdk\Live\ToolCallEvent;
use AiSdk\Live\ToolResultEvent;
use AiSdk\Live\TranscriptCompleted;
use AiSdk\Live\TranscriptSource;
use AiSdk\Live\TranscriptUpdate;
use AiSdk\Live\TransportFrame;
use AiSdk\Live\TransportFrameType;
use AiSdk\Live\UsageEvent;
use AiSdk\Live\WebSocketEndpoint;
use AiSdk\Schema;
use AiSdk\Support\Sdk;
use AiSdk\Tool;
use AiSdk\XAI;
use AiSdk\XAI\Tests\Fakes\FakeHttpClient;
use AiSdk\XAI\Tests\Fakes\FakeTransport;
use Nyholm\Psr7\Factory\Psr17Factory;

afterEach(function () {
    Generate::reset();
    XAI::reset();
});

function configureXAILiveWith(FakeHttpClient $client): void
{
    $factory = new Psr17Factory;
    Generate::configure(new Sdk($client, $factory, $factory));
}

it('connects an xAI voice agent and encodes OpenAI-compatible events', function () {
    XAI::create(['apiKey' => 'xai-live']);
    $transport = new FakeTransport;

    $session = Live::voice()
        ->model(XAI::model('grok-voice-latest'))
        ->instructions('Be concise.')
        ->voice('eve')
        ->language('en')
        ->inputAudioFormat('g711_ulaw')
        ->outputAudioFormat('g711_alaw')
        ->turnDetection('server_vad')
        ->providerOptions('xai', [
            'resumption' => ['enabled' => true],
            'reasoning' => ['effort' => 'none'],
            'query' => ['conversation_id' => 'conv_resume'],
        ])
        ->connect($transport);

    expect($transport->endpoint)
        ->toBeInstanceOf(WebSocketEndpoint::class)
        ->url->toBe('wss://api.x.ai/v1/realtime?model=grok-voice-latest&conversation_id=conv_resume')
        ->headers->toMatchArray(['Authorization' => 'Bearer xai-live']);

    $update = json_decode($transport->connection->sent[0]->payload, true);
    expect($update['type'])->toBe('session.update')
        ->and($update['session']['voice'])->toBe('eve')
        ->and($update['session']['audio']['input']['format'])->toBe(['type' => 'audio/pcmu'])
        ->and($update['session']['audio']['input']['transcription']['language_hint'])->toBe('en')
        ->and($update['session']['audio']['output']['format'])->toBe(['type' => 'audio/pcma'])
        ->and($update['session']['turn_detection'])->toBe(['type' => 'server_vad'])
        ->and($update['session']['resumption'])->toBe(['enabled' => true])
        ->and($update['session']['reasoning'])->toBe(['effort' => 'none']);

    $session->sendAudio("\x01\x02");
    $session->sendText('Hello');
    $session->requestResponse();

    $sent = array_map(
        static fn ($frame): array => json_decode($frame->payload, true),
        array_slice($transport->connection->sent, 1),
    );
    expect(array_column($sent, 'type'))->toBe([
        'input_audio_buffer.append',
        'conversation.item.create',
        'response.create',
    ])->and($sent[0]['audio'])->toBe(base64_encode("\x01\x02"));
});

it('normalizes xAI voice audio and cumulative transcription corrections', function () {
    XAI::create(['apiKey' => 'xai-live']);
    $transport = new FakeTransport;
    $session = Live::voice()->model(XAI::model('grok-voice-latest'))->connect($transport);

    $transport->connection->enqueue(
        TransportFrame::text(json_encode(['type' => 'response.output_audio.delta', 'delta' => base64_encode('audio')])),
        TransportFrame::text(json_encode([
            'type' => 'conversation.item.input_audio_transcription.updated',
            'item_id' => 'item_input',
            'transcript' => 'I scream',
        ])),
        TransportFrame::text(json_encode([
            'type' => 'conversation.item.input_audio_transcription.updated',
            'item_id' => 'item_input',
            'transcript' => 'Ice cream',
        ])),
        TransportFrame::text(json_encode(['type' => 'conversation.created', 'conversation' => ['id' => 'conv_1']])),
    );

    $events = iterator_to_array($session->events());
    expect($events[0])->toBeInstanceOf(AudioDelta::class)
        ->and($events[1])->toBeInstanceOf(TranscriptUpdate::class)
        ->and($events[1]->text)->toBe('I scream')
        ->and($events[1]->itemId)->toBe('item_input')
        ->and($events[1]->source)->toBe(TranscriptSource::Input)
        ->and($events[2])->toBeInstanceOf(TranscriptUpdate::class)
        ->and($events[2]->text)->toBe('Ice cream')
        ->and($events[3])->toBeInstanceOf(ProviderEvent::class);
});

it('executes registered xAI voice tools and exposes manual calls', function () {
    XAI::create(['apiKey' => 'xai-live']);
    $transport = new FakeTransport;
    $weather = Tool::make('weather', 'Get weather')
        ->input(Schema::string(name: 'city')->required())
        ->run(fn (string $city): array => ['forecast' => "Sunny in {$city}"]);
    $session = Live::voice()
        ->model(XAI::model('grok-voice-latest'))
        ->tools([$weather])
        ->connect($transport);

    $transport->connection->enqueue(
        TransportFrame::text(json_encode([
            'type' => 'response.function_call_arguments.done',
            'call_id' => 'call_weather',
            'name' => 'weather',
            'arguments' => '{"city":"Lahore"}',
        ])),
        TransportFrame::text(json_encode([
            'type' => 'response.function_call_arguments.done',
            'call_id' => 'call_manual',
            'name' => 'human_approval',
            'arguments' => '{}',
        ])),
        TransportFrame::text(json_encode([
            'type' => 'response.done',
            'response' => [
                'id' => 'resp_tools',
                'status' => 'completed',
                'output' => [
                    ['type' => 'function_call', 'call_id' => 'call_weather', 'name' => 'weather', 'arguments' => '{"city":"Lahore"}'],
                    ['type' => 'function_call', 'call_id' => 'call_manual', 'name' => 'human_approval', 'arguments' => '{}'],
                ],
            ],
        ])),
    );

    $events = iterator_to_array($session->events());
    $session->sendToolResult('call_manual', ['approved' => true]);
    $sent = array_map(
        static fn ($frame): array => json_decode($frame->payload, true),
        array_slice($transport->connection->sent, 1),
    );

    expect($sent[0]['item']['output'])->toBe('{"forecast":"Sunny in Lahore"}')
        ->and($sent[1]['item']['call_id'])->toBe('call_manual')
        ->and($sent[2]['type'])->toBe('response.create')
        ->and($events)->toHaveCount(4)
        ->and($events[0])->toBeInstanceOf(ToolCallEvent::class)
        ->and($events[0]->callId)->toBe('call_weather')
        ->and($events[1])->toBeInstanceOf(ToolResultEvent::class)
        ->and($events[1]->automatic)->toBeTrue()
        ->and($events[2])->toBeInstanceOf(ToolCallEvent::class)
        ->and($events[2]->callId)->toBe('call_manual')
        ->and($events[3])->toBeInstanceOf(ResponseCompleted::class);
});

it('continues once after all parallel xAI Voice Agent tool results', function () {
    XAI::create(['apiKey' => 'xai-live']);
    $transport = new FakeTransport;
    $first = Tool::make('first', 'First tool')->run(fn (): string => 'one');
    $second = Tool::make('second', 'Second tool')->run(fn (): string => 'two');
    $session = Live::voice()
        ->model(XAI::model('grok-voice-latest'))
        ->tools([$first, $second])
        ->connect($transport);

    $transport->connection->enqueue(
        TransportFrame::text(json_encode([
            'type' => 'response.function_call_arguments.done',
            'call_id' => 'call_first',
            'name' => 'first',
            'arguments' => '{}',
        ])),
        TransportFrame::text(json_encode([
            'type' => 'response.function_call_arguments.done',
            'call_id' => 'call_second',
            'name' => 'second',
            'arguments' => '{}',
        ])),
        TransportFrame::text(json_encode([
            'type' => 'response.done',
            'response' => [
                'id' => 'resp_parallel',
                'status' => 'completed',
                'output' => [
                    ['type' => 'function_call', 'call_id' => 'call_first', 'name' => 'first', 'arguments' => '{}'],
                    ['type' => 'function_call', 'call_id' => 'call_second', 'name' => 'second', 'arguments' => '{}'],
                ],
            ],
        ])),
    );

    iterator_to_array($session->events());
    $sent = array_map(
        static fn ($frame): array => json_decode($frame->payload, true),
        array_slice($transport->connection->sent, 1),
    );
    $types = array_column($sent, 'type');

    expect($types)->toBe([
        'conversation.item.create',
        'conversation.item.create',
        'response.create',
    ])->and(array_count_values($types)['response.create'])->toBe(1);
});

it('disables xAI server turn detection with normalized string aliases', function (string $turnDetection) {
    XAI::create(['apiKey' => 'xai-live']);
    $transport = new FakeTransport;

    Live::voice()
        ->model(XAI::model('grok-voice-latest'))
        ->turnDetection($turnDetection)
        ->connect($transport);

    $update = json_decode($transport->connection->sent[0]->payload, true);

    expect($update['session'])->toHaveKey('turn_detection')
        ->and($update['session']['turn_detection'])->toBeNull();
})->with(['none', 'disabled']);

it('streams raw binary audio through xAI dedicated STT and exposes replaceable partials', function () {
    XAI::create(['apiKey' => 'xai-live']);
    $transport = new FakeTransport;
    $transport->connection->enqueue(
        TransportFrame::text(json_encode(['type' => 'transcript.created'])),
    );
    $session = Live::transcribe()
        ->model(XAI::model('grok-transcribe'))
        ->language('en')
        ->providerOptions('xai', [
            'sample_rate' => 16000,
            'keyterm' => ['PHP', 'AI SDK'],
            'smart_turn' => 0.7,
        ])
        ->connect($transport);

    expect($transport->endpoint?->url)
        ->toContain('wss://api.x.ai/v1/stt?')
        ->toContain('encoding=pcm')
        ->toContain('interim_results=true')
        ->toContain('language=en')
        ->toContain('keyterm=PHP')
        ->toContain('keyterm=AI%20SDK')
        ->toContain('smart_turn=0.7');

    $session->sendAudio("\x01\x02");
    $session->commitAudio();
    expect($transport->connection->sent[0]->type)->toBe(TransportFrameType::Binary)
        ->and($transport->connection->sent[0]->payload)->toBe("\x01\x02")
        ->and(json_decode($transport->connection->sent[1]->payload, true)['type'])->toBe('finalize');

    $transport->connection->enqueue(
        TransportFrame::text(json_encode([
            'type' => 'transcript.partial',
            'item_id' => 'stt_1',
            'text' => 'I scream',
            'is_final' => false,
            'speech_final' => false,
        ])),
        TransportFrame::text(json_encode([
            'type' => 'transcript.partial',
            'item_id' => 'stt_1',
            'text' => 'Ice cream',
            'is_final' => true,
            'speech_final' => true,
        ])),
        TransportFrame::text(json_encode([
            'type' => 'transcript.done',
            'item_id' => 'stt_1',
            'text' => 'Ice cream',
            'duration' => 1.2,
        ])),
    );
    $session->close();
    $events = iterator_to_array($session->events());

    expect(json_decode($transport->connection->sent[2]->payload, true)['type'])->toBe('audio.done')
        ->and($events[0])->toBeInstanceOf(ProviderEvent::class)
        ->and($events[1])->toBeInstanceOf(TranscriptUpdate::class)
        ->and($events[1]->text)->toBe('I scream')
        ->and($events[1]->itemId)->toBe('stt_1')
        ->and($events[1]->source)->toBe(TranscriptSource::Input)
        ->and($events[2])->toBeInstanceOf(TranscriptUpdate::class)
        ->and($events[2]->text)->toBe('Ice cream')
        ->and($events[3])->toBeInstanceOf(TranscriptCompleted::class)
        ->and($events[3]->text)->toBe('Ice cream')
        ->and($events[4])->toBeInstanceOf(SpeechStopped::class)
        ->and($events[5])->toBeInstanceOf(TranscriptCompleted::class)
        ->and($events[5]->text)->toBe('Ice cream')
        ->and($events[6])->toBeInstanceOf(LiveClosed::class)
        ->and($transport->connection->closed)->toBeTrue();
});

it('keeps xAI multichannel STT open until every channel is complete', function () {
    XAI::create(['apiKey' => 'xai-live']);
    $transport = new FakeTransport;
    $transport->connection->enqueue(
        TransportFrame::text(json_encode(['type' => 'transcript.created'])),
    );
    $session = Live::transcribe()
        ->model(XAI::model('grok-transcribe'))
        ->providerOptions('xai', [
            'multichannel' => true,
            'channels' => 2,
        ])
        ->connect($transport);

    $transport->connection->enqueue(
        TransportFrame::text(json_encode([
            'type' => 'transcript.done',
            'channel_index' => 0,
            'text' => 'Agent speaking',
        ])),
        TransportFrame::text(json_encode([
            'type' => 'transcript.done',
            'channel_index' => 1,
            'text' => 'Customer speaking',
        ])),
    );
    $session->close();
    $events = iterator_to_array($session->events());

    expect($events[1])->toBeInstanceOf(TranscriptCompleted::class)
        ->and($events[1]->itemId)->toBe('channel:0')
        ->and($events[2])->toBeInstanceOf(TranscriptCompleted::class)
        ->and($events[2]->itemId)->toBe('channel:1')
        ->and($events[3])->toBeInstanceOf(LiveClosed::class)
        ->and($transport->connection->closed)->toBeTrue();
});

it('requires xAI transcript.created before accepting streaming audio', function () {
    XAI::create(['apiKey' => 'xai-live']);
    $transport = new FakeTransport;
    $transport->connection->enqueue(
        TransportFrame::text(json_encode(['type' => 'error', 'message' => 'Not ready'])),
    );

    Live::transcribe()
        ->model(XAI::model('grok-transcribe'))
        ->connect($transport);
})->throws(InvalidArgumentException::class, 'Not ready');

it('normalizes xAI response completion usage errors and closure', function () {
    XAI::create(['apiKey' => 'xai-live']);
    $transport = new FakeTransport;
    $session = Live::voice()->model(XAI::model('grok-voice-latest'))->connect($transport);

    $transport->connection->enqueue(
        TransportFrame::text(json_encode([
            'type' => 'response.done',
            'response' => [
                'id' => 'resp_1',
                'status' => 'completed',
                'usage' => ['total_tokens' => 9, 'input_token_details' => ['cached_tokens' => 2]],
            ],
        ])),
        TransportFrame::text(json_encode([
            'type' => 'error',
            'error' => ['code' => 'invalid_event', 'message' => 'Bad event'],
        ])),
        TransportFrame::text(json_encode(['type' => 'session.closed', 'reason' => 'completed'])),
    );

    $events = iterator_to_array($session->events());

    expect($events[0])->toBeInstanceOf(UsageEvent::class)
        ->and($events[0]->usage)->toMatchArray([
            'total_tokens' => 9,
            'input_token_details.cached_tokens' => 2,
        ])
        ->and($events[1])->toBeInstanceOf(ResponseCompleted::class)
        ->and($events[1]->responseId)->toBe('resp_1')
        ->and($events[2])->toBeInstanceOf(LiveError::class)
        ->and($events[2]->code)->toBe('invalid_event')
        ->and($events[3])->toBeInstanceOf(LiveClosed::class)
        ->and($transport->connection->closed)->toBeTrue();
});

it('creates xAI Voice Agent client secrets without embedding session configuration', function () {
    $client = new FakeHttpClient(200, json_encode([
        'value' => 'ek_xai',
        'expires_at' => 1_800_000_000,
    ]));
    configureXAILiveWith($client);
    XAI::create(['apiKey' => 'xai-live']);

    $secret = Live::voice()
        ->model(XAI::model('grok-voice-latest'))
        ->instructions('This must not be sent in the secret request.')
        ->providerOptions('xai', ['expires_after' => ['seconds' => 600]])
        ->clientSecret();

    expect($secret->value)->toBe('ek_xai')
        ->and($secret->expiresAt)->toBe(1_800_000_000)
        ->and((string) $client->lastRequest?->getUri())->toBe('https://api.x.ai/v1/realtime/client_secrets')
        ->and($client->sentBody())->toBe(['expires_after' => ['seconds' => 600]])
        ->and($client->sentBody())->not->toHaveKey('session')
        ->and($client->sentBody())->not->toHaveKey('model');
});

it('attaches to and hangs up xAI hosted SIP calls without an accept request', function () {
    $client = new FakeHttpClient(200, '{}');
    configureXAILiveWith($client);
    XAI::create(['apiKey' => 'xai-live']);
    $transport = new FakeTransport;

    $call = Live::voice()
        ->model(XAI::model('grok-voice-latest'))
        ->instructions('Answer support questions.')
        ->call('call_sip_123');
    $call->accept();

    expect($client->requests)->toBeEmpty();

    $control = $call->connect($transport);
    $control->requestResponse();
    $call->hangup();

    $sidebandUpdate = json_decode($transport->connection->sent[0]->payload, true);

    expect($call->id())->toBe('call_sip_123')
        ->and($transport->endpoint?->url)->toBe('wss://api.x.ai/v1/realtime?call_id=call_sip_123')
        ->and($sidebandUpdate['session']['instructions'])->toBe('Answer support questions.')
        ->and(json_decode($transport->connection->sent[1]->payload, true)['type'])->toBe('response.create')
        ->and($client->requests)->toHaveCount(1)
        ->and((string) $client->requests[0]->getUri())->toBe('https://api.x.ai/v1/realtime/calls/call_sip_123/hangup');
});

it('verifies signed xAI incoming-call webhooks from the raw body', function () {
    $payload = json_encode([
        'type' => 'realtime.call.incoming',
        'data' => ['call_id' => 'call_webhook'],
    ], JSON_THROW_ON_ERROR);
    $secretBytes = 'xai-webhook-secret';
    $secret = 'whsec_'.base64_encode($secretBytes);
    $timestamp = (string) time();
    $messageId = 'wh_test';
    $signature = base64_encode(hash_hmac(
        'sha256',
        $messageId.'.'.$timestamp.'.'.$payload,
        $secretBytes,
        true,
    ));
    $headers = [
        'Webhook-Id' => $messageId,
        'Webhook-Timestamp' => $timestamp,
        'Webhook-Signature' => 'v1,'.$signature,
    ];

    $event = XAI::verifyWebhook($payload, $headers, $secret);

    expect($event['type'])->toBe('realtime.call.incoming')
        ->and($event['data']['call_id'])->toBe('call_webhook');

    XAI::verifyWebhook($payload.' ', $headers, $secret);
})->throws(InvalidArgumentException::class, 'Invalid xAI webhook signature');

it('rejects dedicated xAI Live translation', function () {
    XAI::create(['apiKey' => 'xai-live']);

    Live::translate()
        ->model(XAI::model('grok-voice-latest'))
        ->to('es')
        ->connect(new FakeTransport);
})->throws(InvalidArgumentException::class, 'does not provide a dedicated Live translation');
