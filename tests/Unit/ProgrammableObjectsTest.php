<?php

declare(strict_types=1);

use App\Support\ProgrammableObjects;
use App\Support\SqlScriptSplitter;

it('removes the DEFINER clause so the object is recreated as whoever runs it', function () {
    expect(ProgrammableObjects::stripDefiner('CREATE DEFINER=`maria`@`%` TRIGGER t BEFORE INSERT ON a FOR EACH ROW SET NEW.x = 1'))
        ->toBe('CREATE TRIGGER t BEFORE INSERT ON a FOR EACH ROW SET NEW.x = 1')
        ->and(ProgrammableObjects::stripDefiner("CREATE ALGORITHM=UNDEFINED DEFINER='maria'@'%' SQL SECURITY DEFINER VIEW `v` AS select 1"))
        ->toBe('CREATE ALGORITHM=UNDEFINED SQL SECURITY DEFINER VIEW `v` AS select 1');
});

it('builds a script the console splits back into one statement per object', function () {
    $script = ProgrammableObjects::restoreScript([
        'CREATE DEFINER=`maria`@`%` VIEW `v` AS select 1 AS `a`',
        'CREATE DEFINER=`maria`@`%` TRIGGER t BEFORE INSERT ON a FOR EACH ROW BEGIN SET NEW.x = 1; END',
    ]);

    expect(SqlScriptSplitter::split($script))->toBe([
        'CREATE VIEW `v` AS select 1 AS `a`',
        'CREATE TRIGGER t BEFORE INSERT ON a FOR EACH ROW BEGIN SET NEW.x = 1; END',
    ]);
});
