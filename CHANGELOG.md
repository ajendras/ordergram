# Changelog

All notable changes to this project are documented here. The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and the project uses [Semantic Versioning](https://semver.org/).

## [Unreleased]

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
