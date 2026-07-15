<?php

declare(strict_types=1);

namespace AiSdk\XAI\Live;

use AiSdk\Exceptions\InvalidArgumentException;
use AiSdk\Live\Contracts\LiveSessionDriverInterface;
use AiSdk\Live\Contracts\TransportConnectionInterface;
use AiSdk\Live\Contracts\TransportInterface;
use AiSdk\Live\LiveClosed;
use AiSdk\Live\LiveError;
use AiSdk\Live\LiveEvent;
use AiSdk\Live\LiveRequest;
use AiSdk\Live\ProviderEvent;
use AiSdk\Live\SpeechStopped;
use AiSdk\Live\TranscriptCompleted;
use AiSdk\Live\TranscriptSource;
use AiSdk\Live\TranscriptUpdate;
use AiSdk\Live\TransportFrame;
use AiSdk\Live\TransportFrameType;
use AiSdk\Live\WebSocketEndpoint;
use AiSdk\Utils\Support\Url;
use AiSdk\XAI\XAIOptions;
use JsonException;

/** xAI's dedicated STT WebSocket: binary audio in, JSON transcripts out. */
final class XAIStreamingTranscriptionDriver implements LiveSessionDriverInterface
{
    private TransportConnectionInterface $connection;

    private bool $finishing = false;

    /** @var list<TransportFrame> */
    private array $initialFrames = [];

    private int $expectedDoneEvents = 1;

    private int $receivedDoneEvents = 0;

    public function __construct(
        private readonly XAIOptions $options,
        private readonly LiveRequest $request,
        TransportInterface $transport,
    ) {
        if ($request->tools !== []) {
            throw new InvalidArgumentException('xAI streaming transcription does not support tools.');
        }
        if (isset($request->options['instructions'])) {
            throw new InvalidArgumentException('xAI streaming transcription does not support instructions(). Use provider keyterms instead.');
        }

        $endpoint = new WebSocketEndpoint(
            url: $this->webSocketUrl(),
            headers: $this->options->authHeaders(),
        );

        if (! $transport->supports($endpoint)) {
            throw new InvalidArgumentException('The selected transport does not support xAI streaming transcription WebSockets.');
        }

        $this->connection = $transport->connect($endpoint);
        $this->expectedDoneEvents = $this->expectedDoneEvents();
        $this->waitUntilReady();
    }

    public function sendAudio(string $bytes): void
    {
        if ($this->finishing) {
            throw new InvalidArgumentException('Cannot append audio after closing an xAI transcription stream.');
        }

        $this->connection->send(TransportFrame::binary($bytes));
    }

    public function sendText(string $text): void
    {
        throw new InvalidArgumentException('xAI streaming transcription accepts audio only.');
    }

    public function commitAudio(): void
    {
        $this->sendEvent(['type' => 'finalize']);
    }

    public function clearAudio(): void
    {
        throw new InvalidArgumentException('xAI streaming transcription does not support clearing sent audio.');
    }

    public function requestResponse(): void
    {
        throw new InvalidArgumentException('xAI streaming transcription emits transcripts automatically.');
    }

    public function cancelResponse(): void
    {
        throw new InvalidArgumentException('xAI streaming transcription does not produce model responses.');
    }

    public function sendToolResult(string $callId, mixed $result): void
    {
        throw new InvalidArgumentException('xAI streaming transcription does not support tools.');
    }

    public function events(): iterable
    {
        foreach ($this->initialFrames as $frame) {
            foreach ($this->decodeFrame($frame) as $event) {
                yield $event;
            }
        }
        $this->initialFrames = [];

        while (($frame = $this->connection->receive()) !== null) {
            foreach ($this->decodeFrame($frame) as $event) {
                yield $event;
            }
        }
    }

    /**
     * Sends audio.done and keeps the receive side alive. Continue consuming
     * events until transcript.done so xAI can flush its final transcript.
     */
    public function close(): void
    {
        if ($this->connection->isClosed() || $this->finishing) {
            return;
        }

        $this->finishing = true;
        $this->sendEvent(['type' => 'audio.done']);
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

        if ($type === 'transcript.partial' && is_string($payload['text'] ?? null)) {
            $itemId = $this->itemId($payload);
            $events = [new TranscriptUpdate($payload['text'], $itemId, TranscriptSource::Input)];

            if (($payload['is_final'] ?? false) === true) {
                $events[] = new TranscriptCompleted($payload['text'], $itemId, TranscriptSource::Input);
            }
            if (($payload['speech_final'] ?? false) === true) {
                $events[] = new SpeechStopped;
            }

            return $events;
        }

        if ($type === 'error') {
            $error = is_array($payload['error'] ?? null) ? $payload['error'] : $payload;
            $message = $error['message'] ?? 'xAI streaming transcription returned an error.';
            $code = $error['code'] ?? null;

            return [new LiveError(
                is_string($message) ? $message : 'xAI streaming transcription returned an error.',
                is_string($code) ? $code : null,
                $payload,
            )];
        }

        if ($type === 'transcript.done') {
            $events = [];
            if (is_string($payload['text'] ?? null)) {
                $events[] = new TranscriptCompleted(
                    $payload['text'],
                    $this->itemId($payload),
                    TranscriptSource::Input,
                );
            }

            $this->receivedDoneEvents++;
            if ($this->receivedDoneEvents >= $this->expectedDoneEvents) {
                if (! $this->connection->isClosed()) {
                    $this->connection->close();
                }

                $events[] = new LiveClosed(reason: 'transcript.done');
            }

            return $events;
        }

        return [new ProviderEvent($type, $payload)];
    }

    private function webSocketUrl(): string
    {
        $base = preg_replace('#^http://#', 'ws://', $this->options->baseUrl);
        $base = preg_replace('#^https://#', 'wss://', $base ?? $this->options->baseUrl) ?? $this->options->baseUrl;
        $query = $this->queryOptions();

        return Url::joinPath($base, '/stt').($query === '' ? '' : '?'.$query);
    }

    private function queryOptions(): string
    {
        return $this->encodeQuery($this->queryValues());
    }

    /** @return array<string, mixed> */
    private function queryValues(): array
    {
        $provider = $this->request->providerOptions[XAIOptions::PROVIDER_NAME] ?? [];
        $providerQuery = is_array($provider['query'] ?? null) ? $provider['query'] : [];
        $raw = is_array($provider['raw'] ?? null) ? $provider['raw'] : [];
        $direct = array_diff_key($provider, array_flip(['headers', 'query', 'raw', 'session']));

        $query = array_replace([
            'encoding' => $this->encoding(),
            'interim_results' => true,
        ], $direct, $providerQuery, $raw);

        if (is_string($this->request->options['language'] ?? null)) {
            $query['language'] = $this->request->options['language'];
        }

        $allowed = array_flip([
            'sample_rate',
            'encoding',
            'interim_results',
            'endpointing',
            'language',
            'diarize',
            'filler_words',
            'multichannel',
            'channels',
            'keyterm',
            'smart_turn',
            'smart_turn_timeout',
        ]);
        $query = array_intersect_key($query, $allowed);

        return $query;
    }

    /** @param array<string, mixed> $query */
    private function encodeQuery(array $query): string
    {

        $pairs = [];
        foreach ($query as $name => $value) {
            foreach (is_array($value) ? $value : [$value] as $item) {
                if (! is_scalar($item)) {
                    continue;
                }

                $encoded = is_bool($item) ? ($item ? 'true' : 'false') : (string) $item;
                $pairs[] = rawurlencode((string) $name).'='.rawurlencode($encoded);
            }
        }

        return implode('&', $pairs);
    }

    private function expectedDoneEvents(): int
    {
        $query = $this->queryValues();
        $multichannel = filter_var($query['multichannel'] ?? false, FILTER_VALIDATE_BOOL);
        $channels = $query['channels'] ?? 1;

        return $multichannel && is_numeric($channels)
            ? max(1, (int) $channels)
            : 1;
    }

    /** @param array<string, mixed> $payload */
    private function itemId(array $payload): ?string
    {
        if (is_string($payload['item_id'] ?? null)) {
            return $payload['item_id'];
        }

        return is_int($payload['channel_index'] ?? null)
            ? 'channel:'.$payload['channel_index']
            : null;
    }

    private function waitUntilReady(): void
    {
        $frame = $this->connection->receive();
        if ($frame === null) {
            throw new InvalidArgumentException('xAI closed the streaming transcription connection before transcript.created.');
        }

        if ($frame->type !== TransportFrameType::Text) {
            $this->connection->close();

            throw new InvalidArgumentException('xAI sent a non-text event before transcript.created.');
        }

        try {
            $payload = json_decode($frame->payload, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            $this->connection->close();

            throw new InvalidArgumentException('xAI sent invalid JSON before transcript.created.', [], $exception);
        }

        if (! is_array($payload) || ($payload['type'] ?? null) !== 'transcript.created') {
            $this->connection->close();
            $message = is_array($payload) && is_string($payload['message'] ?? null)
                ? $payload['message']
                : 'xAI did not send transcript.created before streaming audio.';

            throw new InvalidArgumentException($message);
        }

        // Preserve the provider handshake event for consumers that need its
        // raw session metadata, even though connect() consumes it for the
        // protocol readiness gate.
        $this->initialFrames[] = $frame;
    }

    private function encoding(): string
    {
        $format = $this->request->options['input_audio_format'] ?? 'pcm16';

        return match (is_string($format) ? strtolower($format) : 'pcm16') {
            'pcmu', 'mulaw', 'g711_ulaw', 'audio/pcmu' => 'mulaw',
            'pcma', 'alaw', 'g711_alaw', 'audio/pcma' => 'alaw',
            default => 'pcm',
        };
    }

    /** @param array<string, mixed> $event */
    private function sendEvent(array $event): void
    {
        $this->connection->send(TransportFrame::text(json_encode($event, JSON_THROW_ON_ERROR)));
    }
}
