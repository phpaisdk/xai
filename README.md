# PHP AI SDK — xAI

Text, image, audio, embedding, and video generation, plus live voice sessions with xAI. Part of the framework-agnostic [PHP AI SDK](https://phpaisdk.com).

## Installation

```bash
composer require aisdk/xai
```

Set `XAI_API_KEY` in your environment.

## Usage

```php
use AiSdk\Generate;
use AiSdk\XAI;

$result = Generate::text('Explain closures in PHP in one sentence.')
    ->model(XAI::model('grok-4.3-latest'))
    ->run();

echo $result->text;
```

## Documentation

[Read the documentation](https://phpaisdk.com/docs/0.x/providers/xai) for configuration, supported features, and more examples.

[Contributing](https://github.com/phpaisdk/.github/blob/main/CONTRIBUTING.md) · [Support](https://github.com/phpaisdk/.github/blob/main/SUPPORT.md)

## License

The MIT License (MIT). See [LICENSE](LICENSE) for details.
