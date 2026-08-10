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
        $deviceName = $this->resolveDeviceName($request, $data);
        $browserUserName = $this->resolveBrowserUserName($request, $data);

        $database = app(Database::class);
        $database->transaction(function () use ($submittedByKey, $requiredKeys, $canonicalTexts, $consentVersion, $acceptedAt, $ipAddress, $userAgent, $deviceName, $browserUserName): void {
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
                    $userAgent,
                    $deviceName,
                    $browserUserName
                );

                db('consents')->insert([
                    'consent_key' => (string) $requiredKey,
                    'accepted' => true,
                    'accepted_at' => $acceptedAt,
                    'consent_version' => $consentVersion,
                    'consent_text_snapshot' => $textSnapshot,
                    'ip_address' => $ipAddress,
                    'user_agent' => $userAgent,
                    'device_name' => $deviceName,
                    'browser_user_name' => $browserUserName,
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
        $deviceName = $this->resolveDeviceName($request, $data);
        $browserUserName = $this->resolveBrowserUserName($request, $data);

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
                $userAgent,
                $deviceName,
                $browserUserName
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
                        $userAgent,
                        $deviceName,
                        $browserUserName
                    );

                    $ids[] = db('consents')->insert([
                        'consent_key' => $consentKey,
                        'accepted' => true,
                        'accepted_at' => $acceptedAt,
                        'consent_version' => $consentVersion,
                        'consent_text_snapshot' => $textSnapshot,
                        'ip_address' => $ipAddress,
                        'user_agent' => $userAgent,
                        'device_name' => $deviceName,
                        'browser_user_name' => $browserUserName,
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
        $candidates = [];

        foreach (['cf-connecting-ip', 'true-client-ip', 'x-client-ip', 'x-real-ip', 'remote_addr'] as $header) {
            $value = trim((string) $request->header($header, ''));
            if ($value !== '') {
                $candidates[] = $value;
            }
        }

        $forwardedFor = trim((string) $request->header('x-forwarded-for', ''));
        if ($forwardedFor !== '') {
            foreach (explode(',', $forwardedFor) as $part) {
                $candidate = trim($part);
                if ($candidate !== '') {
                    $candidates[] = $candidate;
                }
            }
        }

        $forwarded = trim((string) $request->header('forwarded', ''));
        if ($forwarded !== '') {
            if (preg_match_all('/for=\"?\[?([^\]\";,]+)\]?\"?/i', $forwarded, $matches)) {
                foreach (($matches[1] ?? []) as $candidate) {
                    $candidate = trim((string) $candidate);
                    if ($candidate !== '') {
                        $candidates[] = $candidate;
                    }
                }
            }
        }

        $bestAny = null;
        foreach ($candidates as $candidate) {
            $normalized = $this->normalizeIp($candidate);
            if ($normalized === null) {
                continue;
            }

            if ($this->isPublicIp($normalized)) {
                return $normalized;
            }

            if ($bestAny === null) {
                $bestAny = $normalized;
            }
        }

        return $bestAny ?? '0.0.0.0';
    }

    private function resolveDeviceName(Request $request, array $data): string
    {
        $fromPayload = trim((string) ($data['device_name'] ?? ''));
        if ($fromPayload !== '') {
            return substr($fromPayload, 0, 255);
        }

        $fromOsAccountPayload = trim((string) ($data['os_account_name'] ?? ''));
        if ($fromOsAccountPayload !== '') {
            return substr($fromOsAccountPayload, 0, 255);
        }

        $fromHeader = trim((string) $request->header('x-device-name', ''));
        if ($fromHeader !== '') {
            return substr($fromHeader, 0, 255);
        }

        $fromOsAccountHeader = trim((string) $request->header('x-os-account-name', ''));
        if ($fromOsAccountHeader !== '') {
            return substr($fromOsAccountHeader, 0, 255);
        }

        $session = $request->session();
        $sessionKey = (string) config('admin.session_key', 'admin_user');
        $adminUser = $session[$sessionKey] ?? null;

        if (is_array($adminUser)) {
            $firstName = trim((string) ($adminUser['first_name'] ?? ''));
            $lastName = trim((string) ($adminUser['last_name'] ?? ''));
            $fullName = trim($firstName . ' ' . $lastName);

            if ($fullName !== '') {
                return substr($fullName, 0, 255);
            }

            $email = trim((string) ($adminUser['email'] ?? ''));
            if ($email !== '') {
                return substr($email, 0, 255);
            }
        }

        $browserUserName = trim((string) ($data['browser_user_name'] ?? ''));
        if ($browserUserName !== '' && strtolower($browserUserName) !== 'unknown') {
            return substr($browserUserName, 0, 255);
        }

        $serverUser = trim((string) ($_SERVER['LOGON_USER'] ?? $_SERVER['REMOTE_USER'] ?? getenv('USERNAME') ?: ''));
        if ($serverUser !== '') {
            return substr($serverUser, 0, 255);
        }

        $platform = trim((string) $request->header('sec-ch-ua-platform', ''));
        if ($platform !== '') {
            $platform = trim($platform, " \t\n\r\0\x0B\"");
            return substr($platform . ' (account-unavailable)', 0, 255);
        }

        return 'unknown-device';
    }

    private function resolveBrowserUserName(Request $request, array $data): string
    {
        $fromPayload = trim((string) ($data['browser_user_name'] ?? ''));
        if ($fromPayload !== '') {
            return substr($fromPayload, 0, 255);
        }

        $fromHeader = trim((string) $request->header('x-browser-user-name', ''));
        if ($fromHeader !== '') {
            return substr($fromHeader, 0, 255);
        }

        $session = $request->session();
        $sessionKey = (string) config('admin.session_key', 'admin_user');
        $adminUser = $session[$sessionKey] ?? null;

        if (is_array($adminUser)) {
            $firstName = trim((string) ($adminUser['first_name'] ?? ''));
            $lastName = trim((string) ($adminUser['last_name'] ?? ''));
            $fullName = trim($firstName . ' ' . $lastName);

            if ($fullName !== '') {
                return substr($fullName, 0, 255);
            }

            $email = trim((string) ($adminUser['email'] ?? ''));
            if ($email !== '') {
                return substr($email, 0, 255);
            }
        }

        return 'unknown';
    }

    private function normalizeIp(string $candidate): ?string
    {
        $candidate = trim($candidate);
        if ($candidate === '') {
            return null;
        }

        if (str_starts_with($candidate, '[') && str_contains($candidate, ']')) {
            $candidate = trim($candidate, '[]');
        }

        if (str_contains($candidate, ':') && substr_count($candidate, ':') === 1 && str_contains($candidate, '.')) {
            $candidate = explode(':', $candidate, 2)[0];
        }

        if (filter_var($candidate, FILTER_VALIDATE_IP) === false) {
            return null;
        }

        return substr($candidate, 0, 45);
    }

    private function isPublicIp(string $ip): bool
    {
        return filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false;
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
        string $userAgent,
        string $deviceName,
        string $browserUserName
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
            $deviceName,
            $browserUserName,
        ]);

        return hash_hmac('sha256', $payload, $secret);
    }
}
