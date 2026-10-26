<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\SellerPaymentAccount;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SellerPaymentController extends Controller
{
    /**
     * Records a provider-issued linked-account reference only.
     * This endpoint does not create a Razorpay account or verify KYC.
     */
    public function saveOnboardingReference(Request $request): JsonResponse
    {
        $data = $request->validate([
            'provider_account_id' => ['required', 'string', 'max:120'],
        ]);

        $account = SellerPaymentAccount::updateOrCreate(
            ['seller_id' => $request->user()->id],
            [
                'provider' => 'razorpay_route',
                'provider_account_id' => $data['provider_account_id'],
                'onboarding_status' => 'pending_verification',
                'kyc_status' => 'pending',
                'payout_enabled' => false,
            ]
        );

        return response()->json([
            'message' => 'Provider reference saved. This does not confirm Razorpay onboarding or KYC.',
            'account' => $account,
        ], 202);
    }

    public function status(Request $request): JsonResponse
    {
        $account = SellerPaymentAccount::where('seller_id', $request->user()->id)->first();

        return response()->json([
            'account' => $account,
            'note' => 'Status must be updated only from verified Razorpay API/webhook events.',
        ]);
    }

    public function payouts(Request $request): JsonResponse
    {
        return response()->json([
            'payouts' => [],
            'message' => 'Payout listing is not connected to Razorpay yet.',
            'integration_status' => 'not_configured',
        ], 501);
    }

    public function commissionPreview(Request $request): JsonResponse
    {
        $data = $request->validate([
            'eligible_order_value_minor' => ['required', 'integer', 'min:0', 'max:999999999999'],
            'currency' => ['sometimes', 'string', 'size:3'],
        ]);

        $rate = config('services.marketplace.commission_rate');
        if ($rate === null || ! is_numeric($rate) || $rate < 0 || $rate > 100) {
            return response()->json([
                'message' => 'Marketplace commission rate has not been approved/configured.',
            ], 503);
        }

        $basis = (int) $data['eligible_order_value_minor'];
        $commission = (int) round($basis * ((float) $rate / 100), 0, PHP_ROUND_HALF_UP);

        return response()->json([
            'currency' => strtoupper($data['currency'] ?? 'INR'),
            'eligible_order_value_minor' => $basis,
            'commission_rate_percent' => (float) $rate,
            'platform_commission_minor' => $commission,
            'seller_share_minor' => $basis - $commission,
            'note' => 'Preview only; not an order, payment, or settlement.',
        ]);
    }
}
