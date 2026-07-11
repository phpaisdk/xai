# aisdk/xai

Official xAI provider for the PHP AI SDK. Uses shared OpenAI-compatible wire adapters for text and embedding generation.

## Installation

```bash
composer require aisdk/xai
```

## Basic Usage

```php
use AiSdk\Generate;
use AiSdk\XAI;

$result = Generate::text()
    ->model(XAI::model('grok-4.3'))
    ->instructions('Write short, clear answers.')
    ->prompt('Explain closures in PHP.')
    ->run();

echo $result->text;
```

Model IDs pass through unchanged and do not need to be registered. This package does not ship a model inventory; the SDK performs internal adapter validation before xAI validates support for the selected model.

## Embeddings

xAI recommends prefixing retrieval queries with `query: ` and documents with `passage: `:

```php
use AiSdk\Generate;
use AiSdk\XAI;

$result = Generate::embedding([
    'query: framework-agnostic PHP AI SDK',
    'passage: Build provider-neutral AI features in PHP.',
])
    ->model(XAI::embedding('grok-embedding-small'))
    ->dimensions(512)
    ->providerOptions('xai', ['user' => 'user-123'])
    ->run();

$queryVector = $result->embeddings[0]->vector;
$passageVector = $result->embeddings[1]->vector;
```

## Image Generation

```php
use AiSdk\Generate;
use AiSdk\XAI;

$result = Generate::image()
    ->model(XAI::image('grok-imagine-image-quality'))
    ->prompt('A clean app icon for a PHP AI SDK')
    ->aspectRatio('1:1')
    ->run();

$result->output->save(__DIR__.'/icon.png');
```

## Speech Generation

```php
use AiSdk\Generate;
use AiSdk\XAI;

$result = Generate::speech()
    ->model(XAI::speech('grok-voice'))
    ->input('Hello from xAI voice.')
    ->voice('eve')
    ->format('mp3')
    ->providerOptions('xai', [
        'language' => 'auto',
        'speed' => 1.1,
    ])
    ->run();

$result->output->save(__DIR__.'/speech.mp3');
```

## Configuration

| Variable | Description | Default |
|---|---|---|
| `XAI_API_KEY` | API key for authentication | Required |
| `XAI_BASE_URL` | Base URL for API requests | `https://api.x.ai/v1` |

```php
XAI::create([
    'apiKey' => 'xai-...',
    'baseUrl' => 'https://api.x.ai/v1',
]);
```

## Reasoning

```php
use AiSdk\Reasoning;

$result = Generate::text('Explain the tradeoff.')
    ->model(XAI::model('grok-4.3'))
    ->reasoning(Reasoning::effort('low'))
    ->run();
```

## Provider-Specific Options

```php
$result = Generate::text('Hello')
    ->model(XAI::model('grok-4.3'))
    ->providerOptions('xai', [
        'raw' => ['search_parameters' => ['mode' => 'auto']],
    ])
    ->run();
```

## Testing

```bash
composer test
```

## Links

- [xAI API OpenAPI Specification](https://docs.x.ai/openapi.json)
- [Core Package](https://github.com/phpaisdk/core)
