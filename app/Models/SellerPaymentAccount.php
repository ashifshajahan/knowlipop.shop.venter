<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SellerPaymentAccount extends Model
{
    protected $fillable = ['seller_id', 'provider', 'provider_account_id', 'onboarding_status', 'kyc_status', 'payout_enabled'];

    protected function casts(): array
    {
        return ['payout_enabled' => 'boolean'];
    }

    public function seller(): BelongsTo
    {
        return $this->belongsTo(User::class, 'seller_id');
    }
}
