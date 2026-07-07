# aisdk/xai

Official xAI provider for the PHP AI SDK. Uses the shared OpenAI-compatible chat-completions adapter for v0.1 text support.

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
