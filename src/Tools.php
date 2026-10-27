<?php

declare(strict_types=1);

namespace AiSdk\XAI;

/** xAI-hosted tools execute remotely, never through the local ToolExecutor. */
final class Tools
{
    /**
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     */
    public static function webSearch(array $options = []): array
    {
        return ['type' => 'web_search'] + $options;
    }
    /**
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     */
    public static function xSearch(array $options = []): array
    {
        return ['type' => 'x_search'] + $options;
    }
    /** @return array<string, mixed> */
    public static function codeExecution(): array
    {
        return ['type' => 'code_interpreter'];
    }
    /**
     * @param list<string> $collectionIds
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     */
    public static function collectionsSearch(array $collectionIds, array $options = []): array
    {
        if ($collectionIds === []) {
            throw new \InvalidArgumentException('At least one collection id is required.');
        }
        foreach ($collectionIds as $id) {
            if ($id === '') {
                throw new \InvalidArgumentException('Collection ids cannot be empty.');
            }
        }

        return ['type' => 'file_search', 'vector_store_ids' => array_values($collectionIds)] + $options;
    }
}
