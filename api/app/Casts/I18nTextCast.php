<?php

declare(strict_types=1);

namespace App\Casts;

use App\Support\I18nText;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

/**
 * Kolumna JSON z tłumaczeniami jako obiekt I18nText.
 *
 * @implements CastsAttributes<I18nText, I18nText|array<string, string>|string|null>
 */
final class I18nTextCast implements CastsAttributes
{
    public function get(Model $model, string $key, mixed $value, array $attributes): I18nText
    {
        if ($value === null) {
            return I18nText::from(null);
        }

        $decoded = json_decode((string) $value, true, flags: JSON_THROW_ON_ERROR);

        return I18nText::from(is_array($decoded) ? $decoded : null);
    }

    public function set(Model $model, string $key, mixed $value, array $attributes): string
    {
        $text = match (true) {
            $value instanceof I18nText => $value,
            is_array($value), is_string($value), $value === null => I18nText::from($value),
            default => throw new InvalidArgumentException("Nieobsługiwana wartość dla {$key}."),
        };

        return json_encode($text, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }
}
