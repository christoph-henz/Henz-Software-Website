<?php

declare(strict_types=1);

return [
    'max_file_size_bytes' => 5 * 1024 * 1024,
    'max_file_size_label' => '5 MB',
    'upload_chunk_size_bytes' => 500 * 1024,
    'allowed_mime_types' => [
        'image/jpeg',
        'image/png',
        'image/webp',
        'image/gif',
    ],
    'allowed_extensions' => ['jpg', 'jpeg', 'png', 'webp', 'gif'],
    'gallery_slots' => [
        [
            'page_key' => 'home',
            'label' => 'Startseite',
            'sections' => [
                [
                    'section_key' => 'hero',
                    'label' => 'Hero',
                    'slots' => ['background_image'],
                ],
                [
                    'section_key' => 'about',
                    'label' => 'Über mich Abschnitt',
                    'slots' => ['photo_src'],
                ],
            ],
        ],
        [
            'page_key' => 'ueber-mich',
            'label' => 'Über mich',
            'sections' => [
                [
                    'section_key' => 'hero',
                    'label' => 'Hero',
                    'slots' => ['photo_src'],
                ],
            ],
        ],
        [
            'page_key' => 'meine-geschichte',
            'label' => 'Meine Geschichte',
            'sections' => [
                [
                    'section_key' => 'intro',
                    'label' => 'Intro',
                    'slots' => ['lead_visual'],
                ],
            ],
        ],
        [
            'page_key' => 'booking',
            'label' => 'Termin buchen',
            'sections' => [
                [
                    'section_key' => 'hero',
                    'label' => 'Hero',
                    'slots' => ['lead_visual'],
                ],
            ],
        ],
        [
            'page_key' => 'prices',
            'label' => 'Honorar & Ablauf',
            'sections' => [
                [
                    'section_key' => 'hero',
                    'label' => 'Hero',
                    'slots' => ['lead_visual'],
                ],
            ],
        ],
        [
            'page_key' => 'begleitung',
            'label' => 'Begleitung',
            'sections' => [
                [
                    'section_key' => 'intro',
                    'label' => 'Intro',
                    'slots' => ['detail_image_one', 'detail_image_two'],
                ],
            ],
        ],
    ],
    'api' => [
        'assets_list' => '/images/data',
        'assets_upload' => '/images/data',
        'assets_upload_init' => '/images/data/chunk/init',
        'assets_upload_chunk' => '/images/data/chunk/{id}',
        'assets_upload_finish' => '/images/data/chunk/{id}/finish',
        'asset_detail' => '/images/data/{id}',
        'asset_update' => '/images/data/{id}',
        'asset_delete' => '/images/data/{id}',
        'galleries_list' => '/images/galleries/data',
        'page_assignments_list' => '/images/pages/{page_key}/assignments',
        'page_assignments_store' => '/images/pages/{page_key}/assignments',
    ],
];
