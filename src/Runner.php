<?php

declare(strict_types=1);

namespace OrderGram;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use Throwable;

/**
 * Main orchestration engine for the OrderGram polling loop.
 *
 * Loads last processed update ID, polls Telegram, routes relevant
 * messages through the parser, and dispatches parsed Orders to all
 * configured outputs (console, file, webhook). Supports dry-run and
 * one-shot modes for testing and cron usage.
 */
final class Runner
{
    private int $lastUpdateId = 0;

    /**
     * @param bool $dryRun When true, parsing occurs but no file writes or HTTP posts are performed
     * @param bool $once When true, the polling loop exits after the first request
     */
    public function __construct(
        private readonly Config $config,
        private readonly TelegramClient $client,
        private readonly OrderParser $parser,
        private readonly bool $dryRun = false,
        private readonly bool $once = false
    ) {
        $this->lastUpdateId = $this->loadLastUpdateId();
    }

    /**
     * Start the main polling loop.
     *
     * Uses long-polling via the TelegramClient. Persists update offset
     * after each batch. Handles errors gracefully with exponential back-off.
     */
    public function start(): void
    {
        $offset = $this->lastUpdateId;
        $timeout = $this->config->getPollTimeout();

        echo "\033[36m→\033[0m Starting Telegram long-poll (offset: {$offset}, timeout: {$timeout}s)\n\n";

        while (true) {
            try {
                $updates = $this->client->getUpdates($offset, $timeout);

                $processed = 0;
                foreach ($updates as $update) {
                    $this->processUpdate($update);
                    $processed++;

                    if (isset($update['update_id']) && $update['update_id'] >= $offset) {
                        $offset = $update['update_id'] + 1;
                        $this->saveLastUpdateId($offset);
                    }
                }

                if ($processed > 0) {
                    echo "\033[2mProcessed {$processed} update(s). New offset: {$offset}\033[0m\n";
                }
            } catch (Throwable $e) {
                fwrite(
                    STDERR,
                    "\033[31mPolling error:\033[0m " . $e->getMessage() . "\n"
                );
                sleep(5); // back-off on transient failures
            }

            if ($this->once) {
                echo "\n\033[33m→\033[0m One-shot mode completed.\n";
                break;
            }

            // When long-polling returns immediately we add a small delay
            // to prevent busy-looping on network issues.
            if (empty($updates)) {
                usleep(500_000);
            }
        }
    }

    /**
     * Process a single Telegram update.
     *
     * Filters by configured chat_id, extracts text, runs the parser,
     * and dispatches any resulting Order.
     */
    public function processUpdate(array $update): void
    {
        $message = $update['message'] ?? $update['channel_post'] ?? null;
        if (!is_array($message)) {
            return;
        }

        $chatId = (int) ($message['chat']['id'] ?? 0);
        if ($chatId !== $this->config->getChatId()) {
            return;
        }

        $text = trim($message['text'] ?? $message['caption'] ?? '');
        if ($text === '') {
            return;
        }

        $order = $this->parser->parse($text);
        if ($order === null) {
            return;
        }

        echo "\033[32m✓ Order detected\033[0m #{$order->getId()} • {$order->getCustomer()} • {$order->getAmount()} {$order->getCurrency()}\n";

        $this->dispatch($order);

        if (isset($update['update_id'])) {
            // Best-effort acknowledgment (non-fatal)
            try {
                $this->client->acknowledge($update['update_id']);
            } catch (Throwable) {
                // Swallow - offset persistence is the primary mechanism
            }
        }
    }

    /**
     * Route a parsed order to all configured outputs.
     */
    private function dispatch(Order $order): void
    {
        $payload = $order->toArray();
        $outputs = $this->config->getOutputs();

        if (in_array('console', $outputs, true)) {
            $this->outputToConsole($payload);
        }

        if ($this->dryRun) {
            echo "\033[33m⚠ DRY-RUN\033[0m — skipping persistent outputs for order {$order->getId()}\n\n";
            return;
        }

        if (in_array('file', $outputs, true)) {
            $this->outputToFile($payload);
        }

        if (in_array('webhook', $outputs, true)) {
            $this->outputToWebhook($payload);
        }
    }

    private function outputToConsole(array $payload): void
    {
        $pretty = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        echo "\n\033[34m[ORDER EVENT]\033[0m\n";
        echo $pretty . "\n";
        echo str_repeat('─', 80) . "\n\n";
    }

    private function outputToFile(array $payload): void
    {
        $line = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL;
        $path = $this->config->getFilePath();

        if (file_put_contents($path, $line, FILE_APPEND | LOCK_EX) === false) {
            fwrite(STDERR, "\033[31mFailed to append order to file:\033[0m {$path}\n");
        } else {
            echo "\033[32m→\033[0m Appended to {$path}\n";
        }
    }

    private function outputToWebhook(array $payload): void
    {
        $url = $this->config->getWebhookUrl();
        if ($url === null || $url === '') {
            fwrite(STDERR, "\033[33mWebhook configured but no URL provided.\033[0m\n");
            return;
        }

        try {
            $http = new Client([
                'timeout' => 10,
                'http_errors' => false,
                'headers' => [
                    'User-Agent' => 'OrderGram/1.0',
                ],
            ]);

            $response = $http->post($url, [
                'json' => $payload,
            ]);

            $status = $response->getStatusCode();
            if ($status >= 200 && $status < 300) {
                echo "\033[32m→\033[0m Webhook POST to {$url} succeeded ({$status})\n";
            } else {
                fwrite(STDERR, "\033[31mWebhook failed with status {$status}\033[0m\n");
            }
        } catch (GuzzleException $e) {
            fwrite(STDERR, "\033[31mWebhook request failed:\033[0m " . $e->getMessage() . "\n");
        } catch (Throwable $e) {
            fwrite(STDERR, "\033[31mUnexpected error dispatching webhook:\033[0m " . $e->getMessage() . "\n");
        }
    }

    private function loadLastUpdateId(): int
    {
        $file = $this->config->getLastUpdateFile();

        if (!file_exists($file)) {
            return 0;
        }

        $content = file_get_contents($file);
        if ($content === false) {
            fwrite(STDERR, "\033[33mWarning:\033[0m Could not read last_update_file, starting from offset 0.\n");
            return 0;
        }

        return (int) trim($content);
    }

    private function saveLastUpdateId(int $updateId): void
    {
        if ($updateId <= $this->lastUpdateId) {
            return;
        }

        $this->lastUpdateId = $updateId;
        $file = $this->config->getLastUpdateFile();

        if (file_put_contents($file, (string) $updateId, LOCK_EX) === false) {
            fwrite(STDERR, "\033[31mFailed to persist last_update_id to {$file}\033[0m\n");
        }
    }
}
