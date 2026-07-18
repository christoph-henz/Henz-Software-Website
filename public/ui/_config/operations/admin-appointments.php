<?php

declare(strict_types=1);

return [
	'default_sort' => 'scheduled_at',
	'default_direction' => 'asc',
	'default_page' => 1,
	'per_page' => 25,
	'timezone' => 'Europe/Berlin',
	'status_labels' => [
		'pending' => 'Ausstehend',
		'accepted' => 'Angenommen',
		'declined' => 'Abgelehnt',
		'completed' => 'Abgeschlossen',
		'storno' => 'Storno',
	],
	'api' => [
		'list' => '/appointments/data',
		'detail' => '/appointments/data/{id}',
		'update' => '/appointments/data/{id}',
	],
];
