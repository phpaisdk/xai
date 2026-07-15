<?php

declare(strict_types=1);

namespace AiSdk\XAI\Live;

use AiSdk\Exceptions\InvalidArgumentException;
use AiSdk\Live\AudioDelta;
use AiSdk\Live\Contracts\LiveSessionDriverInterface;
use AiSdk\Live\Contracts\TransportConnectionInterface;
use AiSdk\Live\Contracts\TransportInterface;
use AiSdk\Live\Interrupted;
use AiSdk\Live\LiveClosed;
use AiSdk\Live\LiveError;
use AiSdk\Live\LiveEvent;
use AiSdk\Live\LiveRequest;
use AiSdk\Live\ProviderEvent;
use AiSdk\Live\ResponseCompleted;
use AiSdk\Live\SpeechStarted;
use AiSdk\Live\SpeechStopped;
use AiSdk\Live\TextDelta;
use AiSdk\Live\ToolCallEvent;
use AiSdk\Live\TranscriptCompleted;
use AiSdk\Live\TranscriptDelta;
use AiSdk\Live\TranscriptSource;
use AiSdk\Live\TranscriptUpdate;
use AiSdk\Live\TransportFrame;
use AiSdk\Live\TransportFrameType;
use AiSdk\Live\UsageEvent;
use AiSdk\Live\WebSocketEndpoint;
use AiSdk\Utils\Support\Url;
use AiSdk\XAI\XAIOptions;
use JsonException;

/** xAI Voice Agent protocol codec for OpenAI-compatible JSON events. */
final class XAILiveSessionDriver implements LiveSessionDriverInterface
{
    private TransportConnectionInterface $connection;

    /** @var array<string, true> */
    private array $handledToolCalls = [];

    /** @var array<string, true> */
    private array $pendingToolCalls = [];

    /** @var array<string, true> */
    private array $submittedToolResults = [];

    private bool $toolTurnComplete = false;

    public function __construct(
        private readonly string $modelId,
        private readonly XAIOptions $options,
        private readonly LiveRequest $request,
        TransportInterface $transport,
        private readonly ?string $callId = null,
    ) {
        $endpoint = new WebSocketEndpoint(
            url: $this->webSocketUrl(),
            headers: $this->options->authHeaders(),
        );

        if (! $transport->supports($endpoint)) {
            throw new InvalidArgumentException('The selected transport does not support xAI Voice Agent WebSocket sessions.');
        }

        $this->connection = $transport->connect($endpoint);
        $this->sendEvent([
            'type' => 'session.update',
            'session' => XAILiveConfiguration::session($this->request),
        ]);
    }

    public function sendAudio(string $bytes): void
    {
        $this->sendEvent([
            'type' => 'input_audio_buffer.append',
            'audio' => base64_encode($bytes),
        ]);
    }

    public function sendText(string $text): void
    {
        $this->sendEvent([
            'type' => 'conversation.item.create',
            'item' => [
                'type' => 'message',
                'role' => 'user',
                'content' => [[
                    'type' => 'input_text',
                    'text' => $text,
                ]],
            ],
        ]);
    }

    public function commitAudio(): void
    {
        $this->sendEvent(['type' => 'input_audio_buffer.commit']);
    }

    public function clearAudio(): void
    {
        $this->sendEvent(['type' => 'input_audio_buffer.clear']);
    }

    public function requestResponse(): void
    {
        $this->sendEvent(['type' => 'response.create']);
    }

    public function cancelResponse(): void
    {
        $this->sendEvent(['type' => 'response.cancel']);
    }

    public function sendToolResult(string $callId, mixed $result): void
    {
        $this->sendEvent([
            'type' => 'conversation.item.create',
            'item' => [
                'type' => 'function_call_output',
                'call_id' => $callId,
                'output' => is_string($result) ? $result : json_encode($result, JSON_THROW_ON_ERROR),
            ],
        ]);
        $this->submittedToolResults[$callId] = true;
        $this->continueAfterToolResults();
    }

    public function events(): iterable
    {
        while (($frame = $this->connection->receive()) !== null) {
            foreach ($this->decodeFrame($frame) as $event) {
                yield $event;
            }
        }
    }

    public function close(): void
    {
        if (! $this->connection->isClosed()) {
            $this->connection->close();
        }
    }

    /** @return list<LiveEvent> */
    private function decodeFrame(TransportFrame $frame): array
    {
        if ($frame->type !== TransportFrameType::Text) {
            return [new ProviderEvent('transport.binary', ['bytes' => base64_encode($frame->payload)])];
        }

        try {
            $payload = json_decode($frame->payload, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            return [new ProviderEvent('transport.invalid_json', [
                'payload' => $frame->payload,
                'message' => $exception->getMessage(),
            ])];
        }

        if (! is_array($payload)) {
            return [new ProviderEvent('transport.invalid_event', ['payload' => $payload])];
        }

        $type = is_string($payload['type'] ?? null) ? $payload['type'] : 'unknown';

        if (in_array($type, ['response.output_audio.delta', 'response.audio.delta', 'audio.delta'], true)) {
            $delta = $payload['delta'] ?? null;
            $bytes = is_string($delta) ? base64_decode($delta, true) : false;

            return $bytes === false
                ? [new ProviderEvent($type, $payload)]
                : [new AudioDelta($bytes)];
        }

        if (in_array($type, ['response.output_text.delta', 'response.text.delta', 'text.delta'], true)) {
            return is_string($payload['delta'] ?? null)
                ? [new TextDelta($payload['delta'])]
                : [new ProviderEvent($type, $payload)];
        }

        if ($type === 'conversation.item.input_audio_transcription.updated') {
            $transcript = $payload['transcript'] ?? $payload['text'] ?? null;

            return is_string($transcript)
                ? [new TranscriptUpdate(
                    $transcript,
                    is_string($payload['item_id'] ?? null) ? $payload['item_id'] : null,
                    TranscriptSource::Input,
                )]
                : [new ProviderEvent($type, $payload)];
        }

        if (in_array($type, [
            'conversation.item.input_audio_transcription.delta',
            'response.output_audio_transcript.delta',
            'response.audio_transcript.delta',
        ], true)) {
            return is_string($payload['delta'] ?? null)
                ? [new TranscriptDelta(
                    $payload['delta'],
                    is_string($payload['item_id'] ?? null) ? $payload['item_id'] : null,
                    $type === 'conversation.item.input_audio_transcription.delta'
                        ? TranscriptSource::Input
                        : TranscriptSource::Output,
                )]
                : [new ProviderEvent($type, $payload)];
        }

        if (in_array($type, [
            'conversation.item.input_audio_transcription.completed',
            'response.output_audio_transcript.done',
            'response.audio_transcript.done',
        ], true)) {
            $transcript = $payload['transcript'] ?? $payload['text'] ?? null;

            return is_string($transcript)
                ? [new TranscriptCompleted(
                    $transcript,
                    is_string($payload['item_id'] ?? null) ? $payload['item_id'] : null,
                    $type === 'conversation.item.input_audio_transcription.completed'
                        ? TranscriptSource::Input
                        : TranscriptSource::Output,
                )]
                : [new ProviderEvent($type, $payload)];
        }

        if ($type === 'input_audio_buffer.speech_started') {
            return [new SpeechStarted(is_int($payload['audio_start_ms'] ?? null) ? $payload['audio_start_ms'] : null)];
        }

        if ($type === 'input_audio_buffer.speech_stopped') {
            return [new SpeechStopped(is_int($payload['audio_end_ms'] ?? null) ? $payload['audio_end_ms'] : null)];
        }

        if (in_array($type, ['response.cancelled', 'output_audio_buffer.cleared'], true)) {
            $responseId = $payload['response_id'] ?? $payload['response']['id'] ?? null;

            return [new Interrupted(is_string($responseId) ? $responseId : null)];
        }

        if ($type === 'error') {
            $error = is_array($payload['error'] ?? null) ? $payload['error'] : $payload;
            $message = $error['message'] ?? 'xAI Voice Agent returned an error.';
            $code = $error['code'] ?? null;

            return [new LiveError(
                is_string($message) ? $message : 'xAI Voice Agent returned an error.',
                is_string($code) ? $code : null,
                $payload,
            )];
        }

        if ($type === 'response.function_call_arguments.done') {
            return $this->handleToolCall(
                callId: $payload['call_id'] ?? null,
                name: $payload['name'] ?? null,
                arguments: $payload['arguments'] ?? null,
            );
        }

        if ($type === 'response.output_item.done' && is_array($payload['item'] ?? null)) {
            $item = $payload['item'];
            if (($item['type'] ?? null) === 'function_call') {
                return $this->handleToolCall(
                    callId: $item['call_id'] ?? null,
                    name: $item['name'] ?? null,
                    arguments: $item['arguments'] ?? null,
                );
            }
        }

        if ($type === 'response.done') {
            $events = $this->toolCallsFromResponseDone($payload);
            $response = is_array($payload['response'] ?? null) ? $payload['response'] : [];
            $responseId = is_string($response['id'] ?? null) ? $response['id'] : null;

            if (($response['status'] ?? null) === 'cancelled') {
                $events[] = new Interrupted($responseId);
            }

            $usage = is_array($response['usage'] ?? null) ? $this->numericUsage($response['usage']) : [];
            if ($usage !== []) {
                $events[] = new UsageEvent($usage);
            }

            $events[] = new ResponseCompleted($responseId, $response);
            $this->toolTurnComplete = $this->pendingToolCalls !== [];
            $this->continueAfterToolResults();

            return $events;
        }

        if (in_array($type, ['session.closed', 'connection.closed'], true)) {
            if (! $this->connection->isClosed()) {
                $this->connection->close();
            }

            return [new LiveClosed(
                is_int($payload['code'] ?? null) ? $payload['code'] : null,
                is_string($payload['reason'] ?? null) ? $payload['reason'] : null,
            )];
        }

        return [new ProviderEvent($type, $payload)];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return list<LiveEvent>
     */
    private function toolCallsFromResponseDone(array $payload): array
    {
        $output = $payload['response']['output'] ?? null;
        if (! is_array($output)) {
            return [];
        }

        $events = [];
        foreach ($output as $item) {
            if (! is_array($item) || ($item['type'] ?? null) !== 'function_call') {
                continue;
            }

            array_push($events, ...$this->handleToolCall(
                callId: $item['call_id'] ?? null,
                name: $item['name'] ?? null,
                arguments: $item['arguments'] ?? null,
            ));
        }

        return $events;
    }

    /**
     * @return list<LiveEvent>
     */
    private function handleToolCall(mixed $callId, mixed $name, mixed $arguments): array
    {
        if (! is_string($callId) || $callId === '') {
            return [];
        }

        $this->pendingToolCalls[$callId] = true;
        if (isset($this->handledToolCalls[$callId])) {
            return [];
        }

        $this->handledToolCalls[$callId] = true;
        $name = is_string($name) ? $name : '';
        $decoded = is_string($arguments) ? json_decode($arguments, true) : $arguments;
        $decoded = is_array($decoded) ? $decoded : [];

        return [new ToolCallEvent($callId, $name, $decoded)];
    }

    /**
     * xAI requires all parallel function outputs before exactly one
     * response.create. response.function_call_arguments.done can arrive
     * before response.done, so outputs are accepted early while continuation
     * waits for the response's complete call set.
     */
    private function continueAfterToolResults(): void
    {
        if (! $this->toolTurnComplete || $this->pendingToolCalls === []) {
            return;
        }

        foreach ($this->pendingToolCalls as $callId => $_) {
            if (! isset($this->submittedToolResults[$callId])) {
                return;
            }
        }

        $this->sendEvent(['type' => 'response.create']);
        $this->pendingToolCalls = [];
        $this->submittedToolResults = [];
        $this->toolTurnComplete = false;
    }

    private function webSocketUrl(): string
    {
        $base = preg_replace('#^http://#', 'ws://', $this->options->baseUrl);
        $base = preg_replace('#^https://#', 'wss://', $base ?? $this->options->baseUrl) ?? $this->options->baseUrl;

        if ($this->callId !== null) {
            return Url::joinPath($base, '/realtime').'?call_id='.rawurlencode($this->callId);
        }

        $query = ['model' => $this->modelId];
        $provider = $this->request->providerOptions[XAIOptions::PROVIDER_NAME] ?? [];
        if (is_array($provider['query'] ?? null)) {
            foreach ($provider['query'] as $name => $value) {
                if (is_string($name) && is_scalar($value)) {
                    $query[$name] = $value;
                }
            }
        }

        return Url::joinPath($base, '/realtime').'?'.http_build_query($query, encoding_type: PHP_QUERY_RFC3986);
    }

    /**
     * @param  array<string, mixed>  $usage
     * @return array<string, int|float>
     */
    private function numericUsage(array $usage, string $prefix = ''): array
    {
        $normalized = [];

        foreach ($usage as $name => $value) {
            $key = $prefix === '' ? (string) $name : $prefix.'.'.$name;
            if (is_int($value) || is_float($value)) {
                $normalized[$key] = $value;
            } elseif (is_array($value)) {
                $normalized = array_replace($normalized, $this->numericUsage($value, $key));
            }
        }

        return $normalized;
    }

    /** @param array<string, mixed> $event */
    private function sendEvent(array $event): void
    {
        $this->connection->send(TransportFrame::text(json_encode($event, JSON_THROW_ON_ERROR)));
    }
}
