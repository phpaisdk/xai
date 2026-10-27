<?php

declare(strict_types=1);

namespace AiSdk\XAI\Models;

use AiSdk\Capability;
use AiSdk\Contracts\BaseModel;
use AiSdk\Contracts\TextModelInterface;
use AiSdk\OpenAICompatible\ChatRequestBuilder;
use AiSdk\OpenAICompatible\ChatResponseParser;
use AiSdk\OpenAICompatible\ChatStreamParser;
use AiSdk\Requests\TextModelRequest;
use AiSdk\Responses\TextModelResponse;
use AiSdk\Utils\Support\Url;
use AiSdk\XAI\XAIOptions;
use Generator;

final class XAITextModel extends BaseModel implements TextModelInterface
{
    private const array ADAPTER_CAPABILITIES = [
        Capability::TextGeneration,
        Capability::Streaming,
        Capability::ToolCalling,
        Capability::StructuredOutput,
        Capability::Reasoning,
        Capability::TextInput,
        Capability::ImageInput,
        Capability::FileInput,
    ];

    public function __construct(
        private readonly string $modelId,
        private readonly XAIOptions $options,
    ) {}

    protected function supportsStoredFiles(): bool
    {
        return true;
    }

    public function provider(): string
    {
        return XAIOptions::PROVIDER_NAME;
    }

    public function modelId(): string
    {
        return $this->modelId;
    }

    public function generate(TextModelRequest $request): TextModelResponse
    {
        $this->ensureTextRequestSupported($request, self::ADAPTER_CAPABILITIES);

        if ($this->usesResponses($request)) {
            $body = $this->responsesBody($request, false);
            $url = Url::joinPath($this->options->baseUrl, '/responses');

            return \AiSdk\OpenAICompatible\ResponsesResponseParser::parse($this->runner($this->options->sdk)->postJson($url, $body, $this->options->authHeaders(), $this->provider()), $this->provider());
        }

        $body = ChatRequestBuilder::build($this->modelId, $this->provider(), $request, stream: false);
        $url = Url::joinPath($this->options->baseUrl, '/chat/completions');

        $payload = $this->runner($this->options->sdk)
            ->postJson($url, $body, $this->options->authHeaders(), $this->provider());

        return ChatResponseParser::parse($payload, $this->provider());
    }

    public function stream(TextModelRequest $request): Generator
    {
        $this->ensureTextRequestSupported($request, self::ADAPTER_CAPABILITIES, streaming: true);

        if ($this->usesResponses($request)) {
            $body = $this->responsesBody($request, true);
            $url = Url::joinPath($this->options->baseUrl, '/responses');
            yield from \AiSdk\OpenAICompatible\ResponsesStreamParser::parse($this->runner($this->options->sdk)->postStream($url, $body, $this->options->authHeaders(), $this->provider()), $this->provider());

            return;
        }

        $body = ChatRequestBuilder::build($this->modelId, $this->provider(), $request, stream: true);
        $url = Url::joinPath($this->options->baseUrl, '/chat/completions');

        $events = $this->runner($this->options->sdk)
            ->postStream($url, $body, $this->options->authHeaders(), $this->provider());

        yield from ChatStreamParser::parse($events, $this->provider());
    }
    private function usesResponses(TextModelRequest $request): bool
    {
        $options = $request->providerOptionsFor($this->provider());
        $api = $options['api'] ?? null;
        if ($api !== null && ! in_array($api, ['responses', 'chat'], true)) {
            throw new \InvalidArgumentException('api must be responses or chat.');
        }
        if (isset($options['serverTools'])) {
            if ($api === 'chat') {
                throw new \InvalidArgumentException('Server tools require the Responses API.');
            }

            return true;
        }
        if ($api !== null) {
            return $api === 'responses';
        }
        foreach ($request->messages as $message) {
            foreach ($message->content as $content) {
                if ($content->type === \AiSdk\Content::TYPE_FILE || $content->fileReference() !== null) {
                    return true;
                }
            }
        }

        return false;
    }
    /** @return array<string, mixed> */
    private function responsesBody(TextModelRequest $request, bool $stream): array
    {
        $body = \AiSdk\OpenAICompatible\ResponsesRequestBuilder::build($this->modelId, $this->provider(), $request, $stream);
        $tools = $body['serverTools'] ?? [];
        unset($body['serverTools']);
        if (! is_array($tools) || ! array_is_list($tools)) {
            throw new \InvalidArgumentException('serverTools must be a list of xAI tool configurations.');
        }
        foreach ($tools as $tool) {
            if (! is_array($tool) || ! is_string($tool['type'] ?? null) || $tool['type'] === 'function') {
                throw new \InvalidArgumentException('Use tools() for local function tools.');
            }
        }
        if ($tools !== []) {
            $body['tools'] = array_merge(is_array($body['tools'] ?? null) ? $body['tools'] : [], $tools);
        }

        return $body;
    }
}
