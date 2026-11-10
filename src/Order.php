<?php

declare(strict_types=1);

namespace OrderGram;

/**
 * Immutable DTO representing a parsed and validated order event.
 *
 * All properties are readonly. Use fromParsed() for construction from
 * parser output. toArray() produces a consistent payload for outputs.
 */
final class Order
{
    /**
     * @param string $id Unique order identifier
     * @param float $amount Monetary amount (validated >= 0)
     * @param string $currency Three-letter currency code (normalized uppercase)
     * @param string $customer Customer name or identifier
     * @param array<string,mixed> $meta Additional context (raw_text, items, timestamps, etc.)
     */
    public function __construct(
        private readonly string $id,
        private readonly float $amount,
        private readonly string $currency,
        private readonly string $customer,
        private readonly array $meta = []
    ) {
        if (trim($id) === '') {
            throw new \InvalidArgumentException('Order ID cannot be empty');
        }

        if ($amount < 0.0) {
            throw new \InvalidArgumentException('Order amount cannot be negative');
        }

        if (trim($currency) === '') {
            throw new \InvalidArgumentException('Currency code cannot be empty');
        }

        if (trim($customer) === '') {
            throw new \InvalidArgumentException('Customer identifier cannot be empty');
        }
    }

    /**
     * Factory method used by OrderParser.
     *
     * Normalizes input, supplies defaults, and constructs a valid Order.
     * Any validation errors are thrown and caught by the parser.
     *
     * @param array<string,mixed> $data Data extracted by parser (id, amount, currency, customer, meta)
     */
    public static function fromParsed(array $data): self
    {
        $id = trim($data['id'] ?? '');
        if ($id === '') {
            $id = 'ORD-' . strtoupper(substr(uniqid('', true), -8));
        }

        $amount = (float) ($data['amount'] ?? 0.0);
        $currency = strtoupper(trim($data['currency'] ?? 'USD'));
        $customer = trim($data['customer'] ?? 'Unknown');
        $meta = is_array($data['meta'] ?? null) ? $data['meta'] : [];

        return new self($id, $amount, $currency, $customer, $meta);
    }

    /**
     * Returns a normalized array representation suitable for JSON logging,
     * file output, or webhook payloads.
     *
     * @return array<string,mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'amount' => $this->amount,
            'currency' => $this->currency,
            'customer' => $this->customer,
            'meta' => $this->meta,
            'processed_at' => (new \DateTimeImmutable())->format('c'),
        ];
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getAmount(): float
    {
        return $this->amount;
    }

    public function getCurrency(): string
    {
        return $this->currency;
    }

    public function getCustomer(): string
    {
        return $this->customer;
    }

    /**
     * @return array<string,mixed>
     */
    public function getMeta(): array
    {
        return $this->meta;
    }
}
