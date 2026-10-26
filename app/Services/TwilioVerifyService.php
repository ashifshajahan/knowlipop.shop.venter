<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use RuntimeException;

class TwilioVerifyService
{
    private function configValues(): array
    {
        $sid = config('services.twilio.account_sid');
        $token = config('services.twilio.auth_token');
        $verifySid = config('services.twilio.verify_service_sid');

        if (! $sid || ! $token || ! $verifySid) {
            throw new RuntimeException('Twilio Verify is not configured. Set the TWILIO_* values in .env.');
        }

        return [$sid, $token, $verifySid];
    }

    public function sendCode(string $phone): void
    {
        [$sid, $token, $verifySid] = $this->configValues();

        $response = Http::asForm()
            ->withBasicAuth($sid, $token)
            ->timeout(15)
            ->post("https://verify.twilio.com/v2/Services/{$verifySid}/Verifications", [
                'To' => $phone,
                'Channel' => 'sms',
            ]);

        if (! $response->successful()) {
            report(new RuntimeException('Twilio SMS send failed: '.$response->status().' '.$response->body()));
            throw new RuntimeException('Unable to send SMS OTP right now.');
        }
    }

    public function verifyCode(string $phone, string $code): bool
    {
        [$sid, $token, $verifySid] = $this->configValues();

        $response = Http::asForm()
            ->withBasicAuth($sid, $token)
            ->timeout(15)
            ->post("https://verify.twilio.com/v2/Services/{$verifySid}/VerificationCheck", [
                'To' => $phone,
                'Code' => $code,
            ]);

        if (! $response->successful()) {
            return false;
        }

        return $response->json('status') === 'approved';
    }
}
