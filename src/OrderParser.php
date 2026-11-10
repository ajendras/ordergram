<?php

declare(strict_types=1);

namespace OrderGram;

/**
 * Regex-driven parser that converts raw Telegram messages into Order DTOs.
 *
 * Uses the configurable patterns from config.json to detect ORDER_PLACED
 * signals and extract order_id, amount, currency, customer and items.
 * Returns null for any message that does not match the detection pattern.
 */
final class OrderParser
{
    /**
     * @param array<string,string> $patterns Regex patterns (detect, order_id, amount, ...)
     */
    public function __construct(
        private readonly array $patterns
    ) {
        if (!isset($patterns['detect']) || !is_string($patterns['detect'])) {
            throw new \InvalidArgumentException("Parser requires a 'detect' regex pattern");
        }
    }

    /**
     * Attempt to parse a message into a structured Order.
     *
     * @param string $text Raw text from a Telegram message
     */
    public function parse(string $text): ?Order
    {
        $text = trim($text);
        if ($text === '') {
            return null;
        }

        // Fast-path: only continue if we detect an order signal
        if (!preg_match($this->patterns['detect'], $text)) {
            return null;
        }

        $extracted = [
            'raw_text' => $text,
            'detected_at' => date('c'),
        ];

        // Extract each configured field
        $fieldMap = [
            'order_id' => 'order_id',
            'amount'   => 'amount',
            'currency' => 'currency',
            'customer' => 'customer',
            'items'    => 'items',
        ];

        foreach ($fieldMap as $patternKey => $dataKey) {
            if (!isset($this->patterns[$patternKey])) {
                continue;
            }

            if (preg_match($this->patterns[$patternKey], $text, $matches)) {
                $value = trim($matches[1] ?? $matches[0]);

                if ($patternKey === 'amount') {
                    // Strip currency symbols and convert to float
                    $value = (float) preg_replace('/[^0-9.]/', '', $value);
                    $extracted['amount'] = $value;
                } else {
                    $extracted[$dataKey] = $value;
                }
            }
        }

        // Apply sensible defaults for missing data
        $orderData = [
            'id'       => $extracted['order_id'] ?? 'ORD-' . strtoupper(substr(uniqid(), -8)),
            'amount'   => (float) ($extracted['amount'] ?? 0.0),
            'currency' => strtoupper($extracted['currency'] ?? 'USD'),
            'customer' => $extracted['customer'] ?? 'Unknown',
            'meta'     => [],
        ];

        // Move non-core fields into meta
        if (isset($extracted['items'])) {
            $orderData['meta']['items'] = $extracted['items'];
        }
        $orderData['meta']['raw_text'] = $extracted['raw_text'];
        $orderData['meta']['detected_at'] = $extracted['detected_at'];

        // Let the Order DTO perform final validation/normalization
        try {
            return Order::fromParsed($orderData);
        } catch (\Throwable $e) {
            // Never let a single malformed order crash the entire scraper.
            // In production a PSR-3 logger would be used here.
            error_log('OrderParser: Failed to instantiate Order - ' . $e->getMessage());
            return null;
        }
    }
}
