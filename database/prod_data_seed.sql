INSERT INTO
    `users` (
        email,
        password_hash,
        first_name,
        last_name,
        role_mask
    )
VALUES
    (
        'christophhenz@gmail.com',
        '$2y$10$iL7OpDgYu8DZgpvWwl0vL.zWWOkXFXhPgeIWYifAhtIfGqzCLalsm',
        ' ',
        'System Administrator',
        67108863
    ),
    (
        'claudia.schreiber@henz-software.de',
        '$2y$10$3oQQkSDDXU8NPnhyNGfnoOsHjcZYmOu/u57FAppo8NcdowFQ0f8Bi',
        'Claudia',
        'Schreiber',
        99135
    ),
    (
        'support@henz-software.de',
        '$2y$10$o1IPoPajqv0u6.wBOG5LcOKeK4Z2ScLJpZzuvW3AJ05m19LHb5klu',
        'Ioanni',
        'Gkogkas',
        32803
    ),
    (
        'hans.huber@henz-software.de',
        '$2y$10$nLiWEd4yQvDu4mz0WSnMGOr0AvAztfBqmIlhnXJaYs.UIU6mnPyrO',
        'Hans',
        'Huber',
        33292336
    ),
    (
        'christoph.henz@henz-software.de',
        '$2y$10$b.W529gEAvAA8ymzwkedRepWUA9AIqcQLNpBjBF/MOsC7MAYfvZkO',
        'Christoph',
        'Henz',
        33402743
    ),
    (
        'lars.gerster@henz-software.de',
        '$2y$12$Thnzo5dJG6Wk3bzDA4S4EOk0Hp3HoeAB.7oAqVXLycRimCF7dP25K',
        'Lars',
        'Gerster',
        295011
    );

INSERT INTO
    `clients` (name, email, phone, address)
VALUES
    (
        '{"kv":"1","fmt":"string","iv":"vtBqETFnE4Vk4RaB","tag":"CACmQCWYfr+erPyqo8ySGw==","ct":"oxoDycYjPlLucdqbV7Du"}',
        '{"kv":"1","fmt":"string","iv":"IYFGChHJ9ZBkisJ1","tag":"zgcAVQTruqsXEhjcwp/XpA==","ct":"h5t5A3R8M3I1uallNWB9Vte8juRCgw=="}',
        '{"kv":"1","fmt":"string","iv":"8fb4P8dDUSlDwaLm","tag":"LVlmaiz0CD01r22dO5P3xQ==","ct":"fA68bAvj8JpHaycKZTA="}',
        '{"kv":"1","fmt":"string","iv":"XJbXnNDZhCMKqrcc","tag":"BmcmH+LPbS6GDQuNabUQCw==","ct":"yPaYSmvr1w/H2h1KY8I6pGT0ANnBY6TaNQ87VKZZADC4"}'
    ),
    (
        '{"kv":"1","fmt":"string","iv":"OpQ6JJ+uS+G42BF7","tag":"jY5zGIzrgyRmrVkjFipxuQ==","ct":"1KgNJQ02IxpMq9M="}',
        '{"kv":"1","fmt":"string","iv":"jwulABoWOvuYo+rc","tag":"ifdGM68MxjG0SILBVLMBfA==","ct":"kfjB/V+pj4IpGtcpDRj9IVDfo6hpiKDsi/00"}',
        '{"kv":"1","fmt":"string","iv":"hyfCCD29p7RC52Bg","tag":"w0bTF4K3kqPHselHYFUe4Q==","ct":"MDO4SdFGqANm0kgHJQ=="}',
        '{"kv":"1","fmt":"string","iv":"/ulYS4E9l6BLdoSD","tag":"86ekKxk4bUWyvBeQKwz+0w==","ct":"d3ErjL1Q/8Xc5c3LNOOZBV2UqhPUymFWQL6NqnMX4IS/EQ=="}'
    ),
    (
        '{"kv":"1","fmt":"string","iv":"0BSJEVanfG+GGnbV","tag":"9IsIy4bpJnWqt0sl4AQQsg==","ct":"1+sYeDQsL843CasKK42W"}',
        '{"kv":"1","fmt":"string","iv":"T2hhuf4vLHLA6eOv","tag":"w5LY35XqXnVfp1+ZVqKNmg==","ct":"GNBU6p2HgNtCj84q/r0srB+Yqt0="}',
        NULL,
        '{"kv":"1","fmt":"string","iv":"pH8ZGOaJsPTl54Ds","tag":"NDH8iHBpPCpjVl7bhK2IXQ==","ct":"ZEaJ1bV9GDW2fcp5hW7sokXgMEk="}'
    );

INSERT INTO
    `projects` (id, name, description, client_id, status, progress, due_date, created_by)
VALUES
    (
        1,
        'Dionysos Website',
        '- Landing Page\n- Reservierungsservice\n- Bestellservice\n- Adminpanel zur Darstellung von:\n   * Artikelmanagement\n   * Messagingmanagement\n   * Reservierungsmanagement\n   * Einstellungsmanagement\n   * Bilderverwaltung',
        1,
        'pending',
        100,
        '2026-10-01',
        1
    ),
    (
        2,
        'Getragen Begleiten',
        'Praxismanagementsystem für eine Psychologiepraxis mit:\n  - Klientenverwaltung\n  - Buchungsmanagement\n  - Abrechnungsmanagement',
        2,
        'in_progress',
        68,
        '2026-08-31',
        1
    ),
    (
        3,
        'Villa Athina',
        'Hotelverwaltungstool mit\n  - Landingpage\n  ⁻ Buchungs und Checkinmanagement\n  - Raumverwaltung\n  - Abrechnungsmanagement',
        3,
        'completed',
        100,
        '2026-03-20',
        1
    );

INSERT INTO
    `project_phase` (project_id, phase_name, status, progress, due_date)
VALUES
    (
        1,
        'MVP',
        'completed',
        100,
        '2025-08-15'
    ),
    (
        1,
        'LandingPage',
        'completed',
        100,
        '2025-08-31'
    ),
    (
        2,
        'MVP',
        'completed',
        100,
        '2026-05-28'
    ),
    (
        2,
        'Landing Page',
        'in_progress',
        80,
        '2026-07-31'
    ),
    (
        2,
        'Praxisverwaltung',
        'review',
        90,
        '2026-08-31'
    ),
    (
        2,
        'Abschluss',
        'pending',
        0,
        '2026-08-31'
    ),
    (
        1,
        'Adminpanel',
        'completed',
        100,
        '2025-08-31'
    ),
    (
        3,
        'MVP',
        'completed',
        100,
        '2026-02-15'
    ),
    (
        3,
        'Landing Page',
        'completed',
        100,
        '2026-02-25'
    ),
    (
        3,
        'Adminpanel',
        'completed',
        100,
        '2026-03-15'
    );

INSERT INTO
    `project_members` (project_id, user_id, role)
VALUES
    (1, 6, 'developer'),
    (1, 6, 'owner'),
    (1, 6, 'manager'),
    (1, 6, 'designer'),
    (1, 6, 'tester'),
    (3, 6, 'developer');