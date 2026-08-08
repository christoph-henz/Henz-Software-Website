<?php

declare(strict_types=1);

$structure = is_array($service['structure'] ?? null) ? $service['structure'] : [];
$data = is_array($service['data'] ?? null) ? $service['data'] : [];
$imageSlots = [];

foreach ($service as $key => $value) {
	if (!is_string($key) || !is_string($value)) {
		continue;
	}

	if (str_contains($key, 'image')) {
		$imageSlots[$key] = $value;
	}
}

if (empty($structure)) {
	return;
}

$escape = static fn(string $value): string => htmlspecialchars($value, ENT_QUOTES, 'UTF-8');

$read = static function (array $source, string $path, mixed $default = null): mixed {
	if ($path === '') {
		return $default;
	}

	$segments = explode('.', $path);
	$value = $source;

	foreach ($segments as $segment) {
		if (!is_array($value) || !array_key_exists($segment, $value)) {
			return $default;
		}

		$value = $value[$segment];
	}

	return $value;
};

$stringFromData = static function (array $source, ?string $path, string $default = '') use ($read): string {
	if ($path === null || $path === '') {
		return $default;
	}

	$value = $read($source, $path, $default);

	return is_scalar($value) ? trim((string) $value) : $default;
};

$listFromData = static function (array $source, ?string $path) use ($read): array {
	if ($path === null || $path === '') {
		return [];
	}

	$value = $read($source, $path, []);

	return is_array($value) ? $value : [];
};

$imageSource = static function (array $node) use ($imageSlots): string {
	$slot = trim((string) ($node['image_var'] ?? $node['src_var'] ?? ''));
	if ($slot !== '' && isset($imageSlots[$slot])) {
		return $imageSlots[$slot];
	}

	$fallback = trim((string) ($node['src'] ?? ''));

	return $fallback !== '' ? $fallback : '/ui/_assets/images/profile-placeholder.svg';
};

$sectionId = $stringFromData($data, 'slug');
?>
<section<?= $sectionId !== '' ? ' id="' . $escape($sectionId) . '"' : ''; ?> class="relative py-24 lg:py-32 overflow-hidden">
	<div class="absolute inset-0 pointer-events-none opacity-[0.04]"
		style="background-image: linear-gradient(rgb(0, 200, 255) 1px, transparent 1px), linear-gradient(90deg, rgb(0, 200, 255) 1px, transparent 1px); background-size: 56px 56px;">
	</div>
	<div class="absolute top-20 right-0 w-[480px] h-[480px] rounded-full opacity-10 pointer-events-none"
		style="background: radial-gradient(circle, rgb(0, 200, 255) 0%, transparent 70%); filter: blur(90px);">
	</div>
	<div class="relative z-10 max-w-7xl mx-auto px-6 space-y-20 lg:space-y-28">
		<?php foreach ($structure as $node): ?>
			<?php
			if (!is_array($node)) {
				continue;
			}

			$type = (string) ($node['type'] ?? '');
			?>
			<?php if ($type === 'intro'): ?>
				<?php
				$slug = $stringFromData($data, isset($node['slug']) ? (string) $node['slug'] : null);
				$eyebrow = $stringFromData($data, isset($node['eyebrow']) ? (string) $node['eyebrow'] : null);
				$title = $stringFromData($data, isset($node['title']) ? (string) $node['title'] : null);
				$accent = $stringFromData($data, isset($node['accent']) ? (string) $node['accent'] : null);
				$lead = $stringFromData($data, isset($node['lead']) ? (string) $node['lead'] : null);
				$points = $listFromData($data, isset($node['points']) ? (string) $node['points'] : null);
				$primaryCta = $read($data, (string) ($node['primary_cta'] ?? ''), []);
				$secondaryCta = $read($data, (string) ($node['secondary_cta'] ?? ''), []);
				$imageAlt = $stringFromData($data, isset($node['image_alt']) ? (string) $node['image_alt'] : null);
				$imageSrc = $imageSource($node);
				?>
				<div class="grid lg:grid-cols-[minmax(0,1.05fr)_minmax(320px,0.95fr)] gap-12 lg:gap-16 items-center">
					<div class="space-y-8 max-w-2xl py-2 lg:py-4">
						<?php if ($slug !== ''): ?>
							<div class="text-xs uppercase tracking-[0.32em]" style="color: rgb(0, 200, 255); font-family: 'JetBrains Mono', monospace;">
								// <?= $escape($slug); ?>
							</div>
						<?php endif; ?>
						<div class="space-y-5 lg:space-y-6">
							<?php if ($eyebrow !== ''): ?>
								<p class="text-sm uppercase tracking-[0.28em]" style="color: rgb(90, 116, 148);"><?= $escape($eyebrow); ?></p>
							<?php endif; ?>
							<h2 class="text-4xl lg:text-6xl font-bold leading-[1.05] tracking-tight" style="font-family: 'JetBrains Mono', monospace; color: rgb(232, 237, 245);">
								<?= $escape($title); ?>
								<?php if ($accent !== ''): ?>
									<span style="color: rgb(0, 200, 255);"> <?= $escape($accent); ?></span>
								<?php endif; ?>
							</h2>
							<?php if ($lead !== ''): ?>
								<p class="text-lg leading-relaxed max-w-2xl" style="color: rgb(90, 116, 148);"><?= $escape($lead); ?></p>
							<?php endif; ?>
						</div>
						<?php if (!empty($points)): ?>
							<div class="grid sm:grid-cols-2 gap-4 max-w-2xl">
								<?php foreach ($points as $point): ?>
									<?php if (!is_scalar($point) || trim((string) $point) === '') { continue; } ?>
									<div class="rounded-xl border p-4 h-full" style="border-color: rgba(0, 200, 255, 0.12); background: rgba(6, 10, 15, 0.9);">
										<div class="flex items-start gap-3">
											<span class="mt-1 w-2 h-2 rounded-full shrink-0" style="background: rgb(0, 200, 255);"></span>
											<p class="text-sm leading-relaxed" style="color: rgb(232, 237, 245);"><?= $escape(trim((string) $point)); ?></p>
										</div>
									</div>
								<?php endforeach; ?>
							</div>
						<?php endif; ?>
						<div class="flex flex-wrap items-center gap-4 sm:gap-5 pt-2 sm:pt-4">
							<?php if (is_array($primaryCta) && trim((string) ($primaryCta['label'] ?? '')) !== ''): ?>
								<a href="<?= $escape((string) ($primaryCta['href'] ?? '#')); ?>" class="group inline-flex min-h-[3.5rem] items-center justify-center gap-2.5 px-8 py-4 rounded font-semibold text-sm transition-all duration-200" style="background: rgb(0, 200, 255); color: rgb(6, 10, 15); font-family: 'JetBrains Mono', monospace;">
									<?= $escape((string) $primaryCta['label']); ?>
									<span class="transition-transform duration-200 group-hover:translate-x-1">&rarr;</span>
								</a>
							<?php endif; ?>
							<?php if (is_array($secondaryCta) && trim((string) ($secondaryCta['label'] ?? '')) !== ''): ?>
								<a href="<?= $escape((string) ($secondaryCta['href'] ?? '#')); ?>" class="inline-flex min-h-[3.5rem] items-center justify-center gap-2.5 px-8 py-4 rounded font-medium text-sm border transition-all duration-200" style="color: rgb(232, 237, 245); border-color: rgba(232, 237, 245, 0.15); background: transparent;">
									<?= $escape((string) $secondaryCta['label']); ?>
								</a>
							<?php endif; ?>
						</div>
					</div>
					<div class="relative w-full max-w-[36rem] mx-auto lg:mx-0 py-2">
						<div class="absolute -inset-5 rounded-[2rem] opacity-60" style="background: linear-gradient(135deg, rgba(0, 200, 255, 0.18), rgba(0, 102, 255, 0.08)); filter: blur(30px);"></div>
						<div class="relative rounded-[2rem] border overflow-hidden" style="border-color: rgba(0, 200, 255, 0.14); background: linear-gradient(180deg, rgba(8, 13, 20, 0.96), rgba(6, 10, 15, 0.96));">
							<div class="flex items-center justify-between px-5 py-4 border-b" style="border-color: rgba(0, 200, 255, 0.12);">
								<span class="text-xs uppercase tracking-[0.3em]" style="color: rgb(90, 116, 148); font-family: 'JetBrains Mono', monospace;">software.blueprint</span>
								<span class="w-2.5 h-2.5 rounded-full bg-green-400"></span>
							</div>
							<img src="<?= $escape($imageSrc); ?>" alt="<?= $escape($imageAlt); ?>" class="w-full h-full object-cover aspect-[4/3]">
						</div>
					</div>
				</div>
			<?php elseif ($type === 'stats'): ?>
				<?php $items = $listFromData($data, isset($node['items']) ? (string) $node['items'] : null); ?>
				<?php if (!empty($items)): ?>
					<div class="border-y py-8 lg:py-10 grid grid-cols-2 md:grid-cols-4 gap-4 md:gap-6 max-w-5xl mx-auto items-stretch" style="border-color: rgba(0, 200, 255, 0.08);">
						<?php foreach ($items as $item): ?>
							<?php if (!is_array($item)) { continue; } ?>
							<?php $value = trim((string) ($item['value'] ?? '')); $label = trim((string) ($item['label'] ?? '')); ?>
							<?php if ($value !== '' || $label !== ''): ?>
								<div class="text-center rounded-xl px-4 py-5 h-full flex flex-col justify-center" style="background: rgba(6, 10, 15, 0.45); border: 1px solid rgba(0, 200, 255, 0.08);">
									<div class="text-3xl font-bold mb-1" style="font-family: 'JetBrains Mono', monospace; color: rgb(0, 200, 255);"><?= $escape($value); ?></div>
									<div class="text-xs uppercase tracking-widest" style="color: rgb(90, 116, 148);"><?= $escape($label); ?></div>
								</div>
							<?php endif; ?>
						<?php endforeach; ?>
					</div>
				<?php endif; ?>
			<?php elseif ($type === 'split_panel'): ?>
				<?php
				$slug = $stringFromData($data, isset($node['slug']) ? (string) $node['slug'] : null);
				$title = $stringFromData($data, isset($node['title']) ? (string) $node['title'] : null);
				$accent = $stringFromData($data, isset($node['accent']) ? (string) $node['accent'] : null);
				$body = $stringFromData($data, isset($node['body']) ? (string) $node['body'] : null);
				$points = $listFromData($data, isset($node['points']) ? (string) $node['points'] : null);
				$imageAlt = $stringFromData($data, isset($node['image_alt']) ? (string) $node['image_alt'] : null);
				$imageSrc = $imageSource($node);
				$reverse = (bool) ($node['reverse'] ?? false);
				?>
				<div class="grid lg:grid-cols-2 gap-px rounded-[2rem] overflow-hidden max-w-6xl mx-auto items-stretch" style="background: rgba(0, 200, 255, 0.08);">
					<div class="min-w-0 p-8 lg:p-12 flex flex-col justify-center space-y-5 <?= $reverse ? 'lg:order-2' : ''; ?>" style="background: rgb(6, 10, 15);">
						<?php if ($slug !== ''): ?>
							<div class="text-xs uppercase tracking-[0.28em] mb-5" style="color: rgb(0, 200, 255); font-family: 'JetBrains Mono', monospace;">// <?= $escape($slug); ?></div>
						<?php endif; ?>
						<h3 class="text-2xl sm:text-3xl lg:text-4xl font-bold leading-tight" style="font-family: 'JetBrains Mono', monospace; color: rgb(232, 237, 245); overflow-wrap: anywhere; word-break: normal; hyphens: auto;">
							<?= $escape($title); ?>
							<?php if ($accent !== ''): ?>
								<span style="color: rgb(0, 200, 255);"> <?= $escape($accent); ?></span>
							<?php endif; ?>
						</h3>
						<?php if ($body !== ''): ?>
							<p class="text-base leading-relaxed" style="color: rgb(90, 116, 148); overflow-wrap: anywhere; word-break: normal; hyphens: auto;">
								<?= $escape($body); ?>
							</p>
						<?php endif; ?>
						<?php if (!empty($points)): ?>
							<div class="space-y-4">
								<?php foreach ($points as $point): ?>
									<?php if (!is_scalar($point) || trim((string) $point) === '') { continue; } ?>
									<div class="flex items-start gap-3">
										<span class="mt-2 w-2 h-2 rounded-full shrink-0" style="background: rgb(0, 200, 255);"></span>
										<p class="text-sm leading-relaxed" style="color: rgb(232, 237, 245);"><?= $escape(trim((string) $point)); ?></p>
									</div>
								<?php endforeach; ?>
							</div>
						<?php endif; ?>
					</div>
					<div class="relative min-h-[320px] lg:min-h-[100%] <?= $reverse ? 'lg:order-1' : ''; ?>" style="background: linear-gradient(180deg, rgba(8, 13, 20, 0.96), rgba(6, 10, 15, 0.96));">
						<div class="absolute inset-0 bg-gradient-to-br from-cyan-400/10 to-transparent"></div>
						<img src="<?= $escape($imageSrc); ?>" alt="<?= $escape($imageAlt); ?>" class="relative w-full h-full min-h-[320px] object-cover">
					</div>
				</div>
			<?php elseif ($type === 'feature_grid'): ?>
				<?php
				$slug = $stringFromData($data, isset($node['slug']) ? (string) $node['slug'] : null);
				$title = $stringFromData($data, isset($node['title']) ? (string) $node['title'] : null);
				$accent = $stringFromData($data, isset($node['accent']) ? (string) $node['accent'] : null);
				$lead = $stringFromData($data, isset($node['lead']) ? (string) $node['lead'] : null);
				$items = $listFromData($data, isset($node['items']) ? (string) $node['items'] : null);
				?>
				<?php if (!empty($items)): ?>
					<div class="space-y-10 lg:space-y-12">
						<div class="max-w-3xl mx-auto space-y-4 text-center">
							<?php if ($slug !== ''): ?>
								<div class="text-xs uppercase tracking-[0.28em]" style="color: rgb(0, 200, 255); font-family: 'JetBrains Mono', monospace;">// <?= $escape($slug); ?></div>
							<?php endif; ?>
							<h3 class="text-3xl lg:text-5xl font-bold leading-tight" style="font-family: 'JetBrains Mono', monospace; color: rgb(232, 237, 245);">
								<?= $escape($title); ?>
								<?php if ($accent !== ''): ?>
									<span style="color: rgb(0, 200, 255);"> <?= $escape($accent); ?></span>
								<?php endif; ?>
							</h3>
							<?php if ($lead !== ''): ?>
								<p class="text-base lg:text-lg leading-relaxed" style="color: rgb(90, 116, 148);"><?= $escape($lead); ?></p>
							<?php endif; ?>
						</div>
						<div class="grid md:grid-cols-2 xl:grid-cols-3 gap-4 max-w-6xl mx-auto" style="background: rgba(0, 200, 255, 0.08);">
							<?php foreach ($items as $item): ?>
								<?php if (!is_array($item)) { continue; } ?>
								<?php $itemSlug = trim((string) ($item['slug'] ?? '')); $itemTitle = trim((string) ($item['title'] ?? '')); $itemDescription = trim((string) ($item['description'] ?? '')); ?>
								<div class="p-8 h-full flex flex-col justify-start rounded-[1.5rem]" style="background: rgb(6, 10, 15);">
									<?php if ($itemSlug !== ''): ?>
										<div class="inline-flex text-xs px-3 py-1 rounded-full mb-5" style="font-family: 'JetBrains Mono', monospace; color: rgb(90, 116, 148); background: rgba(90, 116, 148, 0.1); border: 1px solid rgba(90, 116, 148, 0.2);"><?= $escape($itemSlug); ?></div>
									<?php endif; ?>
									<h4 class="text-xl font-bold mb-3" style="color: rgb(232, 237, 245);"><?= $escape($itemTitle); ?></h4>
									<p class="text-sm leading-relaxed" style="color: rgb(90, 116, 148);"><?= $escape($itemDescription); ?></p>
								</div>
							<?php endforeach; ?>
						</div>
					</div>
				<?php endif; ?>
			<?php endif; ?>
		<?php endforeach; ?>
	</div>
</section>