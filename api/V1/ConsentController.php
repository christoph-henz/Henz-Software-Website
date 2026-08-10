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
    public function storeWebsite(Request $request): Response
    {
        $data = $request->all();
        $consentVersion = trim((string) ($data['consent_version'] ?? 'site-1.0'));
        $redirectTo = $this->normalizeLocalRedirectTarget((string) ($data['redirect_to'] ?? '/'));

        $consentConfig = config('consents');
        $requiredKeys = (array) ($consentConfig['site_required_keys'] ?? ['essential_cookies']);
        $canonicalTexts = (array) (($consentConfig['site_versions'] ?? [])[$consentVersion] ?? []);

        $errors = [];

        if ($consentVersion === '') {
            $errors['consent_version'][] = 'required';
        } elseif ($canonicalTexts === []) {
            $errors['consent_version'][] = 'unsupported_version';
        }

        $consents = $data['consents'] ?? null;
        if (!is_array($consents) || $consents === []) {
            $errors['consents'][] = 'required';
        }

        if ($errors !== []) {
            return Response::redirect($redirectTo . (str_contains($redirectTo, '?') ? '&' : '?') . 'cookie_consent=error', 303);
        }

        $submittedByKey = [];
        foreach ($consents as $consent) {
            if (!is_array($consent)) {
                continue;
            }

            $key = trim((string) ($consent['consent_key'] ?? ''));
            if ($key !== '') {
                $submittedByKey[$key] = $consent;
            }
        }

        foreach ($requiredKeys as $requiredKey) {
            if (!isset($submittedByKey[$requiredKey])) {
                $errors['consents.' . $requiredKey][] = 'required';
                continue;
            }

            $consent = $submittedByKey[$requiredKey];
            $accepted = $this->parseBooleanInput($consent['accepted'] ?? null) === true;
            $textSnapshot = trim((string) ($consent['consent_text_snapshot'] ?? ''));

            if (!$accepted) {
                $errors['consents.' . $requiredKey . '.accepted'][] = 'required_true';
            }

            $canonicalText = (string) ($canonicalTexts[$requiredKey] ?? '');
            if ($textSnapshot === '') {
                $errors['consents.' . $requiredKey . '.consent_text_snapshot'][] = 'required';
            } elseif ($canonicalText !== '' && $textSnapshot !== $canonicalText) {
                $errors['consents.' . $requiredKey . '.consent_text_snapshot'][] = 'text_mismatch';
            }
        }

        if ($errors !== []) {
            return Response::redirect($redirectTo . (str_contains($redirectTo, '?') ? '&' : '?') . 'cookie_consent=error', 303);
        }

        $acceptedAt = date('Y-m-d H:i:s');
        $ipAddress = $this->resolveIpAddress($request);
        $userAgent = trim((string) $request->header('user-agent', 'unknown'));

        $database = app(Database::class);
        $database->transaction(function () use ($submittedByKey, $requiredKeys, $canonicalTexts, $consentVersion, $acceptedAt, $ipAddress, $userAgent): void {
            foreach ($requiredKeys as $requiredKey) {
                $consent = (array) ($submittedByKey[$requiredKey] ?? []);
                $textSnapshot = trim((string) ($consent['consent_text_snapshot'] ?? ''));
                if ($textSnapshot === '') {
                    $textSnapshot = (string) ($canonicalTexts[$requiredKey] ?? '');
                }

                $signatureHash = $this->buildSignatureHash(
                    0,
                    (string) $requiredKey,
                    true,
                    $acceptedAt,
                    $consentVersion,
                    $textSnapshot,
                    $ipAddress,
                    $userAgent
                );

                db('consents')->insert([
                    'consent_key' => (string) $requiredKey,
                    'accepted' => true,
                    'accepted_at' => $acceptedAt,
                    'consent_version' => $consentVersion,
                    'consent_text_snapshot' => $textSnapshot,
                    'ip_address' => $ipAddress,
                    'user_agent' => $userAgent,
                    'signature_hash' => $signatureHash,
                ]);
            }
        });

        $response = Response::redirect($redirectTo, 303);
        $cookie = sprintf(
            'hs_essential_cookies=accepted; Path=/; Max-Age=%d; SameSite=Lax%s',
            60 * 60 * 24 * 365,
            $this->isHttpsRequest($request) ? '; Secure' : ''
        );

        return $response->withHeader('Set-Cookie', $cookie);
    }

    public function store(Request $request): Response
    {
        $data = $request->all();

        $consentVersion = trim((string) ($data['consent_version'] ?? ''));
        $consents = $data['consents'] ?? null;

        $errors = [];

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
                        0,
                        $consentKey,
                        true,
                        $acceptedAt,
                        $consentVersion,
                        $textSnapshot,
                        $ipAddress,
                        $userAgent
                    );

                    $ids[] = db('consents')->insert([
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

    private function normalizeLocalRedirectTarget(string $target): string
    {
        $target = trim($target);
        if ($target === '' || !str_starts_with($target, '/')) {
            return '/';
        }

        return $target;
    }

    private function parseBooleanInput(mixed $value): ?bool
    {
        if (is_bool($value)) {
            return $value;
        }

        if (is_int($value)) {
            if ($value === 1) {
                return true;
            }

            if ($value === 0) {
                return false;
            }

            return null;
        }

        if (is_string($value)) {
            $normalized = strtolower(trim($value));
            if (in_array($normalized, ['1', 'true', 'yes', 'on'], true)) {
                return true;
            }

            if (in_array($normalized, ['0', 'false', 'no', 'off'], true)) {
                return false;
            }
        }

        return null;
    }

    private function isHttpsRequest(Request $request): bool
    {
        $https = strtolower(trim((string) $request->header('x-forwarded-proto', '')));
        if ($https === 'https') {
            return true;
        }

        $forwarded = strtolower(trim((string) $request->header('forwarded', '')));
        if ($forwarded !== '' && str_contains($forwarded, 'proto=https')) {
            return true;
        }

        $scheme = (string) $request->header('x-scheme', '');
        if ($scheme === '') {
            $scheme = (string) ($_SERVER['REQUEST_SCHEME'] ?? '');
        }

        return strtolower(trim($scheme)) === 'https';
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
