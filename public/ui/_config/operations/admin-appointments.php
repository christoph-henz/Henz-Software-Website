<?php

declare(strict_types=1);

return [
	'default_sort' => 'scheduled_at',
	'default_direction' => 'asc',
	'default_page' => 1,
	'per_page' => 10,
	'timezone' => 'Europe/Berlin',
	'status_labels' => [
		'pending' => 'Ausstehend',
		'accepted' => 'Angenommen',
		'declined' => 'Abgelehnt',
		'completed' => 'Abgeschlossen',
		'no_show' => 'No-Show',
		'storno' => 'Storno',
	],
	'api' => [
		'list' => '/appointments/data',
		'detail' => '/appointments/data/{id}',
		'update' => '/appointments/data/{id}',
		'cancel' => '/appointments/data/{id}/cancel',
		'reschedule' => '/appointments/data/{id}/reschedule',
	],
];
