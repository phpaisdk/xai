<?php

declare(strict_types=1);

namespace AiSdk\XAI\Live;

use AiSdk\Live\LiveRequest;
use AiSdk\Tool;
use AiSdk\XAI\XAIOptions;

/** Builds xAI Voice Agent session.update payloads. */
final class XAILiveConfiguration
{
    /** @return array<string, mixed> */
    public static function session(LiveRequest $request): array
    {
        $session = [
            'audio' => [
                'input' => ['format' => self::audioFormat($request->options['input_audio_format'] ?? 'pcm16')],
                'output' => ['format' => self::audioFormat($request->options['output_audio_format'] ?? 'pcm16')],
            ],
        ];

        if (is_string($request->options['instructions'] ?? null)) {
            $session['instructions'] = $request->options['instructions'];
        }
        if (is_string($request->options['voice'] ?? null)) {
            $session['voice'] = $request->options['voice'];
        }
        if (is_string($request->options['language'] ?? null)) {
            $session['audio']['input']['transcription']['language_hint'] = $request->options['language'];
        }
        if (array_key_exists('turn_detection', $request->options)) {
            $value = $request->options['turn_detection'];
            $session['turn_detection'] = self::turnDetection($value);
        }
        if ($request->tools !== []) {
            $session['tools'] = array_map(self::toolDefinition(...), $request->tools);
            $session['tool_choice'] = 'auto';
        }

        $provider = $request->providerOptions[XAIOptions::PROVIDER_NAME] ?? [];
        $direct = array_diff_key($provider, array_flip([
            'headers',
            'query',
            'raw',
            'session',
            'client_secret',
            'expires_after',
        ]));
        $session = array_replace_recursive($session, $direct);

        if (is_array($provider['session'] ?? null)) {
            $session = array_replace_recursive($session, $provider['session']);
        }
        if (is_array($provider['raw'] ?? null)) {
            $session = array_replace_recursive($session, $provider['raw']);
        }

        return $session;
    }

    /** @return array<string, mixed> */
    public static function clientSecretBody(LiveRequest $request): array
    {
        $provider = $request->providerOptions[XAIOptions::PROVIDER_NAME] ?? [];
        $body = ['expires_after' => ['seconds' => 300]];

        if (is_array($provider['client_secret'] ?? null)) {
            $body = array_replace_recursive($body, $provider['client_secret']);
        }
        if (is_array($provider['expires_after'] ?? null)) {
            $body['expires_after'] = $provider['expires_after'];
        }

        return $body;
    }

    /** @return array<string, mixed> */
    private static function audioFormat(mixed $format): array
    {
        if (! is_string($format)) {
            return ['type' => 'audio/pcm', 'rate' => 24000];
        }

        return match (strtolower($format)) {
            'pcm', 'pcm16', 'audio/pcm' => ['type' => 'audio/pcm', 'rate' => 24000],
            'pcmu', 'mulaw', 'g711_ulaw', 'audio/pcmu' => ['type' => 'audio/pcmu'],
            'pcma', 'alaw', 'g711_alaw', 'audio/pcma' => ['type' => 'audio/pcma'],
            default => ['type' => $format],
        };
    }

    private static function turnDetection(mixed $value): mixed
    {
        if (is_array($value) || $value === null) {
            return $value;
        }

        if (is_string($value) && in_array(strtolower($value), ['none', 'disabled'], true)) {
            return null;
        }

        return is_string($value) ? ['type' => $value] : $value;
    }

    /** @return array<string, mixed> */
    private static function toolDefinition(Tool $tool): array
    {
        return [
            'type' => 'function',
            'name' => $tool->name(),
            'description' => $tool->description(),
            'parameters' => $tool->inputSchemaForProvider(),
        ];
    }
}
