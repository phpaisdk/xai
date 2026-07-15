# aisdk/xai

<a href="https://github.com/phpaisdk/xai/actions"><img alt="GitHub Workflow Status" src="https://img.shields.io/github/actions/workflow/status/phpaisdk/xai/tests.yml?branch=main&label=Tests"></a>
<a href="https://packagist.org/packages/aisdk/xai"><img alt="Total Downloads" src="https://img.shields.io/packagist/dt/aisdk/xai"></a>
<a href="https://packagist.org/packages/aisdk/xai"><img alt="Latest Version" src="https://img.shields.io/packagist/v/aisdk/xai"></a>
<a href="https://packagist.org/packages/aisdk/xai"><img alt="License" src="https://img.shields.io/packagist/l/aisdk/xai"></a>
<a href="https://whyphp.dev"><img src="https://img.shields.io/badge/Why_PHP-in_2026-7A86E8?style=flat-square&labelColor=18181b" alt="Why PHP in 2026"></a>

------

Official xAI provider for the PHP AI SDK. Uses shared OpenAI-compatible wire adapters for text generation.

## Installation

```bash
composer require aisdk/xai
```

Live sessions need a transport. Install the ready-made implementation, or use
any application transport that implements the contracts from `aisdk/core`:

```bash
composer require aisdk/transport
```

## Basic Usage

```php
use AiSdk\Generate;
use AiSdk\XAI;

$result = Generate::text()
    ->model(XAI::model('grok-4.3'))
    ->instructions('Write short, clear answers.')
    ->prompt('Explain closures in PHP.')
    ->run();

echo $result->text;
```

Model IDs pass through unchanged and do not need to be registered. This package does not ship a model inventory; the SDK performs internal adapter validation before xAI validates support for the selected model.

## Configuration

| Variable | Description | Default |
|---|---|---|
| `XAI_API_KEY` | API key for authentication | Required |
| `XAI_BASE_URL` | Base URL for API requests | `https://api.x.ai/v1` |

```php
XAI::create([
    'apiKey' => 'xai-...',
    'baseUrl' => 'https://api.x.ai/v1',
]);
```

## Supported Capabilities

| Capability | Support |
|---|---|
| Text generation and streaming | Native |
| Tool calling | Native |
| Reasoning | Native |
| Image generation | Native |
| Speech generation | Native |
| Transcription | Native |
| Embeddings | Native |
| Video generation | Native |
| Live voice | WebSocket and SIP control |
| Live transcription | Dedicated binary-audio WebSocket |
| Live translation | Not provided by xAI |
| WebRTC signalling | Not provided by xAI |

## Image Generation

```php
use AiSdk\Generate;
use AiSdk\XAI;

$result = Generate::image()
    ->model(XAI::model('grok-imagine-image-quality'))
    ->prompt('A clean app icon for a PHP AI SDK')
    ->aspectRatio('1:1')
    ->run();

$result->output->save(__DIR__.'/icon.png');
```

## Speech Generation

```php
use AiSdk\Generate;
use AiSdk\XAI;

$result = Generate::speech()
    ->model(XAI::model('grok-voice'))
    ->input('Hello from xAI voice.')
    ->voice('eve')
    ->format('mp3')
    ->providerOptions('xai', [
        'language' => 'auto',
        'speed' => 1.1,
    ])
    ->run();

$result->output->save(__DIR__.'/speech.mp3');
```

## Transcription

```php
use AiSdk\Content;
use AiSdk\Generate;
use AiSdk\XAI;

$result = Generate::transcription(Content::audio(__DIR__.'/meeting.mp3'))
    ->model(XAI::model('grok-transcribe'))
    ->providerOptions('xai', [
        'language' => 'en',
        'diarize' => true,
        'keyterm' => ['PHP', 'AI SDK'],
    ])
    ->run();

echo $result->output->text;
```

The xAI STT endpoint does not accept a model field, so the selected ID remains
an opaque SDK model reference while the provider adapter calls the dedicated
service. Local audio and HTTP URLs are supported.

## Live Voice Agents

`AiSdk\Live` is part of core. `aisdk/xai` owns the xAI Voice Agent endpoint,
authentication, session protocol, tool loop, normalized events, ephemeral
credentials, and SIP control. `aisdk/transport` only supplies the WebSocket.

```php
use AiSdk\Live;
use AiSdk\Live\AudioDelta;
use AiSdk\Live\ResponseCompleted;
use AiSdk\Transport;
use AiSdk\XAI;
use function Amp\async;

$session = Live::voice()
    ->model(XAI::model('grok-voice-latest'))
    ->instructions('Be concise and helpful.')
    ->voice('eve')
    ->language('en')
    ->turnDetection('disabled')
    ->inputAudioFormat('pcm16')
    ->outputAudioFormat('pcm16')
    ->connect(Transport::auto());

$sender = async(function () use ($session): void {
    while (! feof(STDIN)) {
        $bytes = fread(STDIN, 4096);
        if ($bytes !== false && $bytes !== '') {
            $session->sendAudio($bytes);
        }
    }

    $session->commitAudio();
    $session->requestResponse();
});

foreach ($session->events() as $event) {
    if ($event instanceof AudioDelta) {
        fwrite(STDOUT, $event->bytes);
    }

    if ($event instanceof ResponseCompleted) {
        break;
    }
}

$sender->await();
$session->close();
```

The example treats STDIN and STDOUT as raw 24 kHz mono PCM16. In an
application, the same calls can be connected to microphone and speaker I/O.

To resume an opted-in xAI conversation, put its server-issued ID in the
provider connection query while keeping resumption enabled in the session:

```php
$session = Live::voice()
    ->model(XAI::model('grok-voice-latest'))
    ->providerOptions('xai', [
        'resumption' => ['enabled' => true],
        'query' => ['conversation_id' => $conversationId],
    ])
    ->connect(Transport::auto());
```

Without `aisdk/transport`, inject your own core transport:

```php
use App\Ai\AppWebSocketTransport;

$session = Live::voice()
    ->model(XAI::model('grok-voice-latest'))
    ->voice('eve')
    ->connect(new AppWebSocketTransport());
```

The core repository includes a complete
[`AppWebSocketTransport`](https://github.com/phpaisdk/core/blob/main/examples/AppWebSocketTransport.php)
using `amphp/websocket-client`. The application transport only moves frames;
xAI event names and message schemas remain encapsulated by this package.

### Voice tools

Registered core tools with handlers run automatically. Unknown or handler-less
calls are emitted for manual handling:

```php
use AiSdk\Live\ToolCallEvent;
use AiSdk\Schema;
use AiSdk\Tool;

$weather = Tool::make('weather', 'Get current weather')
    ->input(Schema::string(name: 'city')->required())
    ->run(fn (string $city): array => ['forecast' => "Sunny in {$city}"]);

$session = Live::voice()
    ->model(XAI::model('grok-voice-latest'))
    ->tool($weather)
    ->connect(Transport::auto());

foreach ($session->events() as $event) {
    if ($event instanceof ToolCallEvent && $event->name === 'approval') {
        $session->sendToolResult($event->callId, ['approved' => true]);
    }
}
```

xAI parallel function calls are coordinated as one turn: every output is sent
before exactly one continuation request.

## Live Transcription

xAI's dedicated streaming STT protocol sends raw audio as binary WebSocket
frames. Partial transcripts are cumulative and can revise earlier text, so the
portable event is `TranscriptUpdate`, not append-only `TranscriptDelta`.

```php
use AiSdk\Live\LiveClosed;
use AiSdk\Live\TranscriptCompleted;
use AiSdk\Live\TranscriptUpdate;

$session = Live::transcribe()
    ->model(XAI::model('grok-transcribe'))
    ->language('en')
    ->audioFormat('pcm16')
    ->providerOptions('xai', [
        'sample_rate' => 16000,
        'keyterm' => ['PHP', 'AI SDK'],
        'smart_turn' => 0.7,
    ])
    ->connect(Transport::auto());

$session->sendAudio($pcmBytes);
$session->commitAudio(); // Requests a finalized segment.
$session->close();       // Sends audio.done; keep receiving the final result.

foreach ($session->events() as $event) {
    if ($event instanceof TranscriptUpdate) {
        echo "\r{$event->text}";
    }
    if ($event instanceof TranscriptCompleted) {
        echo "\nFinal: {$event->text}\n";
    }
    if ($event instanceof LiveClosed) {
        break;
    }
}
```

## Client Secrets

Issue short-lived xAI Voice Agent credentials from a trusted server:

```php
$secret = Live::voice()
    ->model(XAI::model('grok-voice-latest'))
    ->providerOptions('xai', [
        'expires_after' => ['seconds' => 300],
    ])
    ->clientSecret();

return json_encode([
    'value' => $secret->value,
    'expires_at' => $secret->expiresAt,
]);
```

The browser authenticates its native WebSocket with the documented
subprotocol prefix:

```js
const { value } = await fetch('/api/xai/live-secret').then(response => response.json());
const socket = new WebSocket(
    'wss://api.x.ai/v1/realtime?model=grok-voice-latest',
    [`xai-client-secret.${value}`],
);

socket.addEventListener('open', () => {
    socket.send(JSON.stringify({
        type: 'session.update',
        session: {
            instructions: 'Be concise and helpful.',
            voice: 'eve',
            turn_detection: { type: 'server_vad' },
        },
    }));
});
```

xAI's credential endpoint accepts expiry configuration only. Voice,
instructions, and tools are configured after the client opens its Live
session; this package intentionally does not send them in the credential
request.

## SIP Calls and Server Controls

Verify the exact raw xAI webhook body, attach a WebSocket to its `call_id`, and
hang up when complete. xAI makes an incoming SIP call available immediately
and does not document a separate accept endpoint, so the shared `accept()`
method is intentionally a no-op for this provider.

```php
$rawBody = file_get_contents('php://input');
$event = XAI::verifyWebhook(
    $rawBody,
    getallheaders(),
    $_ENV['XAI_WEBHOOK_SECRET'],
);

if (($event['type'] ?? null) === 'realtime.call.incoming') {
    $call = Live::voice()
        ->model(XAI::model('grok-voice-latest'))
        ->instructions('You are the phone support agent.')
        ->voice('eve')
        ->call($event['data']['call_id']);

    $call->accept(); // Deliberate no-op for xAI.
    $control = $call->connect(Transport::auto());

    foreach ($control->events() as $controlEvent) {
        // Handle normalized events and tools on the server.
    }

    $call->hangup();
}
```

Webhook verification enforces the signed raw bytes and the five-minute
timestamp window. Do not parse and re-encode the body first.

## Video Generation

```php
$result = Generate::video('A rocket launching from Mars')
    ->model(XAI::model('grok-imagine-video'))
    ->aspectRatio('16:9')
    ->resolution('720p')
    ->duration(8)
    ->run(timeout: 600);
```

Image-to-video, editing, extension, and reference-to-video are available through normalized image/video inputs and `providerOptions('xai', ...)`.

## Reasoning

```php
use AiSdk\Reasoning;

$result = Generate::text('Explain the tradeoff.')
    ->model(XAI::model('grok-4.3'))
    ->reasoning(Reasoning::effort('low'))
    ->run();
```

## Provider-Specific Options

```php
$result = Generate::text('Hello')
    ->model(XAI::model('grok-4.3'))
    ->providerOptions('xai', [
        'raw' => ['search_parameters' => ['mode' => 'auto']],
    ])
    ->run();
```

## Testing

```bash
composer test
```

The default suite uses protocol fixtures and conformance checks. Credentialed
Live network verification is separate from the default test run.

## Links

- [xAI API OpenAPI Specification](https://docs.x.ai/openapi.json)
- [xAI Image Generation Guide](https://docs.x.ai/docs/guides/image-generation)
- [xAI Speech-to-Text Guide](https://docs.x.ai/developers/model-capabilities/audio/speech-to-text)
- [xAI Voice Agent Guide](https://docs.x.ai/developers/model-capabilities/audio/voice-agent)
- [xAI SIP Guide](https://docs.x.ai/developers/model-capabilities/audio/voice-agent/sip)
- [Core Package](https://github.com/phpaisdk/core)
