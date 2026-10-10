<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Logging\Logger;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;

final class EmailAutomationService
{
    private ?bool $invoiceTableAvailable = null;
    private ?bool $bookingsTableAvailable = null;
    private ?bool $appointmentsTableAvailable = null;
    /** @var array<string, bool> */
    private array $appointmentsColumnAvailability = [];
    private ?bool $invoicesBookingIdAvailable = null;
    private ?bool $invoicesAppointmentIdAvailable = null;
    private ?bool $invoicePdfPathColumnAvailable = null;

    /**
     * @var array<string, list<array{template_key: string, recipient: string, sender: string}>>
     */
    private const EVENT_TEMPLATE_MAP = [
        'request.submitted' => [
            ['template_key' => 'request_confirmation', 'recipient' => 'client', 'sender' => 'communication'],
            ['template_key' => 'admin_request_info', 'recipient' => 'support', 'sender' => 'support'],
        ],
        'appointment.accepted' => [
            ['template_key' => 'appointment_accepted', 'recipient' => 'client', 'sender' => 'communication'],
        ],
        'appointment.rejected' => [
            ['template_key' => 'appointment_rejected', 'recipient' => 'client', 'sender' => 'communication'],
        ],
        'appointment.storno' => [
            ['template_key' => 'appointment_storno', 'recipient' => 'client', 'sender' => 'communication'],
        ],
        'appointment.reschedule' => [
            ['template_key' => 'appointment_reschedule', 'recipient' => 'client', 'sender' => 'communication'],
        ],
        'appointment.no_show' => [
            ['template_key' => 'appointment_no_show', 'recipient' => 'client', 'sender' => 'communication'],
        ],
        'ticket.opened' => [
            ['template_key' => 'ticket_opened', 'recipient' => 'client', 'sender' => 'communication'],
        ],
        'ticket.closed' => [
            ['template_key' => 'ticket_closed', 'recipient' => 'client', 'sender' => 'communication'],
        ],
        'invoice.created' => [
            ['template_key' => 'invoice_created', 'recipient' => 'client', 'sender' => 'communication'],
        ],
        'invoice.payment_received' => [
            ['template_key' => 'payment_received', 'recipient' => 'client', 'sender' => 'communication'],
        ],
        'invoice.payment_reminder_1' => [
            ['template_key' => 'payment_reminder_1', 'recipient' => 'client', 'sender' => 'communication'],
        ],
        'invoice.payment_reminder_2' => [
            ['template_key' => 'payment_reminder_2', 'recipient' => 'client', 'sender' => 'communication'],
        ],
    ];

    /**
     * @param array{request_id?: int, booking_id?: int, appointment_id?: int, client_id?: int, invoice_id?: int, recipient_email?: string, client_first_name?: string, client_last_name?: string, form_data?: array<string, mixed>} $references
     * @return array{event: string, sent: int, skipped: int, results: list<array<string, mixed>>}
     */
    public function dispatch(string $event, array $references = []): array
    {
        $definitions = self::EVENT_TEMPLATE_MAP[$event] ?? [];
        $this->logAutomation('info', 'email.dispatch.start', [
            'event' => $event,
            'reference_keys' => array_keys($references),
            'request_id' => (int) ($references['request_id'] ?? 0),
            'booking_id' => (int) ($references['booking_id'] ?? 0),
            'appointment_id' => (int) ($references['appointment_id'] ?? 0),
            'client_id' => (int) ($references['client_id'] ?? 0),
        ]);

        if ($definitions === []) {
            $this->logAutomation('warning', 'email.dispatch.no_definitions', [
                'event' => $event,
            ]);
            return [
                'event' => $event,
                'sent' => 0,
                'skipped' => 0,
                'results' => [],
            ];
        }
        $results = [];
        $sent = 0;
        $skipped = 0;
        $automationEnabled = filter_var((string) config('mail.automation.enabled', false), FILTER_VALIDATE_BOOL) === true;

        try {
            $context = $this->buildContext($references);
            $this->logAutomation('info', 'email.dispatch.context_ready', [
                'event' => $event,
                'client_id' => (int) ($context['client']['id'] ?? 0),
                'booking_id' => (int) ($context['booking']['id'] ?? 0),
                'request_id' => (int) ($context['request']['id'] ?? 0),
                'client_email_present' => trim((string) ($context['client']['email'] ?? '')) !== '',
            ]);
        } catch (\Throwable $exception) {
            $this->logAutomation('error', 'email.dispatch.context_failed', [
                'event' => $event,
                'error' => $exception->getMessage(),
            ]);
            return [
                'event' => $event,
                'sent' => 0,
                'skipped' => count($definitions),
                'results' => [[
                    'status' => 'skipped',
                    'reason' => 'context_load_failed: ' . $exception->getMessage(),
                ]],
            ];
        }

        foreach ($definitions as $definition) {
            if (!$automationEnabled && !$this->shouldSendWhenAutomationDisabled($event, $definition['template_key'])) {
                $this->logAutomation('warning', 'email.dispatch.skipped', [
                    'event' => $event,
                    'template_key' => $definition['template_key'],
                    'reason' => 'automation_disabled',
                ]);
                $results[] = [
                    'template_key' => $definition['template_key'],
                    'status' => 'skipped',
                    'reason' => 'automation_disabled',
                ];
                $skipped++;
                continue;
            }

            $result = $this->sendTemplate(
                $event,
                $definition['template_key'],
                $definition['recipient'],
                $definition['sender'],
                $context
            );

            $resultStatus = (string) ($result['status'] ?? 'skipped');
            $this->logAutomation($resultStatus === 'sent' ? 'info' : 'warning', 'email.dispatch.result', [
                'event' => $event,
                'template_key' => $definition['template_key'],
                'status' => $resultStatus,
                'reason' => (string) ($result['reason'] ?? ''),
                'recipient' => $this->maskEmail((string) ($result['recipient'] ?? '')),
                'recipients_count' => is_array($result['recipients'] ?? null) ? count($result['recipients']) : 0,
                'transport' => (string) ($result['transport'] ?? ''),
                'fallback_path' => (string) ($result['fallback_path'] ?? ''),
            ]);

            $results[] = $result;
            if (($result['status'] ?? 'skipped') === 'sent') {
                $sent++;
                continue;
            }

            $skipped++;
        }

        return [
            'event' => $event,
            'sent' => $sent,
            'skipped' => $skipped,
            'results' => $results,
        ];
    }

    /** @return array<string, mixed> */
    private function buildContext(array $references): array
    {
        $requestId = (int) ($references['request_id'] ?? 0);
        $bookingId = (int) ($references['booking_id'] ?? ($references['appointment_id'] ?? 0));
        $clientId = (int) ($references['client_id'] ?? 0);
        $invoiceId = (int) ($references['invoice_id'] ?? 0);
        $recipientEmail = strtolower(trim((string) ($references['recipient_email'] ?? '')));
        $fallbackFirstName = trim((string) ($references['client_first_name'] ?? ''));
        $fallbackLastName = trim((string) ($references['client_last_name'] ?? ''));
        $formData = $this->normalizeFormData($references['form_data'] ?? []);

        $request = $requestId > 0 ? $this->loadRequestData($requestId) : [];
        if ($bookingId <= 0) {
            $bookingId = (int) ($request['booking_id'] ?? 0);
        }

        $booking = $bookingId > 0 ? $this->loadBookingData($bookingId) : [];
        if ($invoiceId <= 0) {
            $invoiceId = (int) ($booking['invoice_id'] ?? 0);
        }

        $invoice = $invoiceId > 0 ? $this->loadInvoiceData($invoiceId) : [];
        if ($clientId <= 0) {
            $clientId = (int) ($request['client_id'] ?? ($booking['client_id'] ?? ($invoice['client_id'] ?? 0)));
        }

        $client = $clientId > 0 ? $this->loadClientData($clientId) : [];

        if ($client === [] && $recipientEmail !== '' && filter_var($recipientEmail, FILTER_VALIDATE_EMAIL)) {
            $client = [
                'id' => 0,
                'first_name' => $fallbackFirstName,
                'last_name' => $fallbackLastName,
                'email' => $recipientEmail,
                'phone' => '',
                'date_of_birth' => '',
                'medical_notes' => '',
                'created_at' => '',
                'updated_at' => '',
            ];
        }

        $appointment = $this->appointmentTemplateData($request, $booking, $formData);
        $requestContext = $this->requestTemplateData($request, $appointment, $formData, $client);

        return [
            'client' => $client,
            'request' => $requestContext,
            'booking' => $booking,
            'appointment' => $appointment,
            'form' => $formData,
            'invoice' => $invoice,
            'payment' => $this->paymentTemplateData($invoice),
            'system' => $this->systemTemplateData(),
        ];
    }

    /** @param array<string, mixed> $request
     *  @param array<string, mixed> $booking
     *  @param array<string, mixed> $formData
     *  @return array<string, string>
     */
    private function appointmentTemplateData(array $request, array $booking, array $formData): array
    {
        $scheduledAt = trim((string) ($booking['scheduled_at'] ?? $request['desired_at'] ?? ''));

        $formDate = trim((string) (
            $formData['service_type']['contact']['appointment_date']
            ?? $formData['appointment_date']
            ?? ''
        ));
        $formTime = trim((string) (
            $formData['service_type']['contact']['appointment_time']
            ?? $formData['appointment_time']
            ?? ''
        ));

        $date = '';
        $time = '';
        $dateTime = '';

        if ($scheduledAt !== '') {
            $dateTime = $scheduledAt;

            try {
                $parsed = DateTimeImmutable::createFromFormat('d.m.Y H:i', $scheduledAt)
                    ?: DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $scheduledAt)
                    ?: DateTimeImmutable::createFromFormat('Y-m-d H:i', $scheduledAt)
                    ?: new DateTimeImmutable($scheduledAt);
                $date = $parsed->format('d.m.Y');
                $time = $parsed->format('H:i');
            } catch (\Throwable) {
                // Keep fallback values below.
            }
        }

        if ($date === '' && $formDate !== '') {
            $date = $this->formatDateForTemplate($formDate);
        }
        if ($time === '' && $formTime !== '') {
            $time = $formTime;
        }
        if ($dateTime === '' && $date !== '' && $time !== '') {
            $dateTime = trim($date . ' ' . $time);
        }

        return [
            'date' => $date,
            'time' => $time,
            'datetime' => $dateTime,
        ];
    }

    /**
     * @param array<string, mixed> $request
     * @param array<string, string> $appointment
     * @param array<string, mixed> $formData
     * @param array<string, mixed> $client
     * @return array<string, mixed>
     */
    private function requestTemplateData(array $request, array $appointment, array $formData, array $client): array
    {
        $clientId = (int) ($client['id'] ?? ($request['client_id'] ?? 0));
        $base = [
            'id' => (int) ($request['id'] ?? 0),
            'client_id' => $clientId,
            'booking_id' => (int) ($request['booking_id'] ?? 0),
            'service_slug' => trim((string) ($request['service_slug'] ?? '')),
            'status' => trim((string) ($request['status'] ?? 'new')),
            'desired_at' => trim((string) ($request['desired_at'] ?? '')),
            'contact_preference' => trim((string) ($request['contact_preference'] ?? 'email')),
            'notes' => trim((string) ($request['notes'] ?? '')),
            'package_id' => (int) ($request['package_id'] ?? 0),
            'created_at' => trim((string) ($request['created_at'] ?? '')),
            'updated_at' => trim((string) ($request['updated_at'] ?? '')),
            'type' => '',
            'type_label' => '',
            'service_action' => '',
            'project_name' => '',
            'project_number' => '',
            'contract_name' => '',
            'contract_number' => '',
            'ticket_category' => '',
            'detail_primary_label' => '',
            'detail_primary_value' => '',
            'detail_secondary_label' => '',
            'detail_secondary_value' => '',
            'detail_tertiary_label' => '',
            'detail_tertiary_value' => '',
            'details_text' => '',
            'details_html' => '',
            'customer_details_text' => '',
            'customer_details_html' => '',
            'admin_details_text' => '',
            'admin_details_html' => '',
        ];

        $serviceType = strtolower($this->stringifyFormValue($formData['service_type'] ?? ''));
        if ($serviceType === '') {
            return $base;
        }

        $base['type'] = $serviceType;
        $base['type_label'] = $this->requestTypeLabel($serviceType);

        $details = [];
        $customerDetails = [];
        $adminDetails = [];

        if ($serviceType === 'contact') {
            $serviceName = $this->resolveServiceDisplayName($this->stringifyFormValue($formData['service_type']['contact']['service'] ?? ''));
            $description = $this->stringifyFormValue($formData['service_type']['contact']['message'] ?? '');
            $desiredAt = trim((string) ($appointment['datetime'] ?? ''));

            $base['service_slug'] = $this->coalesceTemplateString($base['service_slug'], $serviceName);
            $base['desired_at'] = $this->coalesceTemplateString($base['desired_at'], $desiredAt);
            $base['notes'] = $this->coalesceTemplateString($base['notes'], $description);

            $details = [
                ['label' => 'Gewünschtees Termindatum', 'value' => $desiredAt],
                ['label' => 'Serviceart', 'value' => $serviceName],
                ['label' => 'Kurzbeschreibung', 'value' => $description],
            ];
            $customerDetails = $details;
            $adminDetails = $details;
        } elseif ($serviceType === 'service') {
            $action = strtolower($this->stringifyFormValue($formData['service_type']['service']['service_action'] ?? ''));
            $actionLabel = $this->serviceActionLabel($action);
            $projectNumber = $this->stringifyFormValue($formData['service_type']['service']['project_number'] ?? '');
            $contractNumber = $this->stringifyFormValue($formData['service_type']['service']['contract_number'] ?? '');
            $projectMeta = $this->resolveProjectTemplateMeta($projectNumber, $clientId);
            $contractMeta = $this->resolveContractTemplateMeta($contractNumber, $clientId);
            $description = $this->firstFilledTemplateString([
                $this->stringifyFormValue($formData['service_type']['service']['service_action']['update']['update_details'] ?? ''),
                $this->stringifyFormValue($formData['service_type']['service']['service_action']['cancel']['message'] ?? ''),
                $this->stringifyFormValue($formData['service_type']['service']['service_action']['other']['message'] ?? ''),
            ]);

            $base['service_action'] = $actionLabel;
            $base['project_name'] = $projectMeta['customer_value'];
            $base['project_number'] = $projectMeta['number'];
            $base['contract_name'] = $contractMeta['customer_value'];
            $base['contract_number'] = $contractMeta['number'];
            $base['service_slug'] = $this->coalesceTemplateString($base['service_slug'], $actionLabel);
            $base['desired_at'] = $this->coalesceTemplateString($base['desired_at'], $projectMeta['customer_value']);
            $base['notes'] = $this->coalesceTemplateString($base['notes'], $description);

            $customerDetails = [
                ['label' => 'Gewünschtee Aktion', 'value' => $actionLabel],
                ['label' => 'Projekt', 'value' => $projectMeta['customer_value']],
                ['label' => 'Vertrag', 'value' => $contractMeta['customer_value']],
                ['label' => 'Kurzbeschreibung', 'value' => $description],
            ];
            $adminDetails = [
                ['label' => 'Gewünschtee Aktion', 'value' => $actionLabel],
                ['label' => 'Projekt', 'value' => $projectMeta['admin_value']],
                ['label' => 'Vertrag', 'value' => $contractMeta['admin_value']],
                ['label' => 'Kurzbeschreibung', 'value' => $description],
            ];
            $details = $customerDetails;
        } elseif ($serviceType === 'ticket') {
            $category = strtolower($this->stringifyFormValue($formData['service_type']['ticket']['ticket_category'] ?? ''));
            $categoryLabel = $this->ticketCategoryLabel($category);
            $description = $this->firstFilledTemplateString([
                $this->stringifyFormValue($formData['service_type']['ticket']['ticket_category']['technical']['steps'] ?? ''),
                $this->stringifyFormValue($formData['service_type']['ticket']['ticket_category']['invoice']['message'] ?? ''),
                $this->stringifyFormValue($formData['service_type']['ticket']['ticket_category']['other']['message'] ?? ''),
            ]);

            $base['ticket_category'] = $categoryLabel;
            $base['service_slug'] = $this->coalesceTemplateString($base['service_slug'], $categoryLabel);
            $base['notes'] = $this->coalesceTemplateString($base['notes'], $description);

            $details = [
                ['label' => 'Problemkategorie', 'value' => $categoryLabel],
                ['label' => 'Kurzbeschreibung', 'value' => $description],
            ];
            $customerDetails = $details;
            $adminDetails = $details;
        }

        if ($customerDetails === []) {
            $customerDetails = $details;
        }
        if ($adminDetails === []) {
            $adminDetails = $details;
        }

        $details = array_values(array_filter($details, static function (array $detail): bool {
            return trim((string) ($detail['value'] ?? '')) !== '';
        }));
        $customerDetails = array_values(array_filter($customerDetails, static function (array $detail): bool {
            return trim((string) ($detail['value'] ?? '')) !== '';
        }));
        $adminDetails = array_values(array_filter($adminDetails, static function (array $detail): bool {
            return trim((string) ($detail['value'] ?? '')) !== '';
        }));

        if (isset($details[0])) {
            $base['detail_primary_label'] = (string) ($details[0]['label'] ?? '');
            $base['detail_primary_value'] = (string) ($details[0]['value'] ?? '');
        }
        if (isset($details[1])) {
            $base['detail_secondary_label'] = (string) ($details[1]['label'] ?? '');
            $base['detail_secondary_value'] = (string) ($details[1]['value'] ?? '');
        }
        if (isset($details[2])) {
            $base['detail_tertiary_label'] = (string) ($details[2]['label'] ?? '');
            $base['detail_tertiary_value'] = (string) ($details[2]['value'] ?? '');
        }

        $base['details_text'] = $this->requestDetailsText($details);
        $base['details_html'] = $this->requestDetailsHtml($details);
        $base['customer_details_text'] = $this->requestDetailsText($customerDetails);
        $base['customer_details_html'] = $this->requestDetailsHtml($customerDetails);
        $base['admin_details_text'] = $this->requestDetailsText($adminDetails);
        $base['admin_details_html'] = $this->requestDetailsHtml($adminDetails);

        return $base;
    }

    /** @param mixed $rawFormData
     *  @return array<string, mixed>
     */
    private function normalizeFormData(mixed $rawFormData): array
    {
        if (!is_array($rawFormData)) {
            return [];
        }

        $normalized = [];

        foreach ($rawFormData as $key => $value) {
            $keyName = trim((string) $key);
            if ($keyName === '') {
                continue;
            }

            $path = explode('.', $keyName);
            $cursor = &$normalized;
            $pathCount = count($path);

            foreach ($path as $index => $segment) {
                $segment = trim((string) $segment);
                if ($segment === '') {
                    continue;
                }

                $isLeaf = $index === ($pathCount - 1);
                if ($isLeaf) {
                    $cursor[$segment] = $value;
                    continue;
                }

                if (!isset($cursor[$segment])) {
                    $cursor[$segment] = [];
                } elseif (!is_array($cursor[$segment])) {
                    $cursor[$segment] = [
                        '__value' => $cursor[$segment],
                    ];
                }

                $cursor = &$cursor[$segment];
            }

            unset($cursor);
        }

        return $normalized;
    }

    private function requestTypeLabel(string $serviceType): string
    {
        return match ($serviceType) {
            'contact' => 'Angebot anfragen',
            'service' => 'Bestehender Service',
            'ticket' => 'Problem melden',
            default => ucfirst($serviceType),
        };
    }

    private function serviceActionLabel(string $action): string
    {
        return match ($action) {
            'update' => 'Service aktualisieren / erweitern',
            'cancel' => 'Service kündigen',
            'other' => 'Sonstiges',
            default => $action !== '' ? ucfirst($action) : '',
        };
    }

    private function ticketCategoryLabel(string $category): string
    {
        return match ($category) {
            'technical' => 'Technisches Problem',
            'invoice' => 'Rechnung / Zahlung',
            'other' => 'Sonstiges',
            default => $category !== '' ? ucfirst($category) : '',
        };
    }

    private function resolveServiceDisplayName(string $serviceValue): string
    {
        $serviceValue = trim($serviceValue);
        if ($serviceValue === '') {
            return '';
        }

        try {
            if (ctype_digit($serviceValue)) {
                $service = db('services')
                    ->where('id', (int) $serviceValue)
                    ->select(['name', 'slug'])
                    ->first();

                if (is_array($service)) {
                    $name = trim((string) ($service['name'] ?? ''));
                    if ($name !== '') {
                        return $name;
                    }

                    return $this->formatServiceSlugForEmail((string) ($service['slug'] ?? ''));
                }
            }
        } catch (\Throwable) {
            // Fall through to slug formatting.
        }

        return $this->formatServiceSlugForEmail($serviceValue);
    }

    private function resolveProjectDisplayName(string $projectNumber, int $clientId): string
    {
        $projectNumber = trim($projectNumber);
        if ($projectNumber === '') {
            return '';
        }

        $numeric = preg_replace('/\D+/', '', $projectNumber) ?? '';
        if ($numeric === '') {
            return $projectNumber;
        }

        try {
            $query = db('projects')
                ->where('id', (int) $numeric)
                ->select(['id', 'name', 'client_id']);

            if ($clientId > 0) {
                $query->where('client_id', $clientId);
            }

            $project = $query->first();
            if (is_array($project)) {
                $projectName = trim((string) ($project['name'] ?? ''));
                if ($projectName !== '') {
                    return $projectName . ' (#' . $numeric . ')';
                }
            }
        } catch (\Throwable) {
            // Fall back to the submitted project number if lookup is unavailable.
        }

        return 'Projekt #' . $numeric;
    }

    /**
     * @return array{number:string, customer_value:string, admin_value:string}
     */
    private function resolveProjectTemplateMeta(string $projectNumber, int $clientId): array
    {
        $projectNumber = trim($projectNumber);
        if ($projectNumber === '') {
            return [
                'number' => '',
                'customer_value' => '',
                'admin_value' => '',
            ];
        }

        $numeric = preg_replace('/\D+/', '', $projectNumber) ?? '';
        if ($numeric === '') {
            return [
                'number' => $projectNumber,
                'customer_value' => 'Unbekanntes Projekt (' . $projectNumber . ')',
                'admin_value' => 'Falsche Projektnummer (' . $projectNumber . ')',
            ];
        }

        try {
            $project = db('projects')
                ->where('id', (int) $numeric)
                ->select(['id', 'name', 'client_id'])
                ->first();

            if (is_array($project)) {
                $matchesClient = (int) ($project['client_id'] ?? 0) > 0 && (int) ($project['client_id'] ?? 0) === $clientId;
                $projectName = trim((string) ($project['name'] ?? ''));

                if ($matchesClient && $projectName !== '') {
                    return [
                        'number' => $numeric,
                        'customer_value' => $projectName,
                        'admin_value' => $projectName,
                    ];
                }

                return [
                    'number' => $numeric,
                    'customer_value' => 'Unbekanntes Projekt (#' . $numeric . ')',
                    'admin_value' => 'Falsche Projektnummer (#' . $numeric . ')',
                ];
            }
        } catch (\Throwable) {
            // Fall back to unknown/fault markers.
        }

        return [
            'number' => $numeric,
            'customer_value' => 'Unbekanntes Projekt (#' . $numeric . ')',
            'admin_value' => 'Falsche Projektnummer (#' . $numeric . ')',
        ];
    }

    /**
     * @return array{number:string, customer_value:string, admin_value:string}
     */
    private function resolveContractTemplateMeta(string $contractNumber, int $clientId): array
    {
        $contractNumber = trim($contractNumber);
        if ($contractNumber === '') {
            return [
                'number' => '',
                'customer_value' => '',
                'admin_value' => '',
            ];
        }

        $numeric = preg_replace('/\D+/', '', $contractNumber) ?? '';
        if ($numeric === '') {
            return [
                'number' => $contractNumber,
                'customer_value' => 'Unbekannter Vertrag (' . $contractNumber . ')',
                'admin_value' => 'Falsche Vertragsnummer (' . $contractNumber . ')',
            ];
        }

        try {
            $contract = db('contracts')
                ->where('id', (int) $numeric)
                ->select(['id', 'client_id'])
                ->first();

            if (is_array($contract)) {
                $matchesClient = (int) ($contract['client_id'] ?? 0) > 0 && (int) ($contract['client_id'] ?? 0) === $clientId;
                if ($matchesClient) {
                    return [
                        'number' => $numeric,
                        'customer_value' => 'Vertrag #' . $numeric,
                        'admin_value' => 'Vertrag #' . $numeric,
                    ];
                }

                return [
                    'number' => $numeric,
                    'customer_value' => 'Unbekannter Vertrag (#' . $numeric . ')',
                    'admin_value' => 'Falsche Vertragsnummer (#' . $numeric . ')',
                ];
            }
        } catch (\Throwable) {
            // Fall back to unknown/fault markers.
        }

        return [
            'number' => $numeric,
            'customer_value' => 'Unbekannter Vertrag (#' . $numeric . ')',
            'admin_value' => 'Falsche Vertragsnummer (#' . $numeric . ')',
        ];
    }

    /**
     * @param list<array{label: string, value: string}> $details
     */
    private function requestDetailsText(array $details): string
    {
        $lines = [];

        foreach ($details as $detail) {
            $label = trim((string) ($detail['label'] ?? ''));
            $value = trim((string) ($detail['value'] ?? ''));
            if ($label === '' || $value === '') {
                continue;
            }

            $lines[] = $label . ': ' . $value;
        }

        return implode("\n", $lines);
    }

    /**
     * @param list<array{label: string, value: string}> $details
     */
    private function requestDetailsHtml(array $details): string
    {
        $html = '';

        foreach ($details as $detail) {
            $label = trim((string) ($detail['label'] ?? ''));
            $value = trim((string) ($detail['value'] ?? ''));
            if ($label === '' || $value === '') {
                continue;
            }

            $html .= '<div style="margin:0 0 8px;font-size:14px;line-height:1.6;color:#334155;">'
                . '<strong>' . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . ':</strong> '
                . nl2br(htmlspecialchars($value, ENT_QUOTES, 'UTF-8'))
                . '</div>';
        }

        return $html;
    }

    /** @param list<string> $values */
    private function firstFilledTemplateString(array $values): string
    {
        foreach ($values as $value) {
            $trimmed = trim($value);
            if ($trimmed !== '') {
                return $trimmed;
            }
        }

        return '';
    }

    private function coalesceTemplateString(string $currentValue, string $fallbackValue): string
    {
        $currentValue = trim($currentValue);
        if ($currentValue !== '') {
            return $currentValue;
        }

        return trim($fallbackValue);
    }

    /** @param mixed $value */
    private function stringifyFormValue(mixed $value): string
    {
        if (is_array($value)) {
            $selectedValue = $value['__value'] ?? '';
            return is_scalar($selectedValue) ? trim((string) $selectedValue) : '';
        }

        return trim((string) $value);
    }

    /** @param array<string, mixed> $invoice
     *  @return array<string, string>
     */
    private function paymentTemplateData(array $invoice): array
    {
        $reference = trim($this->readSettingValue('bank_transfer_reference', ''));
        if ($reference === '') {
            $invoiceNumber = (string) ($invoice['invoice_number'] ?? '');
            if ($invoiceNumber !== '') {
                $reference = 'Rechnung #' . $invoiceNumber;
            }
        }

        $methods = [];

        if ($this->normalizeBool($this->readSettingValue('bank_transfer_enabled', '0'))) {
            $methods[] = [
                'type' => 'bank_transfer',
                'title' => 'Banküberweisung',
                'lines' => [
                    'Kontoinhaber: ' . $this->fallbackText($this->readSettingValue('bank_transfer_account_holder', 'Christoph Henz'), '-'),
                    'IBAN: ' . $this->fallbackText($this->readSettingValue('bank_data_iban', ''), '-'),
                    'BIC: ' . $this->fallbackText($this->readSettingValue('bank_data_bic', ''), '-'),
                    'Bank: ' . $this->fallbackText($this->readSettingValue('bank_data_name', ''), '-'),
                    'Verwendungszweck: ' . $this->fallbackText($reference, '-'),
                ],
            ];
        }

        if ($this->normalizeBool($this->readSettingValue('paypal_enabled', '0'))) {
            $paypalEmail = trim($this->readSettingValue('paypal_email', ''));
            $methods[] = [
                'type' => 'paypal',
                'title' => 'PayPal',
                'lines' => [
                    'PayPal-Konto: ' . $this->fallbackText($paypalEmail, '-'),
                    'PayPal-Link: ' . $this->fallbackText($this->buildPaypalUrl($paypalEmail), '-'),
                    'Verwendungszweck: ' . $this->fallbackText($reference, '-'),
                ],
            ];
        }

        $summaryText = trim($this->buildPaymentSummaryText($methods));
        $summaryHtml = trim($this->buildPaymentSummaryHtml($methods));

        return [
            'summary_text' => $summaryText,
            'summary_html' => $summaryHtml,
        ];
    }

    /** @param list<array{type: string, title: string, lines: list<string>}> $methods */
    private function buildPaymentSummaryText(array $methods): string
    {
        if ($methods === []) {
            return 'Derzeit sind keine zusätzlichen Zahlungsinformationen hinterlegt.';
        }

        $parts = [];
        foreach ($methods as $method) {
            $title = trim((string) ($method['title'] ?? 'Zahlungsart'));
            $lines = is_array($method['lines'] ?? null) ? $method['lines'] : [];
            $parts[] = $title . ': ' . implode(' | ', array_map(static fn ($line) => trim((string) $line), $lines));
        }

        return implode("\n", $parts);
    }

    /** @param list<array{type: string, title: string, lines: list<string>}> $methods */
    private function buildPaymentSummaryHtml(array $methods): string
    {
        if ($methods === []) {
            return '<div style="margin:0 0 16px;padding:14px 16px;background:#fbf6f0;border:1px solid #eadfd3;border-radius:12px;color:#6b5848;font-size:14px;line-height:1.6;">Derzeit sind keine zusätzlichen Zahlungsinformationen hinterlegt.</div>';
        }

        $blocks = [];
        foreach ($methods as $method) {
            $title = htmlspecialchars(trim((string) ($method['title'] ?? 'Zahlungsart')), ENT_QUOTES, 'UTF-8');
            $lines = is_array($method['lines'] ?? null) ? $method['lines'] : [];
            $lineHtml = '';
            foreach ($lines as $line) {
                $lineText = trim((string) $line);
                if (preg_match('/^PayPal-Link:\s*(https?:\/\/\S+)$/i', $lineText, $matches) === 1) {
                    $url = trim((string) ($matches[1] ?? ''));
                    $safeUrl = htmlspecialchars($url, ENT_QUOTES, 'UTF-8');
                    $lineHtml .= '<div style="margin:8px 0 10px;">'
                        . '<a href="' . $safeUrl . '" target="_blank" rel="noopener noreferrer"'
                        . ' style="display:inline-block;min-width:220px;padding:14px 28px;border-radius:999px;border:1px solid #d7a400;background:linear-gradient(180deg,#ffd65a 0%,#ffc439 100%);color:#003087;font-weight:800;font-size:18px;line-height:1;text-align:center;text-decoration:none;box-shadow:0 2px 0 rgba(153,116,0,0.35),inset 0 1px 0 rgba(255,255,255,0.65);font-family:Arial,\'Helvetica Neue\',Helvetica,sans-serif;letter-spacing:0.2px;">'
                        . 'Pay<span style="color:#009cde;">Pal</span>'
                        . '</a>'
                        . '</div>';
                    continue;
                }

                $lineHtml .= '<div style="margin:0 0 4px;">' . htmlspecialchars($lineText, ENT_QUOTES, 'UTF-8') . '</div>';
            }

            $blocks[] = '<div style="margin:0 0 12px;padding:14px 16px;background:#fbf6f0;border:1px solid #eadfd3;border-radius:12px;color:#5d4a3b;font-size:14px;line-height:1.6;">'
                . '<div style="font-weight:700;color:#2f241c;margin:0 0 8px;">' . $title . '</div>'
                . $lineHtml
                . '</div>';
        }

        return implode('', $blocks);
    }

    private function buildPaypalUrl(string $paypalEmail): string
    {
        $email = trim($paypalEmail);
        if ($email === '' || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            return '';
        }

        return 'https://www.paypal.com/cgi-bin/webscr?cmd=_xclick&business=' . rawurlencode($email);
    }

    private function fallbackText(string $value, string $fallback): string
    {
        $trimmed = trim($value);
        return $trimmed !== '' ? $trimmed : $fallback;
    }

    /** @return array<string, mixed> */
    private function loadRequestData(int $requestId): array
    {
        $row = db('client_requests')
            ->where('id', $requestId)
            ->select([
                'id',
                'client_id',
                'booking_id',
                'service_slug',
                'status',
                'desired_at',
                'message',
                'package_id',
                'created_at',
            ])
            ->first();

        if (!is_array($row)) {
            return [];
        }

        return [
            'id' => (int) ($row['id'] ?? 0),
            'client_id' => (int) ($row['client_id'] ?? 0),
            'booking_id' => isset($row['booking_id']) && $row['booking_id'] !== null ? (int) $row['booking_id'] : 0,
            'service_slug' => $this->formatServiceSlugForEmail((string) ($row['service_slug'] ?? '')),
            'status' => (string) ($row['status'] ?? ''),
            'desired_at' => $this->formatDateTimeForTemplate((string) ($row['desired_at'] ?? '')),
            'contact_preference' => '',
            'notes' => (string) ($row['message'] ?? ''),
            'package_id' => isset($row['package_id']) && $row['package_id'] !== null ? (int) $row['package_id'] : 0,
            'created_at' => $this->formatDateTimeForTemplate((string) ($row['created_at'] ?? '')),
            'updated_at' => $this->formatDateTimeForTemplate((string) ($row['created_at'] ?? '')),
        ];
    }

    /** @return array<string, mixed> */
    private function loadBookingData(int $bookingId): array
    {
        $row = null;

        if ($this->isBookingsTableAvailable()) {
            $row = db('bookings')
                ->where('id', $bookingId)
                ->select([
                    'id',
                    'client_id',
                    'service_id',
                    'scheduled_at',
                    'status',
                    'payment_status',
                    'notes',
                    'cancellation_reason',
                    'cancelled_at',
                    'package_purchase_id',
                    'is_package_booking',
                    'package_session_no',
                    'package_session_state',
                    'created_at',
                ])
                ->first();
        } elseif ($this->isAppointmentsTableAvailable()) {
            $appointmentSelect = [
                'id',
                'client_id',
                'service_id',
                'appointment_date',
                'status',
                'notes',
                'created_at',
            ];

            foreach (['payment_status', 'cancellation_reason', 'cancelled_at', 'package_purchase_id', 'is_package_booking', 'package_session_no', 'package_session_state'] as $optionalColumn) {
                if ($this->isAppointmentsColumnAvailable($optionalColumn)) {
                    $appointmentSelect[] = $optionalColumn;
                }
            }

            $row = db('appointments')
                ->where('id', $bookingId)
                ->select($appointmentSelect)
                ->first();

            if (is_array($row)) {
                $row['scheduled_at'] = (string) ($row['appointment_date'] ?? '');
            }
        }

        if (!is_array($row)) {
            return [];
        }

        $scheduledAt = (string) ($row['scheduled_at'] ?? '');

        return [
            'id' => (int) ($row['id'] ?? 0),
            'client_id' => (int) ($row['client_id'] ?? 0),
            'invoice_id' => $this->loadLatestInvoiceIdForBooking((int) ($row['id'] ?? 0)),
            'service_id' => (int) ($row['service_id'] ?? 0),
            'status' => (string) ($row['status'] ?? ''),
            'payment_status' => (string) ($row['payment_status'] ?? ''),
            'scheduled_at' => $this->formatDateTimeForTemplate($scheduledAt),
            'started_at' => $this->formatDateTimeForTemplate($scheduledAt),
            'notes' => (string) ($row['notes'] ?? ''),
            'cancellation_reason' => (string) ($row['cancellation_reason'] ?? ''),
            'cancelled_at' => $this->formatDateTimeForTemplate((string) ($row['cancelled_at'] ?? '')),
            'package_purchase_id' => isset($row['package_purchase_id']) && $row['package_purchase_id'] !== null ? (int) $row['package_purchase_id'] : 0,
            'is_package_booking' => (int) ($row['is_package_booking'] ?? 0),
            'package_session_no' => isset($row['package_session_no']) && $row['package_session_no'] !== null ? (int) $row['package_session_no'] : 0,
            'package_session_state' => (string) ($row['package_session_state'] ?? 'none'),
            'created_at' => $this->formatDateTimeForTemplate((string) ($row['created_at'] ?? '')),
            'updated_at' => $this->formatDateTimeForTemplate((string) ($row['created_at'] ?? '')),
        ];
    }

    /** @return array<string, mixed> */
    private function loadInvoiceData(int $invoiceId): array
    {
        if (!$this->isInvoiceTableAvailable()) {
            return [];
        }

        $row = db('invoices')
            ->where('id', $invoiceId)
            ->first();

        if (!is_array($row)) {
            return [];
        }

        $dueDateRaw = trim((string) ($row['due_date'] ?? ''));
        $paymentNotice = 'Bitte beachten Sie: Die Leistung wird erst nach Zahlungseingang erbracht.';
        if ($dueDateRaw === '') {
            $paymentNotice = 'Wichtiger Hinweis: Es wurde aufgrund der knappen Terminbuchung kein Fälligkeitsdatum gesetzt.
 Die Leistung wird nur erbracht, wenn der Betrag vor Antritt des Termins vollständig beglichen wurde.';
        }

        return [
            'id' => (int) ($row['id'] ?? 0),
            'invoice_number' => (int) ($row['invoice_number'] ?? 0),
            'client_id' => (int) ($row['client_id'] ?? 0),
            'booking_id' => (int) (($row['booking_id'] ?? $row['appointment_id']) ?? 0),
            'currency_code' => (string) ($row['currency_code'] ?? 'EUR'),
            'sub_total_amount' => isset($row['sub_total_amount']) ? (float) $row['sub_total_amount'] : 0.0,
            'discount_amount' => isset($row['discount_amount']) ? (float) $row['discount_amount'] : 0.0,
            'total_amount' => isset($row['total_amount']) ? (float) $row['total_amount'] : 0.0,
            'status' => (string) ($row['status'] ?? 'created'),
            'invoice_date' => $this->formatDateForTemplate((string) ($row['invoice_date'] ?? '')),
            'due_date' => $this->formatDateForTemplate($dueDateRaw),
            'acceptance_message' => 'Ihre Anfrage wurde angenommen. Mit dieser Mail erhalten Sie die Rechnung zu Ihrem Termin.',
            'payment_notice' => $paymentNotice,
            'tax_exemption_notice' => '',
            'items_html' => $this->loadInvoiceItemsHtml($invoiceId),
            'sent_at' => $this->formatDateTimeForTemplate((string) ($row['sent_at'] ?? '')),
            'created_at' => $this->formatDateTimeForTemplate((string) ($row['created_at'] ?? '')),
            'updated_at' => $this->formatDateTimeForTemplate((string) ($row['updated_at'] ?? '')),
        ];
    }

    private function loadInvoiceItemsHtml(int $invoiceId): string
    {
        if ($invoiceId <= 0 || !$this->isInvoiceTableAvailable()) {
            return '';
        }

        $rows = db('invoice_items')
            ->where('invoice_id', $invoiceId)
            ->orderBy('sort_order', 'asc')
            ->orderBy('id', 'asc')
            ->get();

        if (!is_array($rows) || $rows === []) {
            return '';
        }

        $html = '';
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }

            $description = htmlspecialchars((string) ($row['description'] ?? ''), ENT_QUOTES, 'UTF-8');
            $quantity = $this->formatNumber((float) ($row['quantity'] ?? 1.0));
            $lineTotal = $this->formatMoneyForTemplate((float) ($row['line_total'] ?? 0.0));

            $html .= '<tr>'
                . '<td style="padding:10px 0;border-bottom:1px solid #e7ddd1;color:#3d3127;">' . $description . '</td>'
                . '<td style="padding:10px 0;border-bottom:1px solid #e7ddd1;color:#6a5645;text-align:center;">' . $quantity . '</td>'
                . '<td style="padding:10px 0;border-bottom:1px solid #e7ddd1;color:#3d3127;text-align:right;white-space:nowrap;">' . $lineTotal . ' EUR</td>'
                . '</tr>';
        }

        return $html;
    }

    private function loadLatestInvoiceIdForBooking(int $bookingId): int
    {
        if ($bookingId <= 0 || !$this->isInvoiceTableAvailable()) {
            return 0;
        }

        $query = db('invoices');
        if ($this->isInvoicesBookingIdAvailable()) {
            $query->where('booking_id', $bookingId);
        } elseif ($this->isInvoicesAppointmentIdAvailable()) {
            $query->where('appointment_id', $bookingId);
        } else {
            return 0;
        }

        $row = $query
            ->select(['id'])
            ->orderBy('id', 'desc')
            ->first();

        return is_array($row) ? (int) ($row['id'] ?? 0) : 0;
    }

    private function isInvoiceTableAvailable(): bool
    {
        if ($this->invoiceTableAvailable !== null) {
            return $this->invoiceTableAvailable;
        }

        try {
            $pdo = app(\App\Core\Database\Database::class)->connection();
            $statement = $pdo->prepare(
                'SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = :table_name LIMIT 1'
            );
            $statement->execute(['table_name' => 'invoices']);
            $this->invoiceTableAvailable = $statement->fetchColumn() !== false;
            return $this->invoiceTableAvailable;
        } catch (\Throwable) {
            $this->invoiceTableAvailable = false;
            return false;
        }
    }

    private function isBookingsTableAvailable(): bool
    {
        if ($this->bookingsTableAvailable !== null) {
            return $this->bookingsTableAvailable;
        }

        $this->bookingsTableAvailable = $this->isTableAvailable('bookings');
        return $this->bookingsTableAvailable;
    }

    private function isAppointmentsTableAvailable(): bool
    {
        if ($this->appointmentsTableAvailable !== null) {
            return $this->appointmentsTableAvailable;
        }

        $this->appointmentsTableAvailable = $this->isTableAvailable('appointments');
        return $this->appointmentsTableAvailable;
    }

    private function isAppointmentsColumnAvailable(string $columnName): bool
    {
        if (array_key_exists($columnName, $this->appointmentsColumnAvailability)) {
            return $this->appointmentsColumnAvailability[$columnName];
        }

        if (!$this->isAppointmentsTableAvailable()) {
            $this->appointmentsColumnAvailability[$columnName] = false;
            return false;
        }

        try {
            $pdo = app(\App\Core\Database\Database::class)->connection();
            $statement = $pdo->prepare(
                'SELECT 1 FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = :table_name AND column_name = :column_name LIMIT 1'
            );
            $statement->execute([
                'table_name' => 'appointments',
                'column_name' => $columnName,
            ]);

            $this->appointmentsColumnAvailability[$columnName] = $statement->fetchColumn() !== false;
        } catch (\Throwable) {
            $this->appointmentsColumnAvailability[$columnName] = false;
        }

        return $this->appointmentsColumnAvailability[$columnName];
    }

    private function isInvoicesBookingIdAvailable(): bool
    {
        if ($this->invoicesBookingIdAvailable !== null) {
            return $this->invoicesBookingIdAvailable;
        }

        $this->invoicesBookingIdAvailable = $this->isInvoiceColumnAvailable('booking_id');
        return $this->invoicesBookingIdAvailable;
    }

    private function isInvoicesAppointmentIdAvailable(): bool
    {
        if ($this->invoicesAppointmentIdAvailable !== null) {
            return $this->invoicesAppointmentIdAvailable;
        }

        $this->invoicesAppointmentIdAvailable = $this->isInvoiceColumnAvailable('appointment_id');
        return $this->invoicesAppointmentIdAvailable;
    }

    private function isTableAvailable(string $tableName): bool
    {
        try {
            $pdo = app(\App\Core\Database\Database::class)->connection();
            $statement = $pdo->prepare(
                'SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = :table_name LIMIT 1'
            );
            $statement->execute(['table_name' => $tableName]);
            return $statement->fetchColumn() !== false;
        } catch (\Throwable) {
            return false;
        }
    }

    private function isInvoiceColumnAvailable(string $columnName): bool
    {
        if (!$this->isInvoiceTableAvailable()) {
            return false;
        }

        try {
            $pdo = app(\App\Core\Database\Database::class)->connection();
            $statement = $pdo->prepare(
                'SELECT 1 FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = :table_name AND column_name = :column_name LIMIT 1'
            );
            $statement->execute([
                'table_name' => 'invoices',
                'column_name' => $columnName,
            ]);

            return $statement->fetchColumn() !== false;
        } catch (\Throwable) {
            return false;
        }
    }

    /** @return array<string, mixed> */
    private function loadClientData(int $clientId): array
    {
        $row = null;

        try {
            $row = db('clients')
                ->where('id', $clientId)
                ->select([
                    'id',
                    'first_name',
                    'last_name',
                    'name',
                    'email',
                    'phone',
                    'date_of_birth',
                    'medical_notes',
                    'created_at',
                ])
                ->first();
        } catch (\Throwable) {
            // Backward compatibility for older schemas that only expose a combined name.
            $row = db('clients')
                ->where('id', $clientId)
                ->select([
                    'id',
                    'name',
                    'email',
                    'phone',
                    'created_at',
                ])
                ->first();
        }

        if (!is_array($row)) {
            return [];
        }

        $row = app(ClientFieldEncryptionService::class)->decryptClientRow($row);

        $firstName = trim((string) ($row['first_name'] ?? ''));
        $lastName = trim((string) ($row['last_name'] ?? ''));
        if ($firstName === '' && $lastName === '') {
            $fullName = trim((string) ($row['name'] ?? ''));
            if ($fullName !== '') {
                $parts = preg_split('/\s+/', $fullName) ?: [];
                if ($parts !== []) {
                    $firstName = (string) array_shift($parts);
                    $lastName = trim(implode(' ', $parts));
                }
            }
        }

        return [
            'id' => (int) ($row['id'] ?? 0),
            'first_name' => $firstName,
            'last_name' => $lastName,
            'email' => strtolower(trim((string) ($row['email'] ?? ''))),
            'phone' => (string) ($row['phone'] ?? ''),
            'date_of_birth' => (string) ($row['date_of_birth'] ?? ''),
            'medical_notes' => (string) ($row['medical_notes'] ?? ''),
            'created_at' => $this->formatDateTimeForTemplate((string) ($row['created_at'] ?? '')),
            'updated_at' => $this->formatDateTimeForTemplate((string) ($row['created_at'] ?? '')),
        ];
    }

    /** @return array<string, mixed> */
    private function systemTemplateData(): array
    {
        $now = new DateTimeImmutable('now', new DateTimeZone('Europe/Berlin'));

        return [
            'site_name' => (string) config('app.name', 'Henz Software'),
            'contact_email' => $this->contactMailAddress(),
            'support_email' => $this->supportMailAddress(),
            'profile_image_url' => $this->profileImageUrl(),
            'generated_at' => $now->format('d.m.Y H:i'),
        ];
    }

    private function formatDateTimeForTemplate(string $value): string
    {
        $trimmed = trim($value);
        if ($trimmed === '') {
            return '';
        }

        $dateTime = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $trimmed)
            ?: DateTimeImmutable::createFromFormat('Y-m-d H:i', $trimmed)
            ?: new DateTimeImmutable($trimmed);

        return $dateTime->format('d.m.Y H:i');
    }

    private function formatDateForTemplate(string $value): string
    {
        $trimmed = trim($value);
        if ($trimmed === '') {
            return '';
        }

        $dateTime = DateTimeImmutable::createFromFormat('Y-m-d', $trimmed)
            ?: DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $trimmed)
            ?: new DateTimeImmutable($trimmed);

        return $dateTime->format('d.m.Y');
    }

    private function formatMoneyForTemplate(float $value): string
    {
        return number_format($value, 2, ',', '.');
    }

    private function formatNumber(float $value): string
    {
        if ((float) (int) $value === $value) {
            return (string) (int) $value;
        }

        return number_format($value, 2, ',', '.');
    }

    private function shouldSendWhenAutomationDisabled(string $event, string $templateKey): bool
    {
        if ($event === 'request.submitted' && $templateKey === 'admin_request_info') {
            return true;
        }

        return in_array($event, ['appointment.accepted', 'appointment.rejected', 'appointment.storno', 'appointment.reschedule', 'invoice.created'], true);
    }

    /** @return array<string, mixed> */
    private function sendTemplate(string $event, string $templateKey, string $recipientType, string $senderType, array $context): array
    {
        try {
            $template = db('email_templates')
                ->where('template_key', $templateKey)
                ->select(['template_key', 'display_name', 'subject_template', 'html_template', 'is_active'])
                ->first();

            if (!is_array($template)) {
                $this->logAutomation('warning', 'email.template.skipped', [
                    'event' => $event,
                    'template_key' => $templateKey,
                    'reason' => 'template_missing',
                ]);
                return [
                    'template_key' => $templateKey,
                    'status' => 'skipped',
                    'reason' => 'template_missing',
                ];
            }

            if (!$this->normalizeBool($template['is_active'] ?? false)) {
                $this->logAutomation('warning', 'email.template.skipped', [
                    'event' => $event,
                    'template_key' => $templateKey,
                    'reason' => 'template_inactive',
                ]);
                return [
                    'template_key' => $templateKey,
                    'status' => 'skipped',
                    'reason' => 'template_inactive',
                ];
            }

            $effectiveRecipientType = $recipientType;
            if (in_array($event, ['ticket.opened', 'ticket.closed'], true)) {
                $effectiveRecipientType = 'client';
            }

            $recipients = $this->resolveRecipientAddresses($templateKey, $effectiveRecipientType, $context);
            if ($recipients === []) {
                $this->logAutomation('warning', 'email.template.skipped', [
                    'event' => $event,
                    'template_key' => $templateKey,
                    'reason' => 'recipient_missing',
                    'recipient_type' => $effectiveRecipientType,
                    'client_id' => (int) ($context['client']['id'] ?? 0),
                    'client_email' => $this->maskEmail((string) ($context['client']['email'] ?? '')),
                ]);
                return [
                    'template_key' => $templateKey,
                    'status' => 'skipped',
                    'reason' => 'recipient_missing',
                ];
            }

            $subject = $this->renderTemplate((string) ($template['subject_template'] ?? ''), $context, false);
            $htmlBody = $this->renderTemplate((string) ($template['html_template'] ?? ''), $context, true);
            $deliveryHtml = $this->absolutizeEmailAssetUrls($htmlBody);
            $attachments = $this->resolveAttachmentsForEvent($event, $context);
            $successfulRecipients = [];
            $failedRecipients = [];
            $lastMailResult = ['success' => false, 'error' => 'send_failed', 'transport' => ''];

            foreach ($recipients as $recipient) {
                $mailResult = $this->sendHtmlMail($recipient, $subject, $deliveryHtml, $senderType, $attachments);
                $lastMailResult = $mailResult;

                if ($mailResult['success']) {
                    $successfulRecipients[] = $recipient;
                    $this->logSentEmail($event, $templateKey, $context, $recipient, $subject, $deliveryHtml, $senderType);
                    continue;
                }

                $failedRecipients[] = $recipient . ': ' . (string) ($mailResult['error'] ?? 'send_failed');
            }

            $allSent = count($successfulRecipients) === count($recipients);
            $anySent = $successfulRecipients !== [];

            return [
                'template_key' => $templateKey,
                'display_name' => (string) ($template['display_name'] ?? $templateKey),
                'recipient' => (string) ($successfulRecipients[0] ?? $recipients[0]),
                'recipients' => $recipients,
                'status' => $allSent || $anySent ? 'sent' : 'skipped',
                'transport' => (string) ($lastMailResult['transport'] ?? ''),
                'attachments_count' => count($attachments),
                'reason' => $failedRecipients === [] ? '' : implode(' | ', $failedRecipients),
                'fallback_path' => $lastMailResult['fallback_path'] ?? null,
            ];
        } catch (\Throwable $exception) {
            $this->logAutomation('error', 'email.template.exception', [
                'event' => $event,
                'template_key' => $templateKey,
                'error' => $exception->getMessage(),
            ]);
            return [
                'template_key' => $templateKey,
                'status' => 'skipped',
                'reason' => $exception->getMessage(),
            ];
        }
    }

    /**
     * @param array<string, mixed> $context
     * @return list<string>
     */
    private function resolveRecipientAddresses(string $templateKey, string $recipientType, array $context): array
    {
        if ($recipientType === 'support') {
            $recipients = [$this->supportMailAddress()];

            if ($templateKey === 'admin_request_info') {
                $recipients[] = 'christophhenz@gmail.com';
            }

            return $this->normalizeRecipientList($recipients);
        }

        return $this->normalizeRecipientList([
            strtolower(trim((string) ($context['client']['email'] ?? ''))),
        ]);
    }

    /**
     * @param list<string> $recipients
     * @return list<string>
     */
    private function normalizeRecipientList(array $recipients): array
    {
        $unique = [];

        foreach ($recipients as $recipient) {
            $email = strtolower(trim($recipient));
            if ($email === '' || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
                continue;
            }

            $unique[$email] = true;
        }

        return array_keys($unique);
    }

    /**
     * @param array<string, mixed> $values
     */
    private function renderTemplate(string $template, array $values, bool $escapeForHtml): string
    {
        $flat = $this->flattenTemplateValues($values);
        $replacements = [];

        foreach ($flat as $key => $value) {
            $isTrustedHtml = $escapeForHtml && str_ends_with($key, '_html');
            $safeValue = $isTrustedHtml
                ? (string) $value
                : ($escapeForHtml
                    ? htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8')
                    : (string) $value);
            $replacements['{{' . $key . '}}'] = $safeValue;
            $replacements['{{ ' . $key . ' }}'] = $safeValue;
        }

        $rendered = strtr($template, $replacements);

        // Remove unresolved placeholders so raw {{...}} tokens are never sent to recipients.
        return preg_replace('/\{\{\s*[^{}]+\s*\}\}/', '', $rendered) ?? $rendered;
    }

    /**
     * @param array<string, mixed> $values
     * @return array<string, string>
     */
    private function flattenTemplateValues(array $values, string $prefix = ''): array
    {
        $flat = [];

        foreach ($values as $key => $value) {
            $keyName = trim((string) $key);
            if ($keyName === '') {
                continue;
            }

            $fullKey = $prefix === '' ? $keyName : $prefix . '.' . $keyName;
            if (is_array($value)) {
                $flat[$fullKey] = $this->stringifyTemplateValue($value);
                $flat += $this->flattenTemplateValues($value, $fullKey);
                continue;
            }

            if ($value instanceof DateTimeInterface) {
                $flat[$fullKey] = $value->format(DATE_ATOM);
                continue;
            }

            $flat[$fullKey] = (string) $value;
        }

        return $flat;
    }

    /** @param mixed $value */
    private function stringifyTemplateValue(mixed $value): string
    {
        if (is_array($value)) {
            try {
                return (string) json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            } catch (\Throwable) {
                return '';
            }
        }

        return (string) $value;
    }

    private function formatServiceSlugForEmail(string $serviceSlug): string
    {
        $slug = trim($serviceSlug);
        if ($slug === '') {
            return '';
        }

        try {
            $service = db('services')
                ->where('slug', $slug)
                ->select(['name'])
                ->first();

            $serviceName = trim((string) ($service['name'] ?? ''));
            if ($serviceName !== '') {
                return $serviceName;
            }
        } catch (\Throwable) {
            // Use slug fallback if lookup is unavailable.
        }

        $normalized = str_replace(['-', '_'], ' ', strtolower($slug));
        $normalized = preg_replace('/\s+/', ' ', $normalized) ?? $normalized;
        $normalized = trim($normalized);

        return $normalized !== '' ? ucwords($normalized) : $slug;
    }

    private function profileImageUrl(): string
    {
        return '/storage/media/persistent/email_profile.png';
    }

    private function absolutizeEmailAssetUrls(string $htmlBody): string
    {
        $relative = '/storage/media/persistent/email_profile.png';
        $absolute = $this->publicBaseUrlForEmail() . $relative;

        return str_replace(
            ['src="' . $relative . '"', "src='" . $relative . "'"],
            ['src="' . $absolute . '"', "src='" . $absolute . "'"],
            $htmlBody
        );
    }

    private function publicBaseUrlForEmail(): string
    {
        $env = strtolower(trim((string) config('app.env', 'production')));
        $localEnvs = ['local', 'development', 'dev', 'testing', 'test'];

        if (in_array($env, $localEnvs, true)) {
            return 'http://henz-software.local';
        }

        return 'https://henz-software.de';
    }

    private function supportMailAddress(): string
    {
        $supportSetting = db('settings')
            ->where('`key`', 'support_email')
            ->select(['value'])
            ->first();

        $supportValue = trim((string) ($supportSetting['value'] ?? ''));
        if ($supportValue !== '' && filter_var($supportValue, FILTER_VALIDATE_EMAIL)) {
            return $supportValue;
        }

        $configValue = trim((string) config('mail.senders.support.address', 'support@henz-software.de'));
        if ($configValue !== '' && filter_var($configValue, FILTER_VALIDATE_EMAIL)) {
            return $configValue;
        }

        return 'support@henz-software.de';
    }

    private function contactMailAddress(): string
    {
        $contactSetting = db('settings')
            ->where('`key`', 'contact_email')
            ->select(['value'])
            ->first();

        $contactValue = trim((string) ($contactSetting['value'] ?? ''));
        if ($contactValue !== '' && filter_var($contactValue, FILTER_VALIDATE_EMAIL)) {
            return $contactValue;
        }

        $configValue = trim((string) config('mail.senders.communication.address', 'info@henz-software.de'));
        if ($configValue !== '' && filter_var($configValue, FILTER_VALIDATE_EMAIL)) {
            return $configValue;
        }

        return 'info@henz-software.de';
    }

    private function readSettingValue(string $key, string $fallback = ''): string
    {
        $row = db('settings')
            ->where('`key`', $key)
            ->select(['value'])
            ->first();

        $value = trim((string) ($row['value'] ?? ''));
        return $value !== '' ? $value : $fallback;
    }

    /**
     * @param list<array{filename: string, mime_type: string, content_base64: string}> $attachments
     * @return array{success: bool, error: string, transport: string, fallback_path?: string}
     */
    private function sendHtmlMail(string $to, string $subject, string $htmlBody, string $senderType, array $attachments = []): array
    {
        $transport = strtolower(trim((string) config('mail.transport', 'smtp')));
        $senderConfigKey = 'mail.senders.' . ($senderType !== '' ? $senderType : 'support');
        $fromAddress = trim((string) config($senderConfigKey . '.address', 'support@henz-software.de'));
        if ($fromAddress === '') {
            $fromAddress = 'support@henz-software.de';
        }

        $fromName = trim((string) config($senderConfigKey . '.name', 'Henz Software'));
        $subjectHeader = $subject;
        if (function_exists('mb_encode_mimeheader')) {
            $subjectHeader = mb_encode_mimeheader($subject, 'UTF-8', 'B', "\r\n");
        }

        $mailPayload = $this->buildMailPayload($htmlBody, $attachments);

        $headers = array_merge($mailPayload['headers'], [
            sprintf('From: %s <%s>', $fromName !== '' ? $fromName : 'Support', $fromAddress),
            'X-Mailer: PHP/' . PHP_VERSION,
        ]);

        if ($transport === 'smtp') {
            $smtpResult = $this->sendHtmlMailViaSmtp($to, $subjectHeader, $mailPayload['body'], $headers, $fromAddress);
            if ($smtpResult['success']) {
                return $smtpResult;
            }

            return $this->storeMailFallback(
                $to,
                $subject,
                $mailPayload['body'],
                implode("\r\n", $headers),
                'SMTP send failed: ' . (string) ($smtpResult['error'] ?? 'unknown SMTP error')
            );
        }

        if (!function_exists('mail')) {
            return $this->storeMailFallback($to, $subject, $mailPayload['body'], implode("\r\n", $headers), 'mail() function is unavailable');
        }

        $sent = @mail($to, $subjectHeader, $mailPayload['body'], implode("\r\n", $headers));
        if ($sent) {
            return ['success' => true, 'error' => '', 'transport' => 'mail'];
        }

        $lastError = error_get_last();
        $message = is_array($lastError) ? (string) ($lastError['message'] ?? 'mail() returned false') : 'mail() returned false';

        return $this->storeMailFallback($to, $subject, $mailPayload['body'], implode("\r\n", $headers), $message);
    }

    /** @return array{success: bool, error: string, transport: string} */
    private function sendHtmlMailViaSmtp(string $to, string $subjectHeader, string $mailBody, array $headers, string $fromAddress): array
    {
        $host = trim((string) config('mail.smtp.host', ''));
        $port = (int) config('mail.smtp.port', 587);
        $encryption = strtolower(trim((string) config('mail.smtp.encryption', 'tls')));
        $username = trim((string) config('mail.smtp.username', ''));
        $password = (string) config('mail.smtp.password', '');
        $timeout = (int) config('mail.smtp.timeout_seconds', 10);

        if ($host === '') {
            return ['success' => false, 'error' => 'MAIL_HOST is empty', 'transport' => 'smtp'];
        }

        if ($port <= 0) {
            return ['success' => false, 'error' => 'MAIL_PORT is invalid', 'transport' => 'smtp'];
        }

        $remoteHost = ($encryption === 'ssl' ? 'ssl://' : '') . $host;
        $errno = 0;
        $errstr = '';
        $socket = @fsockopen($remoteHost, $port, $errno, $errstr, max(1, $timeout));
        if ($socket === false) {
            return ['success' => false, 'error' => sprintf('SMTP connect failed (%d): %s', $errno, $errstr), 'transport' => 'smtp'];
        }

        stream_set_timeout($socket, max(1, $timeout));

        $greeting = $this->smtpReadResponse($socket, [220]);
        if (!$greeting['success']) {
            fclose($socket);
            return ['success' => false, 'error' => $greeting['error'], 'transport' => 'smtp'];
        }

        $clientHost = (string) parse_url((string) config('app.url', 'http://localhost'), PHP_URL_HOST);
        if ($clientHost === '') {
            $clientHost = 'localhost';
        }

        $ehlo = $this->smtpSendCommand($socket, 'EHLO ' . $clientHost, [250]);
        if (!$ehlo['success']) {
            $ehlo = $this->smtpSendCommand($socket, 'HELO ' . $clientHost, [250]);
            if (!$ehlo['success']) {
                fclose($socket);
                return ['success' => false, 'error' => $ehlo['error'], 'transport' => 'smtp'];
            }
        }

        if ($encryption === 'tls') {
            $startTls = $this->smtpSendCommand($socket, 'STARTTLS', [220]);
            if (!$startTls['success']) {
                fclose($socket);
                return ['success' => false, 'error' => $startTls['error'], 'transport' => 'smtp'];
            }

            $cryptoEnabled = @stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT);
            if ($cryptoEnabled !== true) {
                fclose($socket);
                return ['success' => false, 'error' => 'STARTTLS negotiation failed', 'transport' => 'smtp'];
            }

            $ehloAfterTls = $this->smtpSendCommand($socket, 'EHLO ' . $clientHost, [250]);
            if (!$ehloAfterTls['success']) {
                fclose($socket);
                return ['success' => false, 'error' => $ehloAfterTls['error'], 'transport' => 'smtp'];
            }
        }

        if ($username !== '' || $password !== '') {
            $authStart = $this->smtpSendCommand($socket, 'AUTH LOGIN', [334]);
            if (!$authStart['success']) {
                fclose($socket);
                return ['success' => false, 'error' => $authStart['error'], 'transport' => 'smtp'];
            }

            $authUser = $this->smtpSendCommand($socket, base64_encode($username), [334]);
            if (!$authUser['success']) {
                fclose($socket);
                return ['success' => false, 'error' => $authUser['error'], 'transport' => 'smtp'];
            }

            $authPass = $this->smtpSendCommand($socket, base64_encode($password), [235]);
            if (!$authPass['success']) {
                fclose($socket);
                return ['success' => false, 'error' => $authPass['error'], 'transport' => 'smtp'];
            }
        }

        $mailFrom = $this->smtpSendCommand($socket, 'MAIL FROM:<' . $fromAddress . '>', [250]);
        if (!$mailFrom['success']) {
            fclose($socket);
            return ['success' => false, 'error' => $mailFrom['error'], 'transport' => 'smtp'];
        }

        $rcptTo = $this->smtpSendCommand($socket, 'RCPT TO:<' . $to . '>', [250, 251]);
        if (!$rcptTo['success']) {
            fclose($socket);
            return ['success' => false, 'error' => $rcptTo['error'], 'transport' => 'smtp'];
        }

        $data = $this->smtpSendCommand($socket, 'DATA', [354]);
        if (!$data['success']) {
            fclose($socket);
            return ['success' => false, 'error' => $data['error'], 'transport' => 'smtp'];
        }

        $toHeader = sprintf('To: <%s>', $to);
        $normalizedBody = str_replace(["\r\n", "\r"], "\n", $mailBody);
        $normalizedBody = str_replace("\n", "\r\n", $normalizedBody);
        $messageData = implode("\r\n", [
            'Date: ' . gmdate('D, d M Y H:i:s') . ' +0000',
            $toHeader,
            'Subject: ' . $subjectHeader,
            ...$headers,
            '',
            $normalizedBody,
        ]);

        $messageData = preg_replace('/(^|\r\n)\./', '$1..', $messageData) ?? $messageData;

        fwrite($socket, $messageData . "\r\n.\r\n");
        $dataSent = $this->smtpReadResponse($socket, [250]);
        if (!$dataSent['success']) {
            fclose($socket);
            return ['success' => false, 'error' => $dataSent['error'], 'transport' => 'smtp'];
        }

        $this->smtpSendCommand($socket, 'QUIT', [221]);
        fclose($socket);

        return ['success' => true, 'error' => '', 'transport' => 'smtp'];
    }

    /**
     * @param list<array{filename: string, mime_type: string, content_base64: string}> $attachments
     * @return array{headers: list<string>, body: string}
     */
    private function buildMailPayload(string $htmlBody, array $attachments = []): array
    {
        $normalizedHtml = str_replace(["\r\n", "\r"], "\n", $htmlBody);
        $normalizedHtml = str_replace("\n", "\r\n", $normalizedHtml);

        if ($attachments !== []) {
            $boundary = 'gb-mix-' . bin2hex(random_bytes(12));
            $body = '';
            $body .= '--' . $boundary . "\r\n";
            $body .= "Content-Type: text/html; charset=UTF-8\r\n";
            $body .= "Content-Transfer-Encoding: 8bit\r\n\r\n";
            $body .= $normalizedHtml . "\r\n";

            foreach ($attachments as $attachment) {
                $fileName = trim((string) ($attachment['filename'] ?? ''));
                $mimeType = trim((string) ($attachment['mime_type'] ?? 'application/octet-stream'));
                $contentBase64 = trim((string) ($attachment['content_base64'] ?? ''));

                if ($fileName === '' || $contentBase64 === '') {
                    continue;
                }

                $safeName = str_replace(['"', "\r", "\n"], '', $fileName);
                $body .= '--' . $boundary . "\r\n";
                $body .= 'Content-Type: ' . $mimeType . '; name="' . $safeName . '"' . "\r\n";
                $body .= "Content-Transfer-Encoding: base64\r\n";
                $body .= 'Content-Disposition: attachment; filename="' . $safeName . '"' . "\r\n\r\n";
                $body .= chunk_split($contentBase64, 76, "\r\n");
            }

            $body .= '--' . $boundary . "--\r\n";

            return [
                'headers' => [
                    'MIME-Version: 1.0',
                    'Content-Type: multipart/mixed; boundary="' . $boundary . '"',
                ],
                'body' => $body,
            ];
        }

        $embedInlineImages = filter_var((string) config('mail.embed_inline_images', false), FILTER_VALIDATE_BOOL);
        if ($embedInlineImages !== true) {
            return [
                'headers' => [
                    'MIME-Version: 1.0',
                    'Content-Type: text/html; charset=UTF-8',
                    'Content-Transfer-Encoding: 8bit',
                ],
                'body' => $normalizedHtml,
            ];
        }

        $profilePath = base_path('storage/media/persistent/email_profile.png');
        if (!is_file($profilePath)) {
            return [
                'headers' => [
                    'MIME-Version: 1.0',
                    'Content-Type: text/html; charset=UTF-8',
                    'Content-Transfer-Encoding: 8bit',
                ],
                'body' => $normalizedHtml,
            ];
        }

        $inlineCid = 'email_profile_img';
        $relativeSrc = '/storage/media/persistent/email_profile.png';
        $absoluteSrc = $this->publicBaseUrlForEmail() . $relativeSrc;
        $htmlWithCid = str_replace(
            ['src="' . $relativeSrc . '"', "src='" . $relativeSrc . "'", 'src="' . $absoluteSrc . '"', "src='" . $absoluteSrc . "'"],
            ['src="cid:' . $inlineCid . '"', "src='cid:" . $inlineCid . "'", 'src="cid:' . $inlineCid . '"', "src='cid:" . $inlineCid . "'"],
            $normalizedHtml
        );

        if ($htmlWithCid === $normalizedHtml) {
            return [
                'headers' => [
                    'MIME-Version: 1.0',
                    'Content-Type: text/html; charset=UTF-8',
                    'Content-Transfer-Encoding: 8bit',
                ],
                'body' => $normalizedHtml,
            ];
        }

        $binary = file_get_contents($profilePath);
        if (!is_string($binary) || $binary === '') {
            return [
                'headers' => [
                    'MIME-Version: 1.0',
                    'Content-Type: text/html; charset=UTF-8',
                    'Content-Transfer-Encoding: 8bit',
                ],
                'body' => $normalizedHtml,
            ];
        }

        $boundary = 'gb-rel-' . bin2hex(random_bytes(12));
        $body = '';
        $body .= '--' . $boundary . "\r\n";
        $body .= "Content-Type: text/html; charset=UTF-8\r\n";
        $body .= "Content-Transfer-Encoding: 8bit\r\n\r\n";
        $body .= $htmlWithCid . "\r\n";
        $body .= '--' . $boundary . "\r\n";
        $body .= "Content-Type: image/png; name=\"email_profile.png\"\r\n";
        $body .= "Content-Transfer-Encoding: base64\r\n";
        $body .= 'Content-ID: <' . $inlineCid . ">\r\n";
        $body .= "Content-Disposition: inline; filename=\"email_profile.png\"\r\n\r\n";
        $body .= chunk_split(base64_encode($binary), 76, "\r\n");
        $body .= '--' . $boundary . "--\r\n";

        return [
            'headers' => [
                'MIME-Version: 1.0',
                'Content-Type: multipart/related; boundary="' . $boundary . '"; type="text/html"',
            ],
            'body' => $body,
        ];
    }

    /**
     * @param array<string, mixed> $context
     * @return list<array{filename: string, mime_type: string, content_base64: string}>
     */
    private function resolveAttachmentsForEvent(string $event, array $context): array
    {
        if ($event !== 'invoice.created') {
            return [];
        }

        $invoiceId = (int) ($context['invoice']['id'] ?? 0);
        if ($invoiceId <= 0) {
            return [];
        }

        $attachment = $this->buildInvoiceAttachment($invoiceId);
        return $attachment !== null ? [$attachment] : [];
    }

    /** @return array{filename: string, mime_type: string, content_base64: string}|null */
    private function buildInvoiceAttachment(int $invoiceId): ?array
    {
        if ($invoiceId <= 0 || !$this->isInvoiceTableAvailable()) {
            return null;
        }

        try {
            $invoice = db('invoices')
                ->where('id', $invoiceId)
                ->first();

            if (!is_array($invoice)) {
                return null;
            }

            $relativePath = trim((string) ($invoice['pdf_path'] ?? ''));
            if ($relativePath === '') {
                $pdfMeta = app(InvoicePdfService::class)->generateForInvoice($invoiceId);
                $relativePath = trim((string) ($pdfMeta['relative_path'] ?? ''));

                if ($relativePath !== '' && $this->isInvoicePdfPathColumnAvailable()) {
                    db('invoices')->where('id', $invoiceId)->update([
                        'pdf_path' => $relativePath,
                        'pdf_mime_type' => (string) ($pdfMeta['mime_type'] ?? 'application/pdf'),
                        'pdf_file_size' => (int) ($pdfMeta['file_size'] ?? 0),
                        'pdf_sha256' => (string) ($pdfMeta['sha256'] ?? ''),
                        'pdf_generated_at' => (string) ($pdfMeta['generated_at'] ?? date('Y-m-d H:i:s')),
                    ]);
                }

                $invoice['pdf_mime_type'] = (string) ($pdfMeta['mime_type'] ?? 'application/pdf');
            }

            if ($relativePath === '') {
                return null;
            }

            $absolutePath = $this->resolveInvoicePdfAbsolutePath($relativePath);
            if ($absolutePath === '' || !is_file($absolutePath)) {
                return null;
            }

            $binary = file_get_contents($absolutePath);
            if (!is_string($binary) || $binary === '') {
                return null;
            }

            $invoiceNumber = (int) ($invoice['invoice_number'] ?? $invoiceId);
            $mimeType = trim((string) ($invoice['pdf_mime_type'] ?? 'application/pdf'));
            if ($mimeType === '') {
                $mimeType = 'application/pdf';
            }

            return [
                'filename' => 'Rechnung-' . $invoiceNumber . '.pdf',
                'mime_type' => $mimeType,
                'content_base64' => base64_encode($binary),
            ];
        } catch (\Throwable $exception) {
            $this->logAutomation('warning', 'email.invoice_attachment.failed', [
                'invoice_id' => $invoiceId,
                'error' => $exception->getMessage(),
            ]);

            return null;
        }
    }

    private function resolveInvoicePdfAbsolutePath(string $relativePath): string
    {
        $relativePath = trim($relativePath);
        if ($relativePath === '') {
            return '';
        }

        $normalized = ltrim($relativePath, '/');
        $candidates = [
            base_path('storage/media/' . $normalized),
            base_path('storage/media/invoices/' . $normalized),
        ];

        foreach ($candidates as $candidate) {
            if (is_file($candidate)) {
                return $candidate;
            }
        }

        return $candidates[0];
    }

    private function isInvoicePdfPathColumnAvailable(): bool
    {
        if ($this->invoicePdfPathColumnAvailable !== null) {
            return $this->invoicePdfPathColumnAvailable;
        }

        $this->invoicePdfPathColumnAvailable = $this->isInvoiceColumnAvailable('pdf_path');
        return $this->invoicePdfPathColumnAvailable;
    }

    /** @param list<int> $expectedCodes @return array{success: bool, error: string, response: string, code: int} */
    private function smtpSendCommand(mixed $socket, string $command, array $expectedCodes): array
    {
        fwrite($socket, $command . "\r\n");
        return $this->smtpReadResponse($socket, $expectedCodes, $command);
    }

    /** @param list<int> $expectedCodes @return array{success: bool, error: string, response: string, code: int} */
    private function smtpReadResponse(mixed $socket, array $expectedCodes, string $command = ''): array
    {
        $response = '';
        $code = 0;

        for ($i = 0; $i < 30; $i++) {
            $line = fgets($socket, 515);
            if ($line === false) {
                return [
                    'success' => false,
                    'error' => 'SMTP read failed after command: ' . $command,
                    'response' => trim($response),
                    'code' => $code,
                ];
            }

            $response .= $line;
            if (preg_match('/^(\d{3})([\s-])/', $line, $matches) === 1) {
                $code = (int) $matches[1];
                if (($matches[2] ?? '-') === ' ') {
                    break;
                }
            }
        }

        if ($code === 0 || !in_array($code, $expectedCodes, true)) {
            return [
                'success' => false,
                'error' => sprintf('SMTP unexpected response %d after "%s": %s', $code, $command, trim($response)),
                'response' => trim($response),
                'code' => $code,
            ];
        }

        return [
            'success' => true,
            'error' => '',
            'response' => trim($response),
            'code' => $code,
        ];
    }

    /** @return array{success: bool, error: string, transport: string, fallback_path?: string} */
    private function storeMailFallback(string $to, string $subject, string $htmlBody, string $headers, string $reason): array
    {
        $dir = base_path('storage/logs/mail-fallback');

        try {
            if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
                return ['success' => false, 'error' => 'Konnte Mail-Fallback-Verzeichnis nicht erstellen.', 'transport' => 'file'];
            }

            $random = bin2hex(random_bytes(4));
            $filename = sprintf('mail_%s_%s.eml', date('Ymd_His'), $random);
            $path = $dir . DIRECTORY_SEPARATOR . $filename;
            $eml = implode("\r\n", [
                'To: ' . $to,
                'Subject: ' . $subject,
                $headers,
                'X-Fallback-Reason: ' . $reason,
                '',
                $htmlBody,
            ]);

            $written = file_put_contents($path, $eml, LOCK_EX);
            if ($written === false) {
                return ['success' => false, 'error' => 'Konnte Fallback-Datei nicht schreiben.', 'transport' => 'file'];
            }

            return [
                'success' => true,
                'error' => '',
                'transport' => 'file',
                'fallback_path' => 'storage/logs/mail-fallback/' . $filename,
            ];
        } catch (\Throwable $exception) {
            return ['success' => false, 'error' => 'Fallback fehlgeschlagen: ' . $exception->getMessage(), 'transport' => 'file'];
        }
    }

    private function normalizeBool(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        if (is_numeric($value)) {
            return (int) $value !== 0;
        }

        $text = strtolower(trim((string) $value));
        return in_array($text, ['1', 'true', 'yes', 'on'], true);
    }

    private function maskEmail(string $email): string
    {
        $normalized = strtolower(trim($email));
        if ($normalized === '' || strpos($normalized, '@') === false) {
            return '';
        }

        [$local, $domain] = explode('@', $normalized, 2);
        if ($local === '') {
            return '***@' . $domain;
        }

        $prefix = substr($local, 0, min(2, strlen($local)));
        return $prefix . '***@' . $domain;
    }

    /** @param array<string, mixed> $context */
    private function logAutomation(string $level, string $message, array $context = []): void
    {
        try {
            $logger = app(Logger::class);
            if (!$logger instanceof Logger) {
                return;
            }

            if ($level === 'error') {
                $logger->error($message, $context);
                return;
            }

            if ($level === 'warning') {
                $logger->warning($message, $context);
                return;
            }

            $logger->info($message, $context);
        } catch (\Throwable) {
            // Debug logging must never interrupt delivery flow.
        }
    }

    /** @param array<string, mixed> $context */
    private function logSentEmail(
        string $triggerEvent,
        string $templateKey,
        array $context,
        string $recipient,
        string $subject,
        string $bodyHtml,
        string $senderType
    ): void {
        try {
            $privacy = app(EmailLogPrivacyService::class);
            $clientId = isset($context['client']['id']) ? (int) $context['client']['id'] : 0;
            $senderAddress = $senderType === 'communication'
                ? $this->contactMailAddress()
                : $this->supportMailAddress();

            $payload = [
                'trigger_event' => $triggerEvent,
                'template_key' => $templateKey,
                'client_id' => $clientId > 0 ? $clientId : null,
                'recipient_email' => $privacy->maskAddress($recipient),
                'subject' => (string) ($privacy->encryptText($subject) ?? ''),
                'body_html' => (string) ($privacy->encryptText($bodyHtml) ?? ''),
                'sent_at' => date('Y-m-d H:i:s'),
            ];

            if ($privacy->hasColumn('client_ref_hash')) {
                $payload['client_ref_hash'] = $privacy->clientRefHash($clientId);
                $payload['client_id'] = null;
            }

            if ($privacy->hasColumn('recipient_email_encrypted')) {
                $payload['recipient_email_encrypted'] = $privacy->encryptAddress($recipient);
            }

            if ($privacy->hasColumn('sender_email')) {
                $payload['sender_email'] = $privacy->maskAddress($senderAddress);
            }

            if ($privacy->hasColumn('sender_email_encrypted')) {
                $payload['sender_email_encrypted'] = $privacy->encryptAddress($senderAddress);
            }

            db('email_logs')->insert($payload);
        } catch (\Throwable) {
            // Logging must never break the functional flow.
        }
    }
}