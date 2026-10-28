<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\PurchaseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A purchase of premium coins with real money: what was bought, for how much, and the reference of the payment at the provider. It is kept when the account
 * is erased (the shop has to keep its books), with the account cut off.
 */
class Purchase extends Model
{
    /** @use HasFactory<PurchaseFactory> */
    use HasFactory;

    public const STATUS_PAID = 'paid';

    public const STATUS_REFUNDED = 'refunded';

    protected $guarded = [];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['coins' => 'integer', 'amount_minor' => 'integer', 'refunded_at' => 'datetime'];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
