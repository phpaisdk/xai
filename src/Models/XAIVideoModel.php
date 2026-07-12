<?php

declare(strict_types=1);

namespace AiSdk\XAI\Models;

use AiSdk\Content;
use AiSdk\ContentSource;
use AiSdk\Contracts\BaseModel;
use AiSdk\Contracts\VideoModelInterface;
use AiSdk\Exceptions\InvalidResponseException;
use AiSdk\Requests\VideoRequest;
use AiSdk\Responses\VideoJob;
use AiSdk\Responses\VideoJobStatus;
use AiSdk\Results\VideoData;
use AiSdk\Support\Usage;
use AiSdk\Utils\Support\Url;
use AiSdk\XAI\XAIOptions;

final class XAIVideoModel extends BaseModel implements VideoModelInterface
{
    public function __construct(private readonly string $modelId, private readonly XAIOptions $options) {}

    public function provider(): string
    {
        return XAIOptions::PROVIDER_NAME;
    }

    public function modelId(): string
    {
        return $this->modelId;
    }

    public function generate(VideoRequest $request): VideoJob
    {
        $options = $request->providerOptionsFor($this->provider());
        $mode = (string) ($options['mode'] ?? (($request->video !== null || isset($options['videoUrl'])) ? 'edit-video' : (isset($options['referenceImageUrls']) ? 'reference-to-video' : 'generation')));
        $endpoint = match ($mode) {
            'edit-video' => '/videos/edits', 'extend-video' => '/videos/extensions', default => '/videos/generations'
        };
        $sourceVideo = $request->video !== null ? $this->media($request->video) : ($options['videoUrl'] ?? null);
        $body = ['model' => $this->modelId, 'prompt' => $request->prompt];
        if ($request->image !== null) {
            $body['image'] = ['url' => $this->media($request->image)];
        }
        if (is_string($sourceVideo) && $sourceVideo !== '') {
            $body['video'] = ['url' => $sourceVideo];
        }
        if (is_array($options['referenceImageUrls'] ?? null)) {
            $body['reference_images'] = array_map(static fn (string $url): array => ['url' => $url], $options['referenceImageUrls']);
        }
        $duration = $request->output !== null ? $request->output->duration : ($options['duration'] ?? null);
        if (! in_array($mode, ['edit-video'], true) && $duration !== null) {
            $body['duration'] = $duration;
        }
        if (! in_array($mode, ['edit-video', 'extend-video'], true)) {
            $aspectRatio = $request->output !== null ? $request->output->aspectRatio : ($options['aspectRatio'] ?? null);
            if ($aspectRatio !== null) {
                $body['aspect_ratio'] = $aspectRatio;
            }
            $resolution = $request->output !== null ? $request->output->resolution : ($options['resolution'] ?? null);
            if ($resolution !== null) {
                $body['resolution'] = $this->resolution((string) $resolution);
            }
        }
        $reserved = array_flip(['mode', 'videoUrl', 'referenceImageUrls', 'pollIntervalMs', 'pollTimeoutMs', 'duration', 'aspectRatio', 'resolution']);
        $body = array_replace($body, array_diff_key($options, $reserved));
        $payload = $this->runner($this->options->sdk)->postJson(Url::joinPath($this->options->baseUrl, $endpoint), $body, $this->options->authHeaders(), $this->provider());
        $id = $payload['request_id'] ?? null;
        if (! is_string($id) || $id === '') {
            throw InvalidResponseException::forProvider($this->provider(), 'xAI returned no video request_id.', ['body' => $payload]);
        }

        return new VideoJob($id, $this->provider(), $this->modelId, rawResponse: $payload, providerMetadata: [$this->provider() => ['requestId' => $id, 'mode' => $mode, 'pollIntervalMs' => (int) ($options['pollIntervalMs'] ?? 5000), 'pollTimeoutMs' => (int) ($options['pollTimeoutMs'] ?? 600000), 'resolution' => $body['resolution'] ?? null]]);
    }

    public function poll(VideoJob $job): VideoJob
    {
        if (in_array($job->status, [VideoJobStatus::Succeeded, VideoJobStatus::Failed, VideoJobStatus::Canceled, VideoJobStatus::Expired], true)) {
            return $job;
        }
        $payload = $this->runner($this->options->sdk)->getJson(Url::joinPath($this->options->baseUrl, '/videos/'.rawurlencode($job->id)), $this->options->authHeaders(), $this->provider());
        $status = (string) ($payload['status'] ?? 'pending');
        if ($status === 'done' || isset($payload['video']['url'])) {
            $url = $payload['video']['url'] ?? null;
            if (! is_string($url) || $url === '') {
                return $this->terminal($job, VideoJobStatus::Failed, $payload, 'xAI completed video generation without a video URL.');
            }
            if (($payload['video']['respect_moderation'] ?? true) === false) {
                return $this->terminal($job, VideoJobStatus::Failed, $payload, 'xAI blocked the generated video under its content policy.');
            }

            return new VideoJob($job->id, $job->provider, $job->modelId, VideoJobStatus::Succeeded, new VideoData(url: $url, duration: isset($payload['video']['duration']) ? (float) $payload['video']['duration'] : null, resolution: $job->providerMetadata[$this->provider()]['resolution'] ?? null), usage: Usage::empty(), rawResponse: $payload, providerMetadata: $job->providerMetadata);
        }

        return match ($status) {
            'failed' => $this->terminal($job, VideoJobStatus::Failed, $payload, (string) ($payload['error'] ?? 'xAI video generation failed.')), 'expired' => $this->terminal($job, VideoJobStatus::Expired, $payload, 'xAI video generation expired.'), default => new VideoJob($job->id, $job->provider, $job->modelId, VideoJobStatus::Running, rawResponse: $payload, providerMetadata: $job->providerMetadata)
        };
    }

    /** @param array<string, mixed> $payload */
    private function terminal(VideoJob $job, VideoJobStatus $status, array $payload, string $message): VideoJob
    {
        return new VideoJob($job->id, $job->provider, $job->modelId, $status, errorMessage: $message, rawResponse: $payload, providerMetadata: $job->providerMetadata);
    }

    private function media(Content $content): string
    {
        return $content->source() === ContentSource::Url ? (string) $content->url() : 'data:'.$content->mimeType().';base64,'.$content->base64Data();
    }

    private function resolution(string $value): string
    {
        return match ($value) {
            '1280x720' => '720p', '854x480', '640x480' => '480p', default => $value
        };
    }
}
