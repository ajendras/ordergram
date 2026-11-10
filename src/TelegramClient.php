<?php

declare(strict_types=1);

namespace OrderGram;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use JsonException;

/**
 * Thin wrapper around the Telegram Bot API using Guzzle.
 *
 * Provides long-polling via getUpdates(), offset-based acknowledgment,
 * and URL construction. All network errors are converted to
 * RuntimeException with clear context.
 */
final class TelegramClient
{
    private Client $http;
    private string $botToken;
    private const API_BASE = 'https://api.telegram.org/bot';

    public function __construct(string $botToken)
    {
        if ($botToken === '' || !str_contains($botToken, ':')) {
            throw new \InvalidArgumentException('Invalid bot token format');
        }

        $this->botToken = $botToken;
        $this->http = new Client([
            'timeout' => 35,
            'http_errors' => false,
            'headers' => [
                'User-Agent' => 'OrderGram/1.0 (+https://github.com/ordergram/ordergram)',
            ],
        ]);
    }

    /**
     * Fetch new updates via long polling.
     *
     * @param int $offset Last processed update_id + 1 (0 on first run)
     * @param int $timeout Long-poll timeout in seconds (Telegram max 60)
     * @return array<int,array<string,mixed>> Array of update objects
     * @throws \RuntimeException On network, HTTP or JSON errors
     */
    public function getUpdates(int $offset, int $timeout = 30): array
    {
        $url = $this->buildUrl('getUpdates');

        $query = [
            'offset' => $offset,
            'timeout' => min(60, max(1, $timeout)),
            'allowed_updates' => ['message'],
        ];

        try {
            $response = $this->http->get($url, ['query' => $query]);
            $status = $response->getStatusCode();

            if ($status !== 200) {
                throw new \RuntimeException("Telegram API HTTP error: {$status}");
            }

            $body = $response->getBody()->getContents();
            $data = json_decode($body, true, 512, JSON_THROW_ON_ERROR);

            if (!isset($data['ok']) || $data['ok'] !== true) {
                $desc = $data['description'] ?? 'Unknown Telegram error';
                throw new \RuntimeException("Telegram API error: {$desc}");
            }

            return $data['result'] ?? [];
        } catch (GuzzleException $e) {
            throw new \RuntimeException(
                'Network failure while polling Telegram: ' . $e->getMessage(),
                0,
                $e
            );
        } catch (JsonException $e) {
            throw new \RuntimeException('Telegram returned invalid JSON', 0, $e);
        }
    }

    /**
     * Acknowledge an update by advancing the offset.
     *
     * We perform a quick getUpdates call with the next offset.
     * Failures are non-fatal; the worst outcome is duplicate processing.
     */
    public function acknowledge(int $updateId): void
    {
        if ($updateId < 0) {
            return;
        }

        try {
            // Use a minimal timeout to just advance the offset
            $this->getUpdates($updateId + 1, 2);
        } catch (\Throwable) {
            // Acknowledgment is best-effort; we swallow errors to keep
            // the main polling loop alive during transient network issues.
        }
    }

    /**
     * Build a full Bot API endpoint URL.
     */
    public function buildUrl(string $method): string
    {
        return self::API_BASE . $this->botToken . '/' . ltrim($method, '/');
    }

    /**
     * Expose the underlying HTTP client (primarily for testing).
     */
    public function getHttpClient(): Client
    {
        return $this->http;
    }
}
