# Changelog

All notable changes to this project are documented here. The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and the project uses [Semantic Versioning](https://semver.org/).

## [Unreleased]

## [0.2.0] - 2026-11-12
### Fixed

- fix test job failure on PHP 8.2 by using consistent test command across environments
- initialize variables before try-catch in start() to avoid warning on exception path

### Changed

- add --do-not-cache-result to avoid cache-related test failures on PHP 8.3

### Added

- persist last_update_id using structured JSON with legacy plain-text support

## [0.1.0] - 2026-11-10

### Added

- Telegram Polling — Long-polling via Bot API with automatic offset tracking
- Smart Parser — Regex-based extraction of order ID, totals, items and metadata
- Webhook Dispatch — POSTs parsed orders to any configured HTTP endpoint
- Multiple Outputs — Console logging, JSON lines file, or custom processors
- Update Tracking — Persists last_update_id to avoid reprocessing messages
- Simple Config — Single JSON file with token, chat_id, patterns and outputs
- Tested Core — PHPUnit suite covering many ORDER_PLACED message variants
- CLI Flags — Supports --config, --once, --dry-run for easy testing
