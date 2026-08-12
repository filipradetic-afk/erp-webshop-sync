<?php

declare(strict_types=1);

namespace ErpSync;

/**
 * Validates one raw CSV row from the ERP export before it is allowed anywhere
 * near the webshop's product table. A malformed feed should never reach the
 * database; it should be rejected, reported, and left for a human to fix at
 * the source, without aborting the rest of the batch.
 */
final class FeedValidator
{
    private const SKU_PATTERN = '/^[A-Z0-9][A-Z0-9\-]{2,19}$/';
    private const ALLOWED_STATUS = ['active', 'inactive'];
    private const MAX_PRICE = 100000.0;
    private const MAX_NAME_LENGTH = 200;

    /**
     * @param array<string,string> $row Associative CSV row (header => value).
     * @throws ValidationException When any field fails validation.
     */
    public function validate(array $row): Product
    {
        $sku = trim($row['sku'] ?? '');
        if ($sku === '' || !preg_match(self::SKU_PATTERN, $sku)) {
            throw new ValidationException("invalid or missing sku: '{$sku}'");
        }

        $name = trim($row['name'] ?? '');
        if ($name === '' || mb_strlen($name) > self::MAX_NAME_LENGTH) {
            throw new ValidationException("{$sku}: invalid or missing name");
        }

        $priceRaw = trim((string) ($row['price_net'] ?? ''));
        if ($priceRaw === '' || !is_numeric($priceRaw)) {
            throw new ValidationException("{$sku}: price_net is not numeric: '{$priceRaw}'");
        }
        $price = (float) $priceRaw;
        if ($price <= 0.0 || $price > self::MAX_PRICE) {
            throw new ValidationException("{$sku}: price_net out of range: {$price}");
        }

        $stockRaw = trim((string) ($row['stock_qty'] ?? ''));
        if (!preg_match('/^\d+$/', $stockRaw)) {
            throw new ValidationException("{$sku}: stock_qty must be a non-negative integer: '{$stockRaw}'");
        }
        $stock = (int) $stockRaw;

        $status = trim($row['status'] ?? '');
        if (!in_array($status, self::ALLOWED_STATUS, true)) {
            throw new ValidationException(
                "{$sku}: status must be one of " . implode('/', self::ALLOWED_STATUS) . ", got '{$status}'"
            );
        }

        $updatedAtRaw = trim($row['updated_at'] ?? '');
        try {
            $updatedAt = new \DateTimeImmutable($updatedAtRaw);
        } catch (\Exception) {
            throw new ValidationException("{$sku}: updated_at is not a valid date: '{$updatedAtRaw}'");
        }

        return new Product($sku, $name, $price, $stock, $status, $updatedAt->format(DATE_ATOM));
    }
}
