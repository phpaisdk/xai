<?php

declare(strict_types=1);

namespace AiSdk\XAI\Live;

use AiSdk\Generate;
use AiSdk\Utils\Http\HttpRunner;
use AiSdk\Utils\Support\Url;
use AiSdk\XAI\XAIOptions;

/** REST requests used by xAI Voice Agent credentials and SIP control. */
final class XAILiveHttp
{
    /**
     * @param  array<string, mixed>  $body
     * @return array<string, mixed>
     */
    public static function postJson(XAIOptions $options, string $path, array $body): array
    {
        return self::runner($options)->postJson(
            Url::joinPath($options->baseUrl, $path),
            $body,
            $options->authHeaders(),
            XAIOptions::PROVIDER_NAME,
        );
    }

    public static function postEmpty(XAIOptions $options, string $path): void
    {
        $sdk = $options->sdk ?? Generate::sdk();
        $request = $sdk->requestFactory
            ->createRequest('POST', Url::joinPath($options->baseUrl, $path));

        foreach ($options->authHeaders() as $name => $value) {
            $request = $request->withHeader($name, $value);
        }

        HttpRunner::fromSdk($sdk)->sendRequest($request, XAIOptions::PROVIDER_NAME);
    }

    private static function runner(XAIOptions $options): HttpRunner
    {
        return HttpRunner::fromSdk($options->sdk ?? Generate::sdk());
    }
}
