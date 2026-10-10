-- Seed: Email templates for automation events
-- Uses Tailwind utility classes in html_template.

INSERT INTO email_templates (template_key, display_name, subject_template, html_template, is_active)
VALUES
(
  'request_confirmation',
  'Anfragebestaetigung (Kundin/Kunde)',
  'Wir haben deine Anfrage erhalten - {{system.site_name}}',
  '<!doctype html><html lang="de"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0"><script src="https://cdn.tailwindcss.com"></script></head><body class="bg-stone-100 py-10"><div class="mx-auto max-w-2xl rounded-2xl border border-stone-200 bg-white shadow-sm"><div class="border-b border-stone-200 px-8 py-6"><p class="text-xs font-semibold uppercase tracking-[0.2em] text-teal-700">{{system.site_name}}</p><h1 class="mt-2 text-2xl font-bold text-stone-900">Danke für deine Anfrage</h1><p class="mt-2 text-sm text-stone-600">Hallo {{client.first_name}} {{client.last_name}}, wir haben deine Anfrage erfolgreich erhalten.</p></div><div class="px-8 py-6"><div class="rounded-xl bg-stone-50 p-5"><h2 class="text-sm font-semibold uppercase tracking-wide text-stone-700">Anfragedetails</h2><p class="mt-3 text-sm text-stone-700"><span class="font-medium text-stone-900">Anfrageart:</span> {{request.type_label}}</p>{{request.customer_details_html}}</div><p class="mt-6 text-sm leading-6 text-stone-700">Wir melden uns zeitnah mit einem Terminvorschlag bei dir.</p></div><div class="border-t border-stone-200 bg-stone-50 px-8 py-5 text-xs text-stone-600">Support: {{system.support_email}} | Kontakt: {{system.contact_email}}</div></div></body></html>',
  1
),
(
  'admin_request_info',
  'Neue Anfrage (Admin)',
  'Neue Anfrage eingegangen: {{client.first_name}} {{client.last_name}}',
  '<!doctype html><html lang="de"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0"><script src="https://cdn.tailwindcss.com"></script></head><body class="bg-slate-100 py-10"><div class="mx-auto max-w-2xl rounded-2xl border border-slate-200 bg-white shadow-sm"><div class="border-b border-slate-200 px-8 py-6"><p class="text-xs font-semibold uppercase tracking-[0.2em] text-indigo-700">Admin Notification</p><h1 class="mt-2 text-2xl font-bold text-slate-900">Neue Anfrage</h1><p class="mt-2 text-sm text-slate-600">Eine neue Anfrage wurde ueber das Formular eingereicht.</p></div><div class="grid gap-4 px-8 py-6 md:grid-cols-2"><div class="rounded-xl bg-slate-50 p-4"><h2 class="text-sm font-semibold text-slate-900">Kundendaten</h2><p class="mt-2 text-sm text-slate-700">{{client.first_name}} {{client.last_name}}</p><p class="text-sm text-slate-700">{{client.email}}</p><p class="text-sm text-slate-700">{{client.phone}}</p></div><div class="rounded-xl bg-slate-50 p-4"><h2 class="text-sm font-semibold text-slate-900">Anfrage</h2><p class="mt-2 text-sm text-slate-700"><span class="font-medium text-slate-900">Anfrageart:</span> {{request.type_label}}</p>{{request.admin_details_html}}</div></div><div class="border-t border-slate-200 bg-slate-50 px-8 py-5 text-xs text-slate-600">Generiert: {{system.generated_at}}</div></div></body></html>',
  1
),
(
  'appointment_accepted',
  'Terminvorschlag angenommen',
  'Dein Termin ist angenommen',
  '<!doctype html><html lang="de"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0"><script src="https://cdn.tailwindcss.com"></script></head><body class="bg-emerald-50 py-10"><div class="mx-auto max-w-2xl rounded-2xl border border-emerald-200 bg-white shadow-sm"><div class="px-8 py-6"><h1 class="text-2xl font-bold text-emerald-900">Termin bestaetigt</h1><p class="mt-2 text-sm text-stone-700">Hallo {{client.first_name}}, dein Termin wurde angenommen.</p><div class="mt-5 rounded-xl bg-emerald-50 p-4 text-sm text-stone-800"><p><span class="font-semibold">Termin:</span> {{booking.scheduled_at}}</p><p><span class="font-semibold">Status:</span> {{booking.status}}</p></div></div><div class="border-t border-emerald-100 px-8 py-5 text-xs text-stone-600">{{system.site_name}} | {{system.contact_email}}</div></div></body></html>',
  1
),
(
  'appointment_rejected',
  'Terminvorschlag abgelehnt',
  'Dein Terminvorschlag wurde abgelehnt',
  '<!doctype html><html lang="de"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0"><script src="https://cdn.tailwindcss.com"></script></head><body class="bg-amber-50 py-10"><div class="mx-auto max-w-2xl rounded-2xl border border-amber-200 bg-white shadow-sm"><div class="px-8 py-6"><h1 class="text-2xl font-bold text-amber-900">Termin aktuell nicht moeglich</h1><p class="mt-2 text-sm text-stone-700">Hallo {{client.first_name}}, der vorgeschlagene Termin konnte leider nicht angenommen werden.</p><p class="mt-3 text-sm text-stone-700">Wir melden uns mit einer Alternative.</p></div><div class="border-t border-amber-100 px-8 py-5 text-xs text-stone-600">{{system.support_email}}</div></div></body></html>',
  1
),
(
  'appointment_storno',
  'Termin storniert (Storno)',
  'Dein Termin wurde storniert',
  '<!doctype html><html lang="de"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0"><script src="https://cdn.tailwindcss.com"></script></head><body class="bg-rose-50 py-10"><div class="mx-auto max-w-2xl rounded-2xl border border-rose-200 bg-white shadow-sm"><div class="px-8 py-6"><h1 class="text-2xl font-bold text-rose-900">Termin storniert</h1><p class="mt-2 text-sm text-stone-700">Hallo {{client.first_name}}, dein Termin wurde storniert.</p><div class="mt-4 rounded-xl bg-rose-50 p-4 text-sm text-stone-800"><p><span class="font-semibold">Termin:</span> {{booking.scheduled_at}}</p><p><span class="font-semibold">Grund:</span> {{booking.cancellation_reason}}</p></div></div><div class="border-t border-rose-100 px-8 py-5 text-xs text-stone-600">{{system.contact_email}}</div></div></body></html>',
  1
),
(
  'appointment_reschedule',
  'Termin umgebucht',
  'Dein Termin wurde umgebucht',
  '<!doctype html><html lang="de"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0"><script src="https://cdn.tailwindcss.com"></script></head><body class="bg-sky-50 py-10"><div class="mx-auto max-w-2xl rounded-2xl border border-sky-200 bg-white shadow-sm"><div class="px-8 py-6"><h1 class="text-2xl font-bold text-sky-900">Termin umgebucht</h1><p class="mt-2 text-sm text-stone-700">Hallo {{client.first_name}}, dein Termin wurde neu geplant.</p><div class="mt-4 rounded-xl bg-sky-50 p-4 text-sm text-stone-800"><p><span class="font-semibold">Neuer Termin:</span> {{booking.scheduled_at}}</p><p><span class="font-semibold">Dauer:</span> {{booking.duration_minutes}} Minuten</p></div></div><div class="border-t border-sky-100 px-8 py-5 text-xs text-stone-600">{{system.site_name}} | {{system.contact_email}}</div></div></body></html>',
  1
),
(
  'appointment_cancelled',
  'Termin storniert',
  'Dein Termin wurde storniert',
  '<!doctype html><html lang="de"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0"><script src="https://cdn.tailwindcss.com"></script></head><body class="bg-rose-50 py-10"><div class="mx-auto max-w-2xl rounded-2xl border border-rose-200 bg-white shadow-sm"><div class="px-8 py-6"><h1 class="text-2xl font-bold text-rose-900">Termin storniert</h1><p class="mt-2 text-sm text-stone-700">Hallo {{client.first_name}}, dein Termin wurde storniert.</p><div class="mt-4 rounded-xl bg-rose-50 p-4 text-sm text-stone-800"><p><span class="font-semibold">Termin:</span> {{booking.scheduled_at}}</p><p><span class="font-semibold">Grund:</span> {{booking.cancellation_reason}}</p></div></div><div class="border-t border-rose-100 px-8 py-5 text-xs text-stone-600">{{system.contact_email}}</div></div></body></html>',
  1
),
(
  'appointment_no_show',
  'Termin nicht wahrgenommen',
  'Hinweis zu deinem ausgefallenen Termin',
  '<!doctype html><html lang="de"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0"><script src="https://cdn.tailwindcss.com"></script></head><body class="bg-orange-50 py-10"><div class="mx-auto max-w-2xl rounded-2xl border border-orange-200 bg-white shadow-sm"><div class="px-8 py-6"><h1 class="text-2xl font-bold text-orange-900">Termin nicht wahrgenommen</h1><p class="mt-2 text-sm text-stone-700">Wir konnten dich zu deinem Termin nicht erreichen.</p><p class="mt-3 text-sm text-stone-700">Bitte melde dich für eine neue Terminabstimmung.</p></div><div class="border-t border-orange-100 px-8 py-5 text-xs text-stone-600">{{system.support_email}}</div></div></body></html>',
  1
),
(
  'ticket_opened',
  'Ticket geoeffnet',
  'Dein Ticket ist jetzt in Bearbeitung',
  '<!doctype html><html lang="de"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0"><script src="https://cdn.tailwindcss.com"></script></head><body class="bg-sky-50 py-10"><div class="mx-auto max-w-2xl rounded-2xl border border-sky-200 bg-white shadow-sm"><div class="px-8 py-6"><h1 class="text-2xl font-bold text-sky-900">Ticket geoeffnet</h1><p class="mt-2 text-sm text-stone-700">Hallo {{client.first_name}}, dein Anliegen wird jetzt aktiv bearbeitet.</p><p class="mt-3 text-sm text-stone-700">Bei Rueckfragen antworte einfach auf diese E-Mail.</p></div><div class="border-t border-sky-100 px-8 py-5 text-xs text-stone-600">{{system.site_name}} | {{system.support_email}}</div></div></body></html>',
  1
),
(
  'ticket_closed',
  'Ticket abgeschlossen',
  'Dein Ticket wurde abgeschlossen',
  '<!doctype html><html lang="de"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0"><script src="https://cdn.tailwindcss.com"></script></head><body class="bg-teal-50 py-10"><div class="mx-auto max-w-2xl rounded-2xl border border-teal-200 bg-white shadow-sm"><div class="px-8 py-6"><h1 class="text-2xl font-bold text-teal-900">Ticket abgeschlossen</h1><p class="mt-2 text-sm text-stone-700">Hallo {{client.first_name}}, dein Ticket wurde als geloest markiert.</p><p class="mt-3 text-sm text-stone-700">Falls noch etwas offen ist, antworte direkt auf diese Nachricht.</p></div><div class="border-t border-teal-100 px-8 py-5 text-xs text-stone-600">{{system.support_email}}</div></div></body></html>',
  1
),
(
  'invoice_created',
  'Rechnung erstellt',
  'Deine Rechnung ist da',
  '<!doctype html><html lang="de"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0"><script src="https://cdn.tailwindcss.com"></script></head><body class="bg-zinc-100 py-10"><div class="mx-auto max-w-2xl rounded-2xl border border-zinc-200 bg-white shadow-sm"><div class="border-b border-zinc-200 px-8 py-6"><h1 class="text-2xl font-bold text-zinc-900">Deine Rechnung</h1><p class="mt-2 text-sm text-zinc-600">Hallo {{client.first_name}}, deine Rechnung wurde erstellt.</p></div><div class="px-8 py-6"><p class="text-sm leading-6 text-zinc-700">Du findest die Rechnung als PDF-Anhang in dieser E-Mail.</p><p class="mt-4 text-sm leading-6 text-zinc-700">Bei Fragen antworte gerne direkt auf diese Nachricht.</p></div><div class="border-t border-zinc-200 bg-zinc-50 px-8 py-5 text-xs text-zinc-600">{{system.site_name}} | {{system.contact_email}}</div></div></body></html>',
  1
),
(
  'payment_received',
  'Zahlung eingegangen',
  'Zahlung bestaetigt für Rechnung #{{invoice.invoice_number}}',
  '<!doctype html><html lang="de"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0"><script src="https://cdn.tailwindcss.com"></script></head><body class="bg-emerald-50 py-10"><div class="mx-auto max-w-2xl rounded-2xl border border-emerald-200 bg-white shadow-sm"><div class="px-8 py-6"><h1 class="text-2xl font-bold text-emerald-900">Zahlung eingegangen</h1><p class="mt-2 text-sm text-stone-700">Vielen Dank, wir haben deine Zahlung erhalten.</p><div class="mt-5 rounded-xl bg-emerald-50 p-4 text-sm text-stone-800"><p><span class="font-semibold">Rechnung:</span> #{{invoice.invoice_number}}</p><p><span class="font-semibold">Betrag:</span> {{invoice.total_amount}} {{invoice.currency_code}}</p><p><span class="font-semibold">Status:</span> bezahlt</p></div></div><div class="border-t border-emerald-100 px-8 py-5 text-xs text-stone-600">{{system.contact_email}}</div></div></body></html>',
  1
),
(
  'payment_reminder_1',
  'Zahlungserinnerung 1',
  'Freundliche Erinnerung: Rechnung #{{invoice.invoice_number}}',
  '<!doctype html><html lang="de"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0"><script src="https://cdn.tailwindcss.com"></script></head><body class="bg-amber-50 py-10"><div class="mx-auto max-w-2xl rounded-2xl border border-amber-200 bg-white shadow-sm"><div class="px-8 py-6"><h1 class="text-2xl font-bold text-amber-900">Freundliche Zahlungserinnerung</h1><p class="mt-2 text-sm text-stone-700">Bitte begleiche die Rechnung #{{invoice.invoice_number}} bis zum Faelligkeitsdatum {{invoice.due_date}}.</p><p class="mt-3 text-sm text-stone-700">Offener Betrag: <span class="font-semibold">{{invoice.total_amount}} {{invoice.currency_code}}</span></p><div class="mt-5">{{payment.summary_html}}</div></div><div class="border-t border-amber-100 px-8 py-5 text-xs text-stone-600">{{system.support_email}}</div></div></body></html>',
  1
),
(
  'payment_reminder_2',
  'Zahlungserinnerung 2',
  'Letzte Erinnerung: Rechnung #{{invoice.invoice_number}}',
  '<!doctype html><html lang="de"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0"><script src="https://cdn.tailwindcss.com"></script></head><body class="bg-rose-50 py-10"><div class="mx-auto max-w-2xl rounded-2xl border border-rose-200 bg-white shadow-sm"><div class="px-8 py-6"><h1 class="text-2xl font-bold text-rose-900">Letzte Zahlungserinnerung</h1><p class="mt-2 text-sm text-stone-700">Die Rechnung #{{invoice.invoice_number}} ist weiterhin offen.</p><p class="mt-3 text-sm text-stone-700">Bitte ueberweise <span class="font-semibold">{{invoice.total_amount}} {{invoice.currency_code}}</span> umgehend.</p><div class="mt-5">{{payment.summary_html}}</div></div><div class="border-t border-rose-100 px-8 py-5 text-xs text-stone-600">Bei Rueckfragen: {{system.support_email}}</div></div></body></html>',
  1
)
ON DUPLICATE KEY UPDATE
  display_name = VALUES(display_name),
  subject_template = VALUES(subject_template),
  html_template = VALUES(html_template),
  is_active = VALUES(is_active),
  updated_at = CURRENT_TIMESTAMP;
