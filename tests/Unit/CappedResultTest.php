<?php

declare(strict_types=1);

use App\Support\CappedResult;

function sqliteWithRows(int $count): PDO
{
    $pdo = new PDO('sqlite::memory:', options: [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $pdo->exec('CREATE TABLE t (n INTEGER)');
    $insert = $pdo->prepare('INSERT INTO t (n) VALUES (?)');
    for ($i = 1; $i <= $count; $i++) {
        $insert->execute([$i]);
    }

    return $pdo;
}

it('returns every row when the result fits under the cap', function () {
    $result = CappedResult::fetch(sqliteWithRows(3)->query('SELECT n FROM t ORDER BY n'), max: 5);

    expect($result->rows)->toBe([['n' => 1], ['n' => 2], ['n' => 3]])
        ->and($result->truncated)->toBeFalse();
});

it('is not truncated when the result has exactly the cap', function () {
    $result = CappedResult::fetch(sqliteWithRows(5)->query('SELECT n FROM t'), max: 5);

    expect($result->rows)->toHaveCount(5)
        ->and($result->truncated)->toBeFalse();
});

it('stops reading at the cap and flags the result as truncated', function () {
    $result = CappedResult::fetch(sqliteWithRows(50)->query('SELECT n FROM t ORDER BY n'), max: 10);

    expect($result->rows)->toHaveCount(10)
        ->and($result->rows[9])->toBe(['n' => 10])
        ->and($result->truncated)->toBeTrue();
});

it('handles an empty result', function () {
    $result = CappedResult::fetch(sqliteWithRows(0)->query('SELECT n FROM t'), max: 10);

    expect($result->rows)->toBe([])
        ->and($result->truncated)->toBeFalse();
});
