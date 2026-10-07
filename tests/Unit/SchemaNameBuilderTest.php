<?php

declare(strict_types=1);

use App\Support\SchemaNameBuilder;

it('accepts labels with letters, numbers and underscore', function () {
    expect(SchemaNameBuilder::isValidLabel('biologia_2026'))->toBeTrue();
});

it('rejects labels with spaces or symbols', function () {
    expect(SchemaNameBuilder::isValidLabel('biologia 2026'))->toBeFalse()
        ->and(SchemaNameBuilder::isValidLabel('biologia-2026'))->toBeFalse()
        ->and(SchemaNameBuilder::isValidLabel(''))->toBeFalse();
});

it('rejects a label starting with an underscore (it would blur the prefix separator)', function () {
    // "ab" + "_x" daria "ab___x", que o GRANT `ab\_\_\_%` de um login "ab_" também alcançaria.
    expect(SchemaNameBuilder::isValidLabel('_biologia'))->toBeFalse()
        ->and(SchemaNameBuilder::isValidLabel('biologia_'))->toBeTrue();
});

it('rejects labels longer than 40 characters', function () {
    expect(SchemaNameBuilder::isValidLabel(str_repeat('a', 41)))->toBeFalse();
});

it('builds the db name from the schema prefix and a lowercased label', function () {
    expect(SchemaNameBuilder::build('u7_joaosilva', 'Biologia_2026'))
        ->toBe('u7_joaosilva__biologia_2026');
});

it('flags names over the 64 character MySQL database name limit', function () {
    $dbName = 'u1_x__' . str_repeat('a', 60);

    expect(SchemaNameBuilder::isWithinLengthLimit($dbName))->toBeFalse();
});

it('recognizes ownership by the schema prefix', function () {
    expect(SchemaNameBuilder::isOwnedBy('u7_joaosilva__biologia_2026', 'u7_joaosilva'))->toBeTrue()
        ->and(SchemaNameBuilder::isOwnedBy('u9_outrapessoa__biologia_2026', 'u7_joaosilva'))->toBeFalse();
});

it('rejects db names with characters that could break out of a DDL identifier', function () {
    expect(SchemaNameBuilder::isValidDbName('u7_joaosilva__biologia_2026'))->toBeTrue()
        ->and(SchemaNameBuilder::isValidDbName('u7_x`; DROP TABLE users; --'))->toBeFalse()
        ->and(SchemaNameBuilder::isValidDbName('Maiusculo'))->toBeFalse()
        ->and(SchemaNameBuilder::isValidDbName(''))->toBeFalse();
});

it('builds a GRANT pattern that escapes every literal underscore of the prefix', function () {
    expect(SchemaNameBuilder::grantPattern('ana_costa'))->toBe('ana\\_costa\\_\\_%');
});

it('builds a LIKE pattern that escapes wildcards of the prefix', function () {
    expect(SchemaNameBuilder::likePattern('ana_costa'))->toBe('ana\\_costa\\_\\_%');
});
