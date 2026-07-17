<?php

declare(strict_types=1);

$projectRows = is_array($projectRows ?? null) ? $projectRows : [];
$canViewProjects = (bool) ($canViewProjects ?? false);
?>
<section class="overflow-hidden rounded-xl border border-[#00c8ff]/10 bg-[#0c1520]">
    <div class="flex items-center justify-between border-b border-[#00c8ff]/10 px-6 py-4">
        <h2 class="text-sm font-semibold text-[#e8edf5]">Aktuelle Projekte</h2>
        <?php if ($canViewProjects): ?>
            <a href="/projects"
                class="rounded-lg border border-[#5a7494]/30 px-3 py-1.5 text-xs text-[#5a7494] transition hover:border-[#00c8ff]/40 hover:text-[#00c8ff]"
                style="font-family: 'JetBrains Mono', monospace;">
                Alle anzeigen
            </a>
        <?php endif; ?>
    </div>

    <?php if (!$canViewProjects): ?>
        <p class="m-6 rounded-lg border border-white/10 bg-white/[0.02] p-4 text-xs text-[#5a7494]">
            Keine Berechtigung fuer Projekte.
        </p>
    <?php elseif ($projectRows === []): ?>
        <p class="m-6 rounded-lg border border-white/10 bg-white/[0.02] p-4 text-xs text-[#5a7494]">
            Keine aktiven Projekte verfuegbar.
        </p>
    <?php else: ?>
        <div class="overflow-x-auto">
            <table class="min-w-full">
                <thead>
                    <tr class="border-b border-[#00c8ff]/10">
                        <th class="px-6 py-3 text-left text-xs uppercase tracking-wider text-[#5a7494]"
                            style="font-family: 'JetBrains Mono', monospace;">Projekt</th>
                        <th class="px-6 py-3 text-left text-xs uppercase tracking-wider text-[#5a7494]"
                            style="font-family: 'JetBrains Mono', monospace;">Kunde</th>
                        <th class="px-6 py-3 text-left text-xs uppercase tracking-wider text-[#5a7494]"
                            style="font-family: 'JetBrains Mono', monospace;">Status</th>
                        <th class="px-6 py-3 text-left text-xs uppercase tracking-wider text-[#5a7494]"
                            style="font-family: 'JetBrains Mono', monospace;">Fortschritt</th>
                        <th class="px-6 py-3 text-left text-xs uppercase tracking-wider text-[#5a7494]"
                            style="font-family: 'JetBrains Mono', monospace;">Fällig</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($projectRows as $row): ?>
                        <?php
                        $projectId = (string) ($row['id'] ?? '');
                        $projectUrl = '/projects/' . rawurlencode($projectId);
                        $progress = max(0, min(100, (int) ($row['progress'] ?? 0)));
                        $barClass = $progress >= 80 ? 'bg-emerald-400' : ($progress >= 50 ? 'bg-cyan-400' : 'bg-violet-400');
                        ?>
                        <tr class="cursor-pointer border-b border-[#00c8ff]/5 hover:bg-white/[0.02]"
                            onclick="window.location.href='<?= htmlspecialchars($projectUrl, ENT_QUOTES, 'UTF-8'); ?>';"
                            onkeydown="if (event.key === 'Enter' || event.key === ' ') { event.preventDefault(); window.location.href='<?= htmlspecialchars($projectUrl, ENT_QUOTES, 'UTF-8'); ?>'; }"
                            tabindex="0"
                            role="link">
                                <td class="px-6 py-4 text-sm font-medium text-[#e8edf5]">
                                    <?= htmlspecialchars((string) ($row['name'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></td>
                                <td class="px-6 py-4 text-xs text-[#5a7494]">
                                    <?= htmlspecialchars((string) ($row['client'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></td>
                                <td class="px-6 py-4 text-xs text-[#00c8ff]" style="font-family: 'JetBrains Mono', monospace;">
                                    <?= htmlspecialchars((string) ($row['status'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></td>
                                <td class="px-6 py-4">
                                    <div class="flex items-center gap-3">
                                        <div class="h-1.5 min-w-[96px] flex-1 overflow-hidden rounded-full bg-[#00c8ff]/15">
                                            <div class="h-full <?= $barClass; ?>" style="width: <?= $progress; ?>%;"></div>
                                        </div>
                                        <span class="w-10 text-right text-[11px] text-[#5a7494]"
                                            style="font-family: 'JetBrains Mono', monospace;"><?= $progress; ?>%</span>
                                    </div>
                                </td>
                                <td class="px-6 py-4 text-xs text-[#5a7494]" style="font-family: 'JetBrains Mono', monospace;">
                                    <?= htmlspecialchars((string) ($row['due'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</section>