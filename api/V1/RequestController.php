<?php

declare(strict_types=1);

namespace App\Controllers\Api\V1;

use App\Controllers\Api\BaseApiController;
use App\Core\Database\ConnectionManager;
use App\Core\Database\Database;
use App\Core\Http\Request;
use App\Core\Http\Response;
use App\Core\Logging\Logger;
use App\Services\ClientFieldEncryptionService;
use App\Services\EmailAutomationService;
use App\Services\PackageBookingManager;
use DateTimeZone;
use DateTimeImmutable;
use Throwable;

final class RequestController extends BaseApiController
{
    private const DEFAULT_CLIENT_TIMEZONE = 'Europe/Berlin';
    private ?bool $clientTimezoneColumnAvailable = null;

    public function store(Request $request): Response
    {
        $rawData = $request->all();
        $honeypot = trim((string) ($rawData['company'] ?? ''));
        if ($honeypot !== '') {
            return $this->fail('Validation failed', 422, [
                'request' => ['bot_detected'],
            ]);
        }

        $minFillSeconds = $this->readIntSetting('booking_min_fill_seconds', 5);
        $formStartedAt = (int) ($rawData['form_started_at'] ?? 0);
        $submittedAt = time();
        $fillDurationSeconds = $submittedAt - $formStartedAt;

        if ($formStartedAt <= 0 || $formStartedAt > $submittedAt || $fillDurationSeconds < $minFillSeconds) {
            return $this->fail('Validation failed', 422, [
                'request' => ['too_fast_submit'],
            ]);
        }

        $data = $this->validate($request, [
            'firstname' => 'required',
            'lastname' => 'required',
            'dob' => 'required',
            'email' => 'required|email',
            'phone' => 'required',
            'service' => 'required',
            'termin' => 'required',
            'consents' => 'required',
        ]);

        $firstName = trim((string) ($data['firstname'] ?? ''));
        $lastName = trim((string) ($data['lastname'] ?? ''));
        $email = strtolower(trim((string) ($data['email'] ?? '')));
        $phone = trim((string) ($data['phone'] ?? ''));
        $dateOfBirthRaw = trim((string) ($data['dob'] ?? $data['date_of_birth'] ?? ''));
        $dateOfBirth = $this->normalizeDateToYmd($dateOfBirthRaw);
        $gender = trim((string) ($data['gender'] ?? ''));
        $title = trim((string) ($data['title'] ?? ''));
        $street = trim((string) ($data['street'] ?? $data['address'] ?? ''));
        $postalCode = trim((string) ($data['postal_code'] ?? $data['zip_code'] ?? ''));
        $city = trim((string) ($data['city'] ?? ''));
        $country = trim((string) ($data['country'] ?? ''));
        $medicalNotes = trim((string) ($data['medical_notes'] ?? ''));
        $dementiaSurname = trim((string) ($data['dementia_person_surname'] ?? ''));
        $dementiaName = trim((string) ($data['dementia_person_name'] ?? ''));
        $dementiaDob = $this->normalizeDateToYmd(trim((string) ($data['dementia_person_date_of_birth'] ?? '')));
        $dementiaDod = $this->normalizeDateToYmd(trim((string) ($data['dementia_person_date_of_death'] ?? '')));
        $contactPreference = trim((string) ($data['contact_preference'] ?? 'email'));
        $language = trim((string) ($data['language'] ?? 'de'));
        $serviceSlug = trim((string) ($data['service'] ?? ''));
        $termin = trim((string) ($data['termin'] ?? ''));
        $freeMessage = trim((string) ($data['message'] ?? ''));
        $consents = $data['consents'] ?? null;
        $consentVersion = trim((string) ($data['consent_version'] ?? '1.0'));

        if (!is_array($consents) || $consents === []) {
            return $this->fail('Validation failed', 422, [
                'consents' => ['required'],
            ]);
        }

        if ($dateOfBirth === null) {
            return $this->fail('Validation failed', 422, [
                'dob' => ['invalid_date'],
            ]);
        }

        $consentConfig = config('consents');
        $requiredKeys = (array) ($consentConfig['required_keys'] ?? []);
        $canonicalTexts = (array) (($consentConfig['versions'] ?? [])[$consentVersion] ?? []);

        if ($canonicalTexts === []) {
            return $this->fail('Validation failed', 422, [
                'consent_version' => ['unsupported_version'],
            ]);
        }

        // Index submitted consents by key for easy lookup
        $submittedByKey = [];
        foreach ($consents as $consent) {
            if (is_array($consent)) {
                $key = trim((string) ($consent['consent_key'] ?? ''));
                if ($key !== '') {
                    $submittedByKey[$key] = $consent;
                }
            }
        }

        $consentErrors = [];

        // Check all required keys are present and accepted
        foreach ($requiredKeys as $requiredKey) {
            if (!isset($submittedByKey[$requiredKey])) {
                $consentErrors['consents.' . $requiredKey][] = 'required';
                continue;
            }

            $consent = $submittedByKey[$requiredKey];
            $accepted = ($consent['accepted'] ?? false) === true;
            $textSnapshot = trim((string) ($consent['consent_text_snapshot'] ?? ''));
            $canonicalText = (string) ($canonicalTexts[$requiredKey] ?? '');

            if (!$accepted) {
                $consentErrors['consents.' . $requiredKey . '.accepted'][] = 'required_true';
            }

            if ($textSnapshot === '') {
                $consentErrors['consents.' . $requiredKey . '.consent_text_snapshot'][] = 'required';
            } elseif ($textSnapshot !== $canonicalText) {
                $consentErrors['consents.' . $requiredKey . '.consent_text_snapshot'][] = 'text_mismatch';
            }
        }

        if ($consentErrors !== []) {
            return $this->fail('Validation failed', 422, $consentErrors);
        }

        $terminTimestamp = strtotime($termin);
        if ($terminTimestamp === false) {
            return $this->fail('Validation failed', 422, [
                'termin' => ['invalid_datetime'],
            ]);
        }

        if ($terminTimestamp < time()) {
            return $this->fail('Validation failed', 422, [
                'termin' => ['in_past'],
            ]);
        }

        $minHoursNotice = $this->readIntAvailabilityRule('booking_min_hours_notice', 24);
        $advanceDays = $this->readIntAvailabilityRule('booking_advance_days', 60);

        $now = time();
        $minAllowedTimestamp = $now + ($minHoursNotice * 3600);
        $maxAllowedTimestamp = $now + ($advanceDays * 86400);

        if ($terminTimestamp < $minAllowedTimestamp) {
            return $this->fail('Validation failed', 422, [
                'termin' => ['min_notice'],
            ]);
        }

        if ($terminTimestamp > $maxAllowedTimestamp) {
            return $this->fail('Validation failed', 422, [
                'termin' => ['max_advance'],
            ]);
        }

        $slotStepMinutes = $this->readIntSetting('booking_slot_interval_minutes', 30);
        if ($slotStepMinutes < 5) {
            $slotStepMinutes = 30;
        }

        $minutesSinceStartOfDay = ((int) date('G', $terminTimestamp) * 60) + (int) date('i', $terminTimestamp);
        if (($minutesSinceStartOfDay % $slotStepMinutes) !== 0) {
            return $this->fail('Validation failed', 422, [
                'termin' => ['invalid_slot_interval'],
            ]);
        }

        $scheduledAt = date('Y-m-d H:i:s', $terminTimestamp);

        $serviceRow = db('services')
            ->where('slug', $serviceSlug)
            ->select(['id', 'is_active', 'duration_minutes'])
            ->first();

        if ($serviceRow === null) {
            return $this->fail('Validation failed', 422, [
                'service' => ['invalid_service'],
            ]);
        }

        $serviceId = (int) ($serviceRow['id'] ?? 0);
        $serviceIsActive = (bool) ($serviceRow['is_active'] ?? false);
        $serviceDurationMinutes = (int) ($serviceRow['duration_minutes'] ?? 0);

        if ($serviceId <= 0) {
            return $this->fail('Validation failed', 422, [
                'service' => ['invalid_service'],
            ]);
        }

        if (!$serviceIsActive) {
            return $this->fail('Validation failed', 422, [
                'service' => ['inactive_service'],
            ]);
        }

        if (!$this->isRequestedSlotCurrentlyAvailable($serviceDurationMinutes, $terminTimestamp)) {
            return $this->fail('Der gewählte Termin ist nicht mehr verfügbar.', 409, [
                'termin' => ['termin_not_available'],
            ]);
        }

        $duplicateGuardEnabled = !((bool) config('app.debug', false));
        if ($duplicateGuardEnabled && $this->isDuplicateRequest($email, $serviceSlug, $scheduledAt)) {
            return $this->fail('Duplicate request detected', 409, [
                'request' => ['duplicate_request'],
            ]);
        }

        $packageResolution = $this->resolveRequestedPackage($data, $serviceId);
        if ($packageResolution['error'] !== null) {
            return $this->fail('Validation failed', 422, $packageResolution['error']);
        }

        $selectedPackageId = $packageResolution['package_id'];

        if (!$this->ensureConsentsTableExists()) {
            return $this->fail('Die Anfrage konnte nicht gespeichert werden.', 500);
        }

        try {
            $database = app(Database::class);
            $result = $database->transaction(function () use (
                $firstName,
                $lastName,
                $email,
                $phone,
                $dateOfBirth,
                $gender,
                $title,
                $street,
                $postalCode,
                $city,
                $country,
                $medicalNotes,
                $dementiaSurname,
                $dementiaName,
                $dementiaDob,
                $dementiaDod,
                $contactPreference,
                $language,
                $serviceSlug,
                $scheduledAt,
                $freeMessage,
                $submittedByKey,
                $requiredKeys,
                $consentVersion,
                $selectedPackageId,
                $serviceId,
                $request,
                $rawData
            ) {
                $clientId = 0;
                $clientCrypto = app(ClientFieldEncryptionService::class);
                $identityIndex = $clientCrypto->identityBlindIndex($firstName, $lastName, $dateOfBirth);
                
                $clientRow = null;
                if ($identityIndex !== null && $clientCrypto->isIdentityBlindIndexColumnAvailable()) {
                    $candidates = db('clients')
                        ->where('identity_blind_index', $identityIndex)
                        ->select(['id', 'first_name', 'last_name', 'date_of_birth'])
                        ->get();

                    foreach ($clientCrypto->decryptClientRows(is_array($candidates) ? $candidates : []) as $candidate) {
                        $candidateFirst = strtolower(trim((string) ($candidate['first_name'] ?? '')));
                        $candidateLast = strtolower(trim((string) ($candidate['last_name'] ?? '')));
                        $candidateDob = trim((string) ($candidate['date_of_birth'] ?? ''));
                        if (
                            $candidateFirst === strtolower(trim($firstName))
                            && $candidateLast === strtolower(trim($lastName))
                            && $candidateDob === $dateOfBirth
                        ) {
                            $clientRow = ['id' => (int) ($candidate['id'] ?? 0)];
                            break;
                        }
                    }
                } else {
                    $clientRow = db('clients')
                        ->where('first_name', $firstName)
                        ->where('last_name', $lastName)
                        ->where('date_of_birth', $dateOfBirth)
                        ->select(['id'])
                        ->first();
                }

                if ($clientRow !== null) {
                    $clientId = (int) ($clientRow['id'] ?? 0);
                }

                if ($clientId <= 0) {
                    $insertData = [
                        'first_name' => $firstName,
                        'last_name' => $lastName,
                        'email' => $email,
                        'phone' => $phone !== '' ? $phone : null,
                        'date_of_birth' => $dateOfBirth,
                        'gender' => in_array($gender, ['m', 'w', 'd', 'other'], true) ? $gender : null,
                        'title' => $title !== '' ? $title : null,
                        'street' => $street !== '' ? $street : null,
                        'postal_code' => $postalCode !== '' ? $postalCode : null,
                        'city' => $city !== '' ? $city : null,
                        'country' => $country !== '' ? $country : null,
                        'medical_notes' => $medicalNotes !== '' ? $medicalNotes : null,
                        'dementia_person_surname' => $dementiaSurname !== '' ? $dementiaSurname : null,
                        'dementia_person_name' => $dementiaName !== '' ? $dementiaName : null,
                        'dementia_person_date_of_birth' => $dementiaDob,
                        'dementia_person_date_of_death' => $dementiaDod,
                        'contact_preference' => in_array($contactPreference, ['email', 'phone', 'sms'], true) ? $contactPreference : 'email',
                        'language' => in_array($language, ['de', 'en'], true) ? $language : 'de',
                    ];
                    if ($this->isClientTimezoneColumnAvailable()) {
                        $insertData['timezone'] = self::DEFAULT_CLIENT_TIMEZONE;
                    }

                    $insertData = $clientCrypto->encryptClientData($insertData);
                    
                    $clientId = db('clients')->insert($insertData);
                }

                $messageParts = ['Anfrage über Kontaktformular'];
                if ($freeMessage !== '') {
                    $messageParts[] = $freeMessage;
                }

                $requestId = db('client_requests')->insert([
                    'client_id' => $clientId,
                    'booking_id' => null,
                    'service_slug' => $serviceSlug,
                    'package_id' => $selectedPackageId,
                    'message' => implode("\n", $messageParts),
                    'desired_at' => $scheduledAt,
                    'status' => 'new',
                ]);

                $ipAddress = $this->resolveIpAddress($request);
                $userAgent = trim((string) $request->header('user-agent', 'unknown'));
                $deviceName = $this->resolveDeviceName($request, $rawData);
                $browserUserName = $this->resolveBrowserUserName($request, $rawData);
                $insertedConsentIds = [];

                // Only persist the required, server-validated consent keys
                foreach ($requiredKeys as $consentKey) {
                    $textSnapshot = trim((string) ($submittedByKey[$consentKey]['consent_text_snapshot'] ?? ''));

                    $signatureHash = $this->buildSignatureHash(
                        $requestId,
                        $consentKey,
                        true,
                        date('Y-m-d H:i:s'),
                        $consentVersion,
                        $textSnapshot,
                        $ipAddress,
                        $userAgent,
                        $deviceName,
                        $browserUserName
                    );

                    $consentId = db('consents')->insert([
                        'client_request_id' => $requestId,
                        'booking_id' => null,
                        'consent_key' => $consentKey,
                        'accepted' => true,
                        'accepted_at' => date('Y-m-d H:i:s'),
                        'consent_version' => $consentVersion,
                        'consent_text_snapshot' => $textSnapshot,
                        'ip_address' => $ipAddress,
                        'user_agent' => $userAgent,
                        'device_name' => $deviceName,
                        'browser_user_name' => $browserUserName,
                        'signature_hash' => $signatureHash,
                    ]);

                    $insertedConsentIds[] = $consentId;
                }

                return [
                    'booking_id' => null,
                    'request_id' => $requestId,
                    'client_id' => $clientId,
                    'service_id' => $serviceId,
                    'consent_ids' => $insertedConsentIds,
                ];
            });

            $packageHint = app(PackageBookingManager::class)->findActivePackageHint(
                (int) $result['client_id'],
                (int) $result['service_id']
            );

            app(EmailAutomationService::class)->dispatch('request.submitted', [
                'request_id' => (int) $result['request_id'],
                'client_id' => (int) $result['client_id'],
            ]);

            return $this->ok([
                'booking_id' => $result['booking_id'],
                'request_id' => $result['request_id'],
                'client_id' => $result['client_id'],
                'consent_ids' => $result['consent_ids'],
                'package_hint' => $packageHint,
                'message' => 'Ihre Anfrage wurde erfolgreich übermittelt.',
            ], 201);
        } catch (Throwable $exception) {
            app(Logger::class)->error('request.store.failed', [
                'exception' => $exception::class,
                'message' => $exception->getMessage(),
                'path' => $request->path(),
                'request_id' => (string) $request->header('x-request-id', ''),
            ]);

            return $this->fail('Die Anfrage konnte nicht gespeichert werden.', 500);
        }
    }

    private function ensureConsentsTableExists(): bool
    {
        try {
            $pdo = app(ConnectionManager::class)->connection();

            $check = $pdo->prepare(
                'SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table_name'
            );
            $check->execute(['table_name' => 'consents']);
            $exists = (int) $check->fetchColumn() > 0;

            if ($exists) {
                $columnCheck = $pdo->prepare(
                    'SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table_name AND COLUMN_NAME = :column_name'
                );
                $columnCheck->execute([
                    'table_name' => 'consents',
                    'column_name' => 'device_name',
                ]);

                if ((int) $columnCheck->fetchColumn() === 0) {
                    $pdo->exec("ALTER TABLE consents ADD COLUMN device_name VARCHAR(255) NOT NULL DEFAULT 'unknown' AFTER user_agent");
                }

                $browserUserColumnCheck = $pdo->prepare(
                    'SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table_name AND COLUMN_NAME = :column_name'
                );
                $browserUserColumnCheck->execute([
                    'table_name' => 'consents',
                    'column_name' => 'browser_user_name',
                ]);

                if ((int) $browserUserColumnCheck->fetchColumn() === 0) {
                    $pdo->exec("ALTER TABLE consents ADD COLUMN browser_user_name VARCHAR(255) NOT NULL DEFAULT 'unknown' AFTER device_name");
                }

                return true;
            }

            // Self-heal for local/dev setups where migrations were partially applied.
            $pdo->exec(
                'CREATE TABLE IF NOT EXISTS consents (' .
                'id INT AUTO_INCREMENT PRIMARY KEY,' .
                'booking_id INT NULL,' .
                'client_request_id INT NULL,' .
                'consent_key VARCHAR(100) NOT NULL,' .
                'accepted BOOLEAN NOT NULL,' .
                'accepted_at DATETIME NOT NULL,' .
                'consent_version VARCHAR(50) NOT NULL,' .
                'consent_text_snapshot TEXT NOT NULL,' .
                'ip_address VARCHAR(45) NOT NULL,' .
                'user_agent VARCHAR(512) NOT NULL,' .
                'device_name VARCHAR(255) NOT NULL DEFAULT "unknown",' .
                'browser_user_name VARCHAR(255) NOT NULL DEFAULT "unknown",' .
                'signature_hash VARCHAR(255) NOT NULL,' .
                'created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,' .
                'INDEX idx_booking_id (booking_id),' .
                'INDEX idx_client_request_id (client_request_id),' .
                'INDEX idx_consent_key (consent_key),' .
                'INDEX idx_accepted_at (accepted_at)' .
                ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
            );

            app(Logger::class)->warning('request.store.consents_table_autocreated');

            return true;
        } catch (Throwable $exception) {
            app(Logger::class)->error('request.store.consents_table_missing', [
                'exception' => $exception::class,
                'message' => $exception->getMessage(),
            ]);

            return false;
        }
    }

    private function isClientTimezoneColumnAvailable(): bool
    {
        if ($this->clientTimezoneColumnAvailable !== null) {
            return $this->clientTimezoneColumnAvailable;
        }

        try {
            $pdo = app(Database::class)->connection();
            $statement = $pdo->prepare(
                'SELECT 1 FROM information_schema.columns
                 WHERE table_schema = DATABASE()
                   AND table_name = :table_name
                   AND column_name = :column_name
                 LIMIT 1'
            );
            $statement->execute([
                'table_name' => 'clients',
                'column_name' => 'timezone',
            ]);

            $this->clientTimezoneColumnAvailable = $statement->fetchColumn() !== false;
            return $this->clientTimezoneColumnAvailable;
        } catch (Throwable) {
            $this->clientTimezoneColumnAvailable = false;
            return false;
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

    private function buildSignatureHash(
        int $requestId,
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
            (string) $requestId,
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

    private function readIntSetting(string $key, int $default): int
    {
        $row = db('settings')
            ->where('`key`', $key)
            ->select(['value'])
            ->first();

        if ($row === null) {
            return $default;
        }

        $raw = $row['value'] ?? null;
        if ($raw === null) {
            return $default;
        }

        if (is_numeric($raw)) {
            $value = (int) $raw;
            return $value >= 0 ? $value : $default;
        }

        return $default;
    }

    private function readIntAvailabilityRule(string $ruleKey, int $default): int
    {
        $legacyMap = [
            'booking_min_hours_notice' => 'min_notice_hours',
            'booking_advance_days' => 'advance_days',
        ];

        try {
            $row = db('availability_rules')
                ->where('rule_key', $ruleKey)
                ->select(['rule_value'])
                ->first();

            if (!is_array($row) && isset($legacyMap[$ruleKey])) {
                $row = db('availability_rules')
                    ->where('rule_key', $legacyMap[$ruleKey])
                    ->select(['rule_value'])
                    ->first();
            }

            if (is_array($row)) {
                $raw = trim((string) ($row['rule_value'] ?? ''));
                if ($raw !== '' && is_numeric($raw)) {
                    return max(0, (int) $raw);
                }
            }
        } catch (\Throwable) {
            // Use hardcoded fallback while availability_rules is not available.
        }

        return $default;
    }

    private function isDuplicateRequest(string $email, string $serviceSlug, string $scheduledAt): bool
    {
        $crypto = app(ClientFieldEncryptionService::class);
        $existingClient = null;

        if ($crypto->isEmailBlindIndexColumnAvailable()) {
            $emailIndex = $crypto->emailBlindIndex($email);
            if ($emailIndex !== null) {
                $candidates = db('clients')
                    ->where('email_blind_index', $emailIndex)
                    ->select(['id', 'email'])
                    ->get();
                foreach ($crypto->decryptClientRows(is_array($candidates) ? $candidates : []) as $candidate) {
                    if (strtolower(trim((string) ($candidate['email'] ?? ''))) === strtolower(trim($email))) {
                        $existingClient = ['id' => (int) ($candidate['id'] ?? 0)];
                        break;
                    }
                }
            }
        }

        if (!is_array($existingClient)) {
            $candidates = db('clients')
                ->select(['id', 'email'])
                ->get();

            foreach ($crypto->decryptClientRows(is_array($candidates) ? $candidates : []) as $candidate) {
                if (strtolower(trim((string) ($candidate['email'] ?? ''))) === strtolower(trim($email))) {
                    $existingClient = ['id' => (int) ($candidate['id'] ?? 0)];
                    break;
                }
            }
        }

        $clientId = (int) ($existingClient['id'] ?? 0);
        if ($clientId <= 0) {
            return false;
        }

        $windowMinutes = $this->readIntSetting('booking_duplicate_window_minutes', 15);
        if ($windowMinutes <= 0) {
            $windowMinutes = 15;
        }

        $threshold = date('Y-m-d H:i:s', time() - ($windowMinutes * 60));

        $existingRequest = db('client_requests')
            ->where('client_id', $clientId)
            ->where('service_slug', $serviceSlug)
            ->where('desired_at', $scheduledAt)
            ->where('created_at', $threshold, '>=')
            ->select(['id'])
            ->first();

        return $existingRequest !== null;
    }

    private function normalizeDateToYmd(string $dateString): ?string
    {
        $dateString = trim($dateString);
        if ($dateString === '') {
            return null;
        }

        $formats = ['Y-m-d', 'Y/m/d', 'd.m.Y', 'd-m-Y'];
        foreach ($formats as $format) {
            $parsed = DateTimeImmutable::createFromFormat($format, $dateString);
            if ($parsed instanceof DateTimeImmutable) {
                return $parsed->format('Y-m-d');
            }
        }

        return null;
    }

    private function isRequestedSlotCurrentlyAvailable(int $serviceDurationMinutes, int $terminTimestamp): bool
    {
        if ($serviceDurationMinutes <= 0) {
            return false;
        }

        $timezone = $this->resolveTimezone((string) config('app.timezone', 'Europe/Berlin'));
        if ($timezone === null) {
            return false;
        }

        $window = $this->readAvailabilityWindow();
        $rules = $this->readAvailabilityRules();
        $recurringWindows = $this->readRecurringAvailabilityWindows();

        $candidateStart = (new DateTimeImmutable('@' . $terminTimestamp))->setTimezone($timezone);
        $candidateEnd = $candidateStart->modify('+' . $serviceDurationMinutes . ' minutes');
        $dayStart = $candidateStart->setTime(0, 0, 0);
        $dayEnd = $dayStart->modify('+1 day');

        $workWindows = $this->resolveWorkWindowsForDay($dayStart, $recurringWindows, $window);
        if ($workWindows === []) {
            return false;
        }

        $isWithinWorkWindow = false;
        foreach ($workWindows as $workWindow) {
            if ($candidateStart >= $workWindow['start'] && $candidateEnd <= $workWindow['end']) {
                $isWithinWorkWindow = true;
                break;
            }
        }

        if (!$isWithinWorkWindow) {
            return false;
        }

        $maxAppointmentsPerDay = (int) ($rules['max_appointments_per_day'] ?? 0);
        if ($maxAppointmentsPerDay > 0) {
            $database = app(Database::class);
            $pdo = $database->connection();
            $countStmt = $pdo->prepare(
                'SELECT COUNT(*) AS booking_count
                 FROM bookings
                 WHERE status <> :cancelled
                   AND scheduled_at >= :from
                   AND scheduled_at < :to'
            );
            $countStmt->execute([
                'cancelled' => 'cancelled',
                'from' => $dayStart->format('Y-m-d H:i:s'),
                'to' => $dayEnd->format('Y-m-d H:i:s'),
            ]);
            $bookingCount = (int) ($countStmt->fetchColumn() ?: 0);
            if ($bookingCount >= $maxAppointmentsPerDay) {
                return false;
            }
        }

        $occupiedIntervals = $this->fetchOccupiedIntervals(
            $candidateStart->modify('-12 hours'),
            $candidateEnd->modify('+12 hours'),
            $window,
            $timezone
        );

        return !$this->overlapsWithIntervals(
            $candidateStart,
            $candidateEnd,
            $occupiedIntervals,
            (int) ($rules['buffer_minutes'] ?? 0)
        );
    }

    private function resolveTimezone(string $timezone): ?DateTimeZone
    {
        try {
            return new DateTimeZone($timezone);
        } catch (Throwable) {
            return null;
        }
    }

    /** @return array{start_hour:int, end_hour:int, slot_step_minutes:int} */
    private function readAvailabilityWindow(): array
    {
        $startHour = 8;
        $endHour = 18;
        $slotStepMinutes = $this->readIntSetting('booking_slot_interval_minutes', 30);

        if ($slotStepMinutes < 5) {
            $slotStepMinutes = 30;
        }

        return [
            'start_hour' => $startHour,
            'end_hour' => $endHour,
            'slot_step_minutes' => $slotStepMinutes,
        ];
    }

    /** @return array{buffer_minutes:int,max_appointments_per_day:int,booking_min_hours_notice:int,booking_advance_days:int,cancellation_hours_notice:int,reminder_hours_before:int} */
    private function readAvailabilityRules(): array
    {
        $rules = [
            'buffer_minutes' => 0,
            'max_appointments_per_day' => 0,
            'booking_min_hours_notice' => 24,
            'booking_advance_days' => 60,
            'cancellation_hours_notice' => 48,
            'reminder_hours_before' => 24,
        ];

        $legacyMap = [
            'min_notice_hours' => 'booking_min_hours_notice',
            'advance_days' => 'booking_advance_days',
        ];

        try {
            $rows = db('availability_rules')->select(['rule_key', 'rule_value'])->get();
        } catch (Throwable) {
            return $rules;
        }

        foreach (is_array($rows) ? $rows : [] as $row) {
            if (!is_array($row)) {
                continue;
            }

            $key = trim((string) ($row['rule_key'] ?? ''));
            if (isset($legacyMap[$key])) {
                $key = $legacyMap[$key];
            }

            if (!array_key_exists($key, $rules)) {
                continue;
            }

            $raw = trim((string) ($row['rule_value'] ?? ''));
            if ($raw === '' || !preg_match('/^-?\d+$/', $raw)) {
                continue;
            }

            $rules[$key] = (int) $raw;
        }

        return $rules;
    }

    /**
     * @return array<int, list<array{start_minutes:int,end_minutes:int}>>
     */
    private function readRecurringAvailabilityWindows(): array
    {
        try {
            $rows = db('recurring_availability')
                ->where('is_active', 1)
                ->orderBy('day_of_week', 'asc')
                ->orderBy('start_time', 'asc')
                ->get();
        } catch (Throwable) {
            return [];
        }

        $windowsByDay = [];
        foreach (is_array($rows) ? $rows : [] as $row) {
            if (!is_array($row)) {
                continue;
            }

            $dayOfWeek = (int) ($row['day_of_week'] ?? 0);
            if ($dayOfWeek < 1 || $dayOfWeek > 7) {
                continue;
            }

            $startMinutes = $this->timeStringToMinutes((string) ($row['start_time'] ?? ''));
            $endMinutes = $this->timeStringToMinutes((string) ($row['end_time'] ?? ''));
            if ($startMinutes === null || $endMinutes === null || $endMinutes <= $startMinutes) {
                continue;
            }

            $windowsByDay[$dayOfWeek][] = [
                'start_minutes' => $startMinutes,
                'end_minutes' => $endMinutes,
            ];
        }

        return $windowsByDay;
    }

    /**
     * @param array<int, list<array{start_minutes:int,end_minutes:int}>> $recurringWindows
     * @param array{start_hour:int, end_hour:int, slot_step_minutes:int} $defaultWindow
     * @return list<array{start:DateTimeImmutable,end:DateTimeImmutable}>
     */
    private function resolveWorkWindowsForDay(DateTimeImmutable $dayStart, array $recurringWindows, array $defaultWindow): array
    {
        $dayOfWeek = (int) $dayStart->format('N');

        if ($recurringWindows === []) {
            if ($this->isWeekend($dayStart)) {
                return [];
            }

            return [[
                'start' => $dayStart->setTime($defaultWindow['start_hour'], 0, 0),
                'end' => $dayStart->setTime($defaultWindow['end_hour'], 0, 0),
            ]];
        }

        $windows = [];
        foreach ($recurringWindows[$dayOfWeek] ?? [] as $window) {
            $startMinutes = $window['start_minutes'];
            $endMinutes = $window['end_minutes'];

            $windows[] = [
                'start' => $dayStart->setTime((int) floor($startMinutes / 60), $startMinutes % 60, 0),
                'end' => $dayStart->setTime((int) floor($endMinutes / 60), $endMinutes % 60, 0),
            ];
        }

        return $windows;
    }

    private function isWeekend(DateTimeImmutable $day): bool
    {
        $dayOfWeek = (int) $day->format('N');
        return $dayOfWeek >= 6;
    }

    private function timeStringToMinutes(string $time): ?int
    {
        $time = trim($time);
        if ($time === '') {
            return null;
        }

        if (!preg_match('/^(\d{2}):(\d{2})(?::\d{2})?$/', $time, $matches)) {
            return null;
        }

        $hours = (int) $matches[1];
        $minutes = (int) $matches[2];

        if ($hours < 0 || $hours > 23 || $minutes < 0 || $minutes > 59) {
            return null;
        }

        return ($hours * 60) + $minutes;
    }

    private function normalizeBookingDurationMinutes(int $durationMinutes, int $slotStepMinutes): int
    {
        if ($durationMinutes <= 0) {
            return 0;
        }

        if ($slotStepMinutes < 5) {
            $slotStepMinutes = 30;
        }

        return (int) (ceil($durationMinutes / $slotStepMinutes) * $slotStepMinutes);
    }

    /**
     * @param array{start_hour:int, end_hour:int, slot_step_minutes:int} $window
     * @return array<int, array{start:DateTimeImmutable,end:DateTimeImmutable}>
     */
    private function fetchOccupiedIntervals(
        DateTimeImmutable $from,
        DateTimeImmutable $to,
        array $window,
        DateTimeZone $timezone
    ): array {
        $database = app(Database::class);
        $pdo = $database->connection();

        $intervals = [];

        $bookingSql = 'SELECT b.scheduled_at, s.duration_minutes
                       FROM bookings b
                       INNER JOIN services s ON s.id = b.service_id
                       WHERE b.status <> :cancelled
                         AND b.scheduled_at >= :from
                         AND b.scheduled_at < :to';

        $bookingStmt = $pdo->prepare($bookingSql);
        $bookingStmt->execute([
            'cancelled' => 'cancelled',
            'from' => $from->format('Y-m-d H:i:s'),
            'to' => $to->format('Y-m-d H:i:s'),
        ]);

        $bookingRows = $bookingStmt->fetchAll();
        foreach (is_array($bookingRows) ? $bookingRows : [] as $row) {
            if (!is_array($row)) {
                continue;
            }

            $scheduledAtRaw = (string) ($row['scheduled_at'] ?? '');
            $duration = $this->normalizeBookingDurationMinutes(
                (int) ($row['duration_minutes'] ?? 0),
                (int) ($window['slot_step_minutes'] ?? 30)
            );
            if ($scheduledAtRaw === '' || $duration <= 0) {
                continue;
            }

            $start = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $scheduledAtRaw, $timezone);
            if (!$start instanceof DateTimeImmutable) {
                continue;
            }

            $intervals[] = [
                'start' => $start,
                'end' => $start->modify('+' . $duration . ' minutes'),
            ];
        }

        try {
            $blockedSql = 'SELECT starts_at, ends_at
                           FROM blocked_times
                           WHERE starts_at < :to
                             AND ends_at > :from';
            $blockedStmt = $pdo->prepare($blockedSql);
            $blockedStmt->execute([
                'from' => $from->format('Y-m-d H:i:s'),
                'to' => $to->format('Y-m-d H:i:s'),
            ]);

            $blockedRows = $blockedStmt->fetchAll();
            foreach (is_array($blockedRows) ? $blockedRows : [] as $row) {
                if (!is_array($row)) {
                    continue;
                }

                $startsAtRaw = (string) ($row['starts_at'] ?? '');
                $endsAtRaw = (string) ($row['ends_at'] ?? '');
                if ($startsAtRaw === '' || $endsAtRaw === '') {
                    continue;
                }

                $start = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $startsAtRaw, $timezone);
                $end = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $endsAtRaw, $timezone);
                if (!$start instanceof DateTimeImmutable || !$end instanceof DateTimeImmutable || $end <= $start) {
                    continue;
                }

                $intervals[] = [
                    'start' => $start,
                    'end' => $end,
                ];
            }
        } catch (Throwable) {
            // blocked_times may be unavailable in partially migrated environments.
        }

        return $intervals;
    }

    /**
     * @param array<int, array{start:DateTimeImmutable,end:DateTimeImmutable}> $occupiedIntervals
     */
    private function overlapsWithIntervals(
        DateTimeImmutable $candidateStart,
        DateTimeImmutable $candidateEnd,
        array $occupiedIntervals,
        int $bufferMinutes
    ): bool {
        foreach ($occupiedIntervals as $interval) {
            $intervalStart = $interval['start'];
            $intervalEnd = $interval['end'];

            if ($bufferMinutes > 0) {
                $intervalStart = $intervalStart->modify('-' . $bufferMinutes . ' minutes');
                $intervalEnd = $intervalEnd->modify('+' . $bufferMinutes . ' minutes');
            }

            if ($candidateStart < $intervalEnd && $intervalStart < $candidateEnd) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, mixed> $data
     * @return array{package_id:int|null,error:array<string, array<int, string>>|null}
     */
    private function resolveRequestedPackage(array $data, int $serviceId): array
    {
        if ($serviceId <= 0) {
            return [
                'package_id' => null,
                'error' => null,
            ];
        }

        $rawPackageId = $data['package_id'] ?? null;
        $rawPackageSlug = trim((string) ($data['package_slug'] ?? $data['package'] ?? ''));

        $packageRow = null;
        if (is_numeric($rawPackageId) && (int) $rawPackageId > 0) {
            $packageRow = db('service_packages')
                ->where('id', (int) $rawPackageId)
                ->select(['id', 'service_id', 'is_active'])
                ->first();
        } elseif ($rawPackageSlug !== '') {
            $packageRow = db('service_packages')
                ->where('slug', $rawPackageSlug)
                ->select(['id', 'service_id', 'is_active'])
                ->first();
        } else {
            return [
                'package_id' => null,
                'error' => null,
            ];
        }

        if (!is_array($packageRow)) {
            return [
                'package_id' => null,
                'error' => [
                    'package' => ['invalid_package'],
                ],
            ];
        }

        if (!(bool) ($packageRow['is_active'] ?? false)) {
            return [
                'package_id' => null,
                'error' => [
                    'package' => ['inactive_package'],
                ],
            ];
        }

        $packageServiceId = (int) ($packageRow['service_id'] ?? 0);
        if ($packageServiceId !== $serviceId) {
            return [
                'package_id' => null,
                'error' => [
                    'package' => ['package_service_mismatch'],
                ],
            ];
        }

        return [
            'package_id' => (int) ($packageRow['id'] ?? 0),
            'error' => null,
        ];
    }

}
