<?php

declare(strict_types=1);

use App\Support\ProfessorGrantDiff;

it('grants what is missing and revokes what is left over', function () {
    expect(ProfessorGrantDiff::between(['ana\_\_%', 'bia\_\_%'], ['bia\_\_%', 'caio\_\_%']))
        ->toBe(['grant' => ['ana\_\_%'], 'revoke' => ['caio\_\_%']]);
});

it('does nothing when already in sync, and revokes everything when nothing is desired', function () {
    expect(ProfessorGrantDiff::between(['ana\_\_%'], ['ana\_\_%']))->toBe(['grant' => [], 'revoke' => []])
        ->and(ProfessorGrantDiff::between([], ['ana\_\_%']))->toBe(['grant' => [], 'revoke' => ['ana\_\_%']]);
});
