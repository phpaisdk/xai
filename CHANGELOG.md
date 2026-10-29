# Changelog

Notable changes to `aisdk/xai`. Release notes are also published at https://github.com/phpaisdk/xai/releases.

## Unreleased (v0.10.1)

- Raise SDK exceptions instead of native PHP ones.

## v0.10.0 — 2026-10-29

### What's new

- Manage files with `XAI::files()`, including retention options and cursor pagination; attachments use Responses.
- Configure hosted Web Search, X Search, Code Interpreter and Collections search through `AiSdk\XAI\Tools` beside local tools.
- Fixes: transcription model ids, supported audio formats and timestamped speech, and video extension length versus total duration.

Hosted tools run remotely and sit outside local tool approval.

### Compatibility

- Requires `aisdk/core ^0.10.0` and `aisdk/openai-compatible ^0.10.0`. Upgrade SDK packages together.

## Earlier releases

See the [GitHub releases](https://github.com/phpaisdk/xai/releases) for versions before 0.10.0.
