<?php

declare(strict_types=1);

namespace AiSdk;

use AiSdk\Contracts\Model;
use AiSdk\XAI\Webhooks\XAIWebhookVerifier;
use AiSdk\XAI\XAIOptions;
use AiSdk\XAI\XAIProvider;

final class XAI
{
    private static ?XAIProvider $default = null;

    /**
     * @param  array<string, mixed>  $config
     */
    public static function create(array $config = []): XAIProvider
    {
        return self::$default = new XAIProvider(XAIOptions::fromArray($config));
    }

    public static function default(): XAIProvider
    {
        return self::$default ??= self::create();
    }

    public static function reset(): void
    {
        self::$default = null;
    }

    public static function model(string $modelId): Model
    {
        return self::default()->model($modelId);
    }

    /**
     * Verify and decode an xAI webhook from its exact raw request body.
     *
     * @param  array<string, string|list<string>>  $headers
     * @return array<string, mixed>
     */
    public static function verifyWebhook(string $payload, array $headers, string $signingSecret): array
    {
        return XAIWebhookVerifier::verify($payload, $headers, $signingSecret);
    }
}
