<?php

declare(strict_types=1);

namespace AiSdk\XAI\Live;

use AiSdk\Live\Contracts\LiveSessionDriverInterface;
use AiSdk\Live\Contracts\ProviderLiveCallInterface;
use AiSdk\Live\Contracts\TransportInterface;
use AiSdk\Live\LiveRequest;
use AiSdk\XAI\XAIOptions;

/** xAI inbound SIP call attachment and hangup lifecycle. */
final readonly class XAILiveCall implements ProviderLiveCallInterface
{
    public function __construct(
        private string $callId,
        private string $modelId,
        private XAIOptions $options,
        private LiveRequest $request,
    ) {}

    public function id(): string
    {
        return $this->callId;
    }

    public function accept(): void
    {
        // xAI has no accept REST endpoint. Its documented inbound SIP flow
        // makes the call available immediately; connect() joins it by call_id
        // and sends session.update to configure the agent.
    }

    public function connect(TransportInterface $transport): LiveSessionDriverInterface
    {
        return new XAILiveSessionDriver(
            modelId: $this->modelId,
            options: $this->options,
            request: $this->request,
            transport: $transport,
            callId: $this->callId,
        );
    }

    public function hangup(): void
    {
        XAILiveHttp::postEmpty(
            $this->options,
            '/realtime/calls/'.rawurlencode($this->callId).'/hangup',
        );
    }
}
