<?php

declare(strict_types=1);

namespace OrderGram;

use JsonException;

/**
 * Configuration loader and validator for OrderGram.
 *
 * Loads a JSON configuration file, performs strict validation on
 * credentials, patterns and output settings, and provides typed
 * accessors. Throws descriptive exceptions on any misconfiguration.
 */
final class Config
{
    private string $botToken;
    private int $chatId;
    private int $pollTimeout;
    private string $lastUpdateFile;
    /** @var array<string> */
    private array $outputs;
    private ?string $webhookUrl;
    private string $filePath;
    /** @var array<string,string> */
    private array $parsePatterns;

    /**
     * @param array<string,mixed> $data Raw decoded JSON data
     */
    private function __construct(array $data)
    {
        $this->botToken = $this->validateBotToken($data['bot_token'] ?? '');
        $this->chatId = $this->validateChatId($data['chat_id'] ?? null);
        $this->pollTimeout = max(5, (int) ($data['poll_timeout'] ?? 30));
        $this->lastUpdateFile = (string) ($data['last_update_file'] ?? '.last_update.json');
        $this->outputs = $this->validateOutputs($data['outputs'] ?? ['console']);
        $this->webhookUrl = isset($data['webhook_url']) && is_string($data['webhook_url']) && $data['webhook_url'] !== ''
            ? $data['webhook_url']
            : null;
        $this->filePath = (string) ($data['file_path'] ?? 'orders.jsonl');
        $this->parsePatterns = $this->validateParsePatterns($data['parse_patterns'] ?? []);
    }

    /**
     * Load and validate configuration from a JSON file.
     *
     * @throws \InvalidArgumentException On any validation failure
     * @throws JsonException If JSON cannot be decoded
     */
    public static function fromFile(string $path): self
    {
        if (!file_exists($path)) {
            throw new \InvalidArgumentException("Configuration file not found: {$path}");
        }

        if (!is_readable($path)) {
            throw new \InvalidArgumentException("Configuration file is not readable: {$path}");
        }

        $content = file_get_contents($path);
        if ($content === false) {
            throw new \InvalidArgumentException("Failed to read configuration file: {$path}");
        }

        try {
            $data = json_decode($content, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new \InvalidArgumentException("Invalid JSON syntax in {$path}", 0, $e);
        }

        if (!is_array($data)) {
            throw new \InvalidArgumentException("Configuration root must be a JSON object");
        }

        if (empty($data['bot_token'])) {
            throw new \InvalidArgumentException('bot_token is required and cannot be empty');
        }

        if (!isset($data['chat_id']) || !is_numeric($data['chat_id'])) {
            throw new \InvalidArgumentException('chat_id is required and must be an integer (negative for groups)');
        }

        if (empty($data['parse_patterns']) || !is_array($data['parse_patterns'])) {
            throw new \InvalidArgumentException('parse_patterns must be a non-empty object containing regex rules');
        }

        return new self($data);
    }

    private function validateBotToken(string $token): string
    {
        $token = trim($token);
        if ($token === '' || !str_contains($token, ':')) {
            throw new \InvalidArgumentException(
                'bot_token must be in the format "123456:AAFxxxxxxxxxxxxxxxxxxxxxxxxxxxxx"'
            );
        }
        return $token;
    }

    private function validateChatId(mixed $chatId): int
    {
        $id = (int) $chatId;
        if ($id === 0) {
            throw new \InvalidArgumentException('chat_id must be a valid integer (usually negative for supergroups)');
        }
        return $id;
    }

    /**
     * @return array<string>
     */
    private function validateOutputs(mixed $outputs): array
    {
        if (!is_array($outputs) || $outputs === []) {
            return ['console'];
        }

        $allowed = ['console', 'webhook', 'file'];
        $validated = [];

        foreach ($outputs as $output) {
            if (!in_array($output, $allowed, true)) {
                throw new \InvalidArgumentException(
                    "Invalid output type '{$output}'. Allowed values: console, webhook, file"
                );
            }
            $validated[] = (string) $output;
        }

        return array_values(array_unique($validated));
    }

    /**
     * @return array<string,string>
     */
    private function validateParsePatterns(mixed $patterns): array
    {
        if (!is_array($patterns) || !isset($patterns['detect'])) {
            throw new \InvalidArgumentException("parse_patterns must contain a 'detect' regex");
        }

        foreach ($patterns as $name => $pattern) {
            if (!is_string($pattern) || trim($pattern) === '') {
                throw new \InvalidArgumentException("parse_patterns.{$name} must be a non-empty string");
            }

            // Test regex validity (suppressing warnings)
            if (@preg_match($pattern, '') === false) {
                throw new \InvalidArgumentException("parse_patterns.{$name} is not a valid PCRE regex: {$pattern}");
            }
        }

        return $patterns;
    }

    public function getBotToken(): string
    {
        return $this->botToken;
    }

    public function getChatId(): int
    {
        return $this->chatId;
    }

    public function getPollTimeout(): int
    {
        return $this->pollTimeout;
    }

    public function getLastUpdateFile(): string
    {
        return $this->lastUpdateFile;
    }

    /**
     * @return array<string>
     */
    public function getOutputs(): array
    {
        return $this->outputs;
    }

    public function getWebhookUrl(): ?string
    {
        return $this->webhookUrl;
    }

    public function getFilePath(): string
    {
        return $this->filePath;
    }

    /**
     * @return array<string,string>
     */
    public function getParsePatterns(): array
    {
        return $this->parsePatterns;
    }
}
