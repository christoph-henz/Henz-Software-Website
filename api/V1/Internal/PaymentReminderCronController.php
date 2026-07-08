<?php

declare(strict_types=1);

namespace App\Controllers\Api\V1\Internal;

use App\Controllers\Api\BaseApiController;
use App\Core\Http\Request;
use App\Core\Http\Response;
use App\Services\PaymentReminderScheduler;
use DateTimeImmutable;

final class PaymentReminderCronController extends BaseApiController
{
    /**
     * POST /v1/internal/payment-reminders
     */
    public function run(Request $request): Response
    {
        $configuredSecret = trim((string) env('CRON_SECRET', ''));

        $header = trim((string) $request->header('Authorization', ''));
        $token = $this->extractBearerToken($header);
        if ($token === '' || $configuredSecret === '' || !hash_equals($configuredSecret, $token)) {
            return $this->fail('Forbidden', 403, [
                'authorization' => ['invalid_token'],
            ]);
        }

        $emailAutomationEnabled = filter_var((string) config('mail.automation.enabled', false), FILTER_VALIDATE_BOOL) === true;
        $paymentAutomationEnabled = filter_var((string) config('mail.payment.automation_enabled', false), FILTER_VALIDATE_BOOL) === true;

        if (!$emailAutomationEnabled || !$paymentAutomationEnabled) {
            return $this->ok([
                'processed' => 0,
                'sent' => 0,
                'cancelled' => 0,
                'failed' => 0,
                'skipped' => 0,
                'details' => [],
                'automation' => [
                    'email_enabled' => $emailAutomationEnabled,
                    'payment_enabled' => $paymentAutomationEnabled,
                ],
                'message' => 'Reminder processing skipped because automation flags are disabled.',
            ]);
        }

        $now = new DateTimeImmutable('now');
        $result = app(PaymentReminderScheduler::class)->run($now);

        return $this->ok([
            'processed' => (int) ($result['processed'] ?? 0),
            'sent' => (int) ($result['sent'] ?? 0),
            'cancelled' => (int) ($result['cancelled'] ?? 0),
            'failed' => (int) ($result['failed'] ?? 0),
            'skipped' => (int) ($result['skipped'] ?? 0),
            'details' => is_array($result['details'] ?? null) ? $result['details'] : [],
            'processed_at' => $now->format('Y-m-d H:i:s'),
            'timezone' => date_default_timezone_get(),
        ]);
    }

    private function extractBearerToken(string $authorizationHeader): string
    {
        if ($authorizationHeader === '') {
            return '';
        }

        if (!preg_match('/^Bearer\s+(.+)$/i', $authorizationHeader, $matches)) {
            return '';
        }

        return trim((string) ($matches[1] ?? ''));
    }
}
