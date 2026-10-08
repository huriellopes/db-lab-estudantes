<?php

declare(strict_types=1);

use App\Support\RowEditSql;

it('quotes identifiers, escaping backticks', function () {
    expect(RowEditSql::ident('nota'))->toBe('`nota`')
        ->and(RowEditSql::ident('a`b'))->toBe('`a``b`');
});

it('builds an UPDATE limited to one row by the primary key, values as parameters', function () {
    [$sql, $params] = RowEditSql::update('ana__loja', 'item', ['pedido_id' => 7, 'linha' => 2], ['qtd' => '3', 'obs' => null]);

    expect($sql)->toBe('UPDATE `ana__loja`.`item` SET `qtd` = ?, `obs` = ? WHERE `pedido_id` = ? AND `linha` = ? LIMIT 1')
        ->and($params)->toBe(['3', null, 7, 2]);
});

it('builds an INSERT, including the empty one that uses only defaults', function () {
    expect(RowEditSql::insert('ana__loja', 'cliente', ['nome' => 'Bia', 'cidade' => null]))
        ->toBe(['INSERT INTO `ana__loja`.`cliente` (`nome`, `cidade`) VALUES (?, ?)', ['Bia', null]])
        ->and(RowEditSql::insert('ana__loja', 'log', []))->toBe(['INSERT INTO `ana__loja`.`log` () VALUES ()', []]);
});

it('refuses an UPDATE without key or without changes', function () {
    expect(fn () => RowEditSql::update('d', 't', [], ['a' => 1]))->toThrow(InvalidArgumentException::class)
        ->and(fn () => RowEditSql::update('d', 't', ['id' => 1], []))->toThrow(InvalidArgumentException::class);
});
