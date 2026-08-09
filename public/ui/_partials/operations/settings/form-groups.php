<?php

declare(strict_types=1);

$settingsByGroup = is_array($settingsByGroup ?? null) ? $settingsByGroup : [];
$settingsConfig = is_array($settingsConfig ?? null) ? $settingsConfig : [];
$csrfToken = (string) ($csrfToken ?? '');

$groupOrder = is_array($settingsConfig['group_order'] ?? null) ? $settingsConfig['group_order'] : [];
$groupLabels = is_array($settingsConfig['group_labels'] ?? null) ? $settingsConfig['group_labels'] : [];
$fieldMeta = is_array($settingsConfig['field_meta'] ?? null) ? $settingsConfig['field_meta'] : [];

$orderedGroups = [];
$seen = [];

foreach ($groupOrder as $groupKey) {
    $group = trim((string) $groupKey);
    if ($group === '' || !isset($settingsByGroup[$group]) || !is_array($settingsByGroup[$group])) {
        continue;
    }

    $orderedGroups[$group] = $settingsByGroup[$group];
    $seen[$group] = true;
}

foreach ($settingsByGroup as $group => $rows) {
    $groupKey = (string) $group;
    if ($groupKey === '' || isset($seen[$groupKey]) || !is_array($rows)) {
        continue;
    }

    $orderedGroups[$groupKey] = $rows;
}

$renderField = static function (array $row, array $meta): string {
    $key = (string) ($row['key'] ?? '');
    $type = strtolower((string) ($row['type'] ?? 'string'));
    $value = (string) ($row['value'] ?? '');
    $inputType = strtolower((string) ($meta['input_type'] ?? ''));
    $placeholder = (string) ($meta['placeholder'] ?? '');

    if ($inputType === '') {
        $inputType = match ($type) {
            'boolean' => 'checkbox',
            'integer' => 'number',
            'json' => 'textarea',
            default => 'text',
        };
    }

    $id = 'setting_' . preg_replace('/[^a-zA-Z0-9_\-]+/', '_', $key);
    $id = is_string($id) ? $id : ('setting_' . $key);

    $classes = 'w-full rounded-lg border border-border bg-input-background px-3 py-2 text-sm text-foreground outline-none transition focus:border-primary/45 focus:ring-2 focus:ring-primary/20';

    if ($inputType === 'checkbox') {
        $checked = ($value === '1' || strtolower($value) === 'true') ? ' checked' : '';

        return '<label class="inline-flex items-center gap-2">'
            . '<input id="' . htmlspecialchars($id, ENT_QUOTES, 'UTF-8') . '" name="' . htmlspecialchars($key, ENT_QUOTES, 'UTF-8') . '" type="checkbox" value="1" class="h-4 w-4 rounded border-border bg-input-background text-primary focus:ring-primary/25"' . $checked . ' />'
            . '<span class="text-xs text-muted-foreground">Aktiv</span>'
            . '</label>';
    }

    if ($inputType === 'textarea') {
        return '<textarea id="' . htmlspecialchars($id, ENT_QUOTES, 'UTF-8') . '" name="' . htmlspecialchars($key, ENT_QUOTES, 'UTF-8') . '" rows="5" class="' . $classes . '"'
            . ($placeholder !== '' ? ' placeholder="' . htmlspecialchars($placeholder, ENT_QUOTES, 'UTF-8') . '"' : '')
            . '>' . htmlspecialchars($value, ENT_QUOTES, 'UTF-8') . '</textarea>';
    }

    $min = $meta['min'] ?? null;
    $max = $meta['max'] ?? null;

    $attrs = '';
    if ($inputType === 'number' && is_numeric($min)) {
        $attrs .= ' min="' . htmlspecialchars((string) $min, ENT_QUOTES, 'UTF-8') . '"';
    }
    if ($inputType === 'number' && is_numeric($max)) {
        $attrs .= ' max="' . htmlspecialchars((string) $max, ENT_QUOTES, 'UTF-8') . '"';
    }

    $allowedInputTypes = ['text', 'email', 'tel', 'url', 'number'];
    if (!in_array($inputType, $allowedInputTypes, true)) {
        $inputType = 'text';
    }

    return '<input id="' . htmlspecialchars($id, ENT_QUOTES, 'UTF-8') . '" name="' . htmlspecialchars($key, ENT_QUOTES, 'UTF-8') . '" type="' . htmlspecialchars($inputType, ENT_QUOTES, 'UTF-8') . '" value="' . htmlspecialchars($value, ENT_QUOTES, 'UTF-8') . '" class="' . $classes . '"'
        . ($placeholder !== '' ? ' placeholder="' . htmlspecialchars($placeholder, ENT_QUOTES, 'UTF-8') . '"' : '')
        . $attrs
        . ' />';
};

if ($orderedGroups === []):
?>
<p class="rounded-xl border border-border bg-card px-4 py-3 text-sm text-muted-foreground">Keine Einstellungen gefunden.</p>
<?php
    return;
endif;
?>

<form method="post" action="/settings" class="space-y-6">
    <input type="hidden" name="_csrf" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8'); ?>" />

    <?php foreach ($orderedGroups as $groupKey => $rows): ?>
        <?php
        $label = trim((string) ($groupLabels[$groupKey] ?? $groupKey));
        $groupId = 'settings-group-' . preg_replace('/[^a-zA-Z0-9_\-]+/', '-', (string) $groupKey);
        $groupId = is_string($groupId) ? $groupId : ('settings-group-' . (string) $groupKey);
        ?>
        <section class="overflow-hidden rounded-xl border border-border bg-card">
            <header class="border-b border-border px-4 py-3 sm:px-6">
                <h2 id="<?= htmlspecialchars($groupId, ENT_QUOTES, 'UTF-8'); ?>" class="text-sm font-semibold text-foreground">
                    <?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8'); ?>
                </h2>
            </header>

            <div class="overflow-x-auto">
                <table class="min-w-full">
                    <thead>
                    <tr class="border-b border-border bg-input-background">
                        <th class="px-4 py-3 text-left text-xs uppercase tracking-wider text-muted-foreground sm:px-6" style="font-family: 'JetBrains Mono', monospace;">Setting</th>
                        <th class="px-4 py-3 text-left text-xs uppercase tracking-wider text-muted-foreground sm:px-6" style="font-family: 'JetBrains Mono', monospace;">Wert</th>
                        <!--th class="px-4 py-3 text-left text-xs uppercase tracking-wider text-muted-foreground sm:px-6" style="font-family: 'JetBrains Mono', monospace;">Hinweis</th-->
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($rows as $row): ?>
                        <?php
                        if (!is_array($row)) {
                            continue;
                        }

                        $key = (string) ($row['key'] ?? '');
                        if ($key === '') {
                            continue;
                        }

                        $meta = is_array($fieldMeta[$key] ?? null) ? $fieldMeta[$key] : [];
                        $fieldLabel = trim((string) ($meta['label'] ?? ''));
                        if ($fieldLabel === '') {
                            $fieldLabel = trim((string) ($row['description'] ?? ''));
                        }
                        if ($fieldLabel === '') {
                            $fieldLabel = $key;
                        }

                        $hintParts = [];
                        $type = strtolower((string) ($row['type'] ?? 'string'));
                        $hintParts[] = 'Typ: ' . $type;

                        $minPermissionSum = (int) ($row['min_permission_sum'] ?? 0);
                        if ($minPermissionSum > 0) {
                            $hintParts[] = 'min_permission_sum: ' . $minPermissionSum;
                        }

                        $hint = implode(' • ', $hintParts);
                        ?>
                        <tr class="border-b border-border/60 align-top last:border-b-0">
                            <td class="px-4 py-4 sm:px-6">
                                <div class="space-y-1">
                                    <div class="text-sm font-medium text-foreground"><?= htmlspecialchars($fieldLabel, ENT_QUOTES, 'UTF-8'); ?></div>
                                    <div class="text-xs text-muted-foreground" style="font-family: 'JetBrains Mono', monospace;">
                                        <?= htmlspecialchars($key, ENT_QUOTES, 'UTF-8'); ?>
                                    </div>
                                </div>
                            </td>
                            <td class="px-4 py-4 sm:px-6">
                                <?= $renderField($row, $meta); ?>
                            </td>
                            <!--td class="px-4 py-4 sm:px-6">
                                <div class="text-xs text-muted-foreground leading-relaxed" style="font-family: 'JetBrains Mono', monospace;">
                                    <?= htmlspecialchars($hint, ENT_QUOTES, 'UTF-8'); ?>
                                </div>
                            </td-->
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </section>
    <?php endforeach; ?>

    <div class="sticky bottom-0 z-10 border-t border-border bg-background/90 px-4 py-4 backdrop-blur sm:px-6">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <p class="text-xs text-muted-foreground" style="font-family: 'JetBrains Mono', monospace;">
                Änderungen werden direkt in der Settings-Tabelle gespeichert.
            </p>
            <button type="submit" class="inline-flex items-center rounded-lg bg-primary px-4 py-2 text-sm font-semibold text-primary-foreground transition hover:brightness-110">
                Einstellungen speichern
            </button>
        </div>
    </div>
</form>
