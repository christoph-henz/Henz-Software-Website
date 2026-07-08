<?php

declare(strict_types=1);

namespace App\Controllers\Api\V1;

use App\Controllers\Api\BaseApiController;
use App\Core\Database\Database;
use App\Core\Http\Request;
use App\Core\Http\Response;
use Throwable;

final class ConsentController extends BaseApiController
{
    public function store(Request $request): Response
    {
        $data = $request->all();

        $bookingId = (int) ($data['booking_id'] ?? 0);
        $requestId = (int) ($data['client_request_id'] ?? 0);
        $consentVersion = trim((string) ($data['consent_version'] ?? ''));
        $consents = $data['consents'] ?? null;

        $errors = [];

        if ($bookingId <= 0 && $requestId <= 0) {
            $errors['booking_id'][] = 'required_without_client_request_id';
            $errors['client_request_id'][] = 'required_without_booking_id';
        }

        if ($consentVersion === '') {
            $errors['consent_version'][] = 'required';
        }

        if (!is_array($consents) || $consents === []) {
            $errors['consents'][] = 'required';
        }

        $consentConfig = config('consents');
        $canonicalTexts = (array) (($consentConfig['versions'] ?? [])[$consentVersion] ?? []);
        if ($consentVersion !== '' && $canonicalTexts === []) {
            $errors['consent_version'][] = 'unsupported_version';
        }

        if ($errors !== []) {
            return $this->fail('Validation failed', 422, $errors);
        }

        if ($bookingId > 0) {
            $bookingExists = db('bookings')
                ->where('id', $bookingId)
                ->select(['id'])
                ->first();

            if ($bookingExists === null) {
                return $this->fail('Validation failed', 422, [
                    'booking_id' => ['not_found'],
                ]);
            }
        }

        if ($requestId > 0) {
            $requestExists = db('client_requests')
                ->where('id', $requestId)
                ->select(['id'])
                ->first();

            if ($requestExists === null) {
                return $this->fail('Validation failed', 422, [
                    'client_request_id' => ['not_found'],
                ]);
            }
        }

        $ipAddress = $this->resolveIpAddress($request);
        $userAgent = trim((string) $request->header('user-agent', 'unknown'));

        $preparedConsents = [];

        try {
            foreach ($consents as $index => $consent) {
                if (!is_array($consent)) {
                    $errors['consents.' . $index][] = 'invalid_item';
                    continue;
                }

                $consentKey = trim((string) ($consent['consent_key'] ?? ''));
                $accepted = ($consent['accepted'] ?? false) === true;
                $textSnapshot = trim((string) ($consent['consent_text_snapshot'] ?? ''));
                $acceptedAtRaw = trim((string) ($consent['accepted_at'] ?? ''));

                if ($consentKey === '') {
                    $errors['consents.' . $index . '.consent_key'][] = 'required';
                }

                $canonicalText = (string) ($canonicalTexts[$consentKey] ?? '');
                if ($consentKey !== '' && $canonicalText === '') {
                    $errors['consents.' . $index . '.consent_key'][] = 'invalid_consent_key';
                }

                if (!$accepted) {
                    $errors['consents.' . $index . '.accepted'][] = 'required_true';
                }

                if ($textSnapshot === '') {
                    $errors['consents.' . $index . '.consent_text_snapshot'][] = 'required';
                } elseif ($canonicalText !== '' && $textSnapshot !== $canonicalText) {
                    $errors['consents.' . $index . '.consent_text_snapshot'][] = 'text_mismatch';
                }

                $acceptedAt = date('Y-m-d H:i:s');
                if ($acceptedAtRaw !== '') {
                    $timestamp = strtotime($acceptedAtRaw);
                    if ($timestamp === false) {
                        $errors['consents.' . $index . '.accepted_at'][] = 'invalid_datetime';
                    } else {
                        $acceptedAt = date('Y-m-d H:i:s', $timestamp);
                    }
                }

                if ($errors !== []) {
                    continue;
                }

                $preparedConsents[] = [
                    'consent_key' => $consentKey,
                    'accepted_at' => $acceptedAt,
                    'consent_text_snapshot' => $textSnapshot,
                ];
            }

            if ($errors !== []) {
                return $this->fail('Validation failed', 422, $errors);
            }

            $database = app(Database::class);
            $insertedConsentIds = $database->transaction(function () use (
                $preparedConsents,
                $bookingId,
                $requestId,
                $consentVersion,
                $ipAddress,
                $userAgent
            ) {
                $ids = [];

                foreach ($preparedConsents as $prepared) {
                    $consentKey = (string) $prepared['consent_key'];
                    $acceptedAt = (string) $prepared['accepted_at'];
                    $textSnapshot = (string) $prepared['consent_text_snapshot'];

                    $signatureHash = $this->buildSignatureHash(
                        $requestId > 0 ? $requestId : $bookingId,
                        $consentKey,
                        true,
                        $acceptedAt,
                        $consentVersion,
                        $textSnapshot,
                        $ipAddress,
                        $userAgent
                    );

                    $ids[] = db('consents')->insert([
                        'client_request_id' => $requestId > 0 ? $requestId : null,
                        'booking_id' => $bookingId > 0 ? $bookingId : null,
                        'consent_key' => $consentKey,
                        'accepted' => true,
                        'accepted_at' => $acceptedAt,
                        'consent_version' => $consentVersion,
                        'consent_text_snapshot' => $textSnapshot,
                        'ip_address' => $ipAddress,
                        'user_agent' => $userAgent,
                        'signature_hash' => $signatureHash,
                    ]);
                }

                return $ids;
            });

            return $this->ok([
                'booking_id' => $bookingId,
                'client_request_id' => $requestId > 0 ? $requestId : null,
                'consent_ids' => $insertedConsentIds,
                'count' => count($insertedConsentIds),
                'message' => 'Consents wurden gespeichert.',
            ], 201);
        } catch (Throwable) {
            return $this->fail('Consents konnten nicht gespeichert werden.', 500);
        }
    }

    private function resolveIpAddress(Request $request): string
    {
        $forwardedFor = trim((string) $request->header('x-forwarded-for', ''));
        if ($forwardedFor !== '') {
            $parts = explode(',', $forwardedFor);
            $candidate = trim((string) ($parts[0] ?? ''));
            if ($candidate !== '') {
                return substr($candidate, 0, 45);
            }
        }

        $realIp = trim((string) $request->header('x-real-ip', ''));
        if ($realIp !== '') {
            return substr($realIp, 0, 45);
        }

        return '0.0.0.0';
    }

    private function buildSignatureHash(
        int $contextId,
        string $consentKey,
        bool $accepted,
        string $acceptedAt,
        string $consentVersion,
        string $consentTextSnapshot,
        string $ipAddress,
        string $userAgent
    ): string {
        $secret = (string) env('CONSENT_HMAC_KEY', 'local-dev-consent-key');

        $payload = implode('|', [
            (string) $contextId,
            $consentKey,
            $accepted ? '1' : '0',
            $acceptedAt,
            $consentVersion,
            $consentTextSnapshot,
            $ipAddress,
            $userAgent,
        ]);

        return hash_hmac('sha256', $payload, $secret);
    }
}
