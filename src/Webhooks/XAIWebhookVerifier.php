<?php

declare(strict_types=1);

namespace AiSdk\XAI\Webhooks;

use AiSdk\Exceptions\InvalidArgumentException;
use JsonException;

/** Verifies xAI SIP webhooks from their whsec secret and signed headers. */
final class XAIWebhookVerifier
{
    /**
     * The payload must be the exact raw request body; parsing and re-encoding it
     * before verification changes the signed bytes.
     *
     * @param  array<string, string|list<string>>  $headers
     * @return array<string, mixed>
     */
    public static function verify(string $payload, array $headers, string $signingSecret): array
    {
        $headers = self::headers($headers);
        $messageId = $headers['webhook-id'] ?? null;
        $timestamp = $headers['webhook-timestamp'] ?? null;
        $signatures = $headers['webhook-signature'] ?? null;

        if (! is_string($messageId) || ! is_string($timestamp) || ! is_string($signatures)) {
            throw new InvalidArgumentException('xAI webhook signature headers are incomplete.');
        }
        if (preg_match('/^[1-9][0-9]*$/', $timestamp) !== 1 || abs(time() - (int) $timestamp) > 300) {
            throw new InvalidArgumentException('xAI webhook timestamp is outside the five-minute verification window.');
        }

        $secret = str_starts_with($signingSecret, 'whsec_') ? substr($signingSecret, 6) : $signingSecret;
        $secret = base64_decode($secret, true);
        if ($secret === false) {
            throw new InvalidArgumentException('xAI webhook signing secret is not valid base64.');
        }

        $expected = base64_encode(hash_hmac(
            'sha256',
            $messageId.'.'.$timestamp.'.'.$payload,
            $secret,
            true,
        ));
        $verified = false;
        foreach (preg_split('/\s+/', trim($signatures)) ?: [] as $signature) {
            [$version, $value] = array_pad(explode(',', $signature, 2), 2, null);
            if ($version === 'v1' && is_string($value) && hash_equals($expected, $value)) {
                $verified = true;
                break;
            }
        }

        if (! $verified) {
            throw new InvalidArgumentException('Invalid xAI webhook signature.');
        }

        try {
            $event = json_decode($payload, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new InvalidArgumentException('The verified xAI webhook payload is not valid JSON.', [], $exception);
        }

        if (! is_array($event)) {
            throw new InvalidArgumentException('The verified xAI webhook payload must be a JSON object.');
        }

        return $event;
    }

    /**
     * @param  array<string, string|list<string>>  $headers
     * @return array<string, string>
     */
    private static function headers(array $headers): array
    {
        $normalized = [];
        foreach ($headers as $name => $value) {
            $normalized[strtolower($name)] = is_array($value) ? implode(' ', $value) : $value;
        }

        return $normalized;
    }
}
