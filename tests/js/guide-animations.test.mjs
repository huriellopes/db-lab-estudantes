// Lógica das animações do guia (resources/js/guide-animations.js), sem navegador: os dados
// têm que bater com os resultados mostrados nas páginas e os getters do Alpine precisam
// acompanhar o passo. Roda com `npm run test:js` (node:test, sem dependência nova).
import test from 'node:test';
import assert from 'node:assert/strict';
import * as m from '../../resources/js/guide-animations.js';
const comps = {};
m.registerGuideAnimations({ data: (n, f) => (comps[n] = f) });

test('join inner/left bate com a saída do guia', () => {
  const left = m.joinResult('left', 5).map((r) => `${r.nome}:${r.curso_id}`);
  assert.deepEqual(left, [
    'Ana Souza:1',
    'Ana Souza:2',
    'Bruno Lima:1',
    'Carla Dias:3',
    'Diego Rocha:null',
    'Elisa Melo:1',
    'Elisa Melo:4',
  ]);
  assert.equal(m.joinResult('inner', 5).length, 6);
  assert.equal(m.joinResult('inner', 0).length, 0);
});
test('where com NULL', () => {
  const pass = (k) =>
    m
      .whereRows(k)
      .filter((r) => r.result === true)
      .map((r) => r.nome);
  assert.deepEqual(pass('igual'), ['Ana Souza', 'Carla Dias']);
  assert.deepEqual(pass('diferente'), ['Bruno Lima', 'Elisa Melo']);
  assert.deepEqual(pass('nulo'), ['Diego Rocha']);
});
test('stepper com getters vivos', () => {
  const c = comps.joinAnimation();
  assert.equal(c.atEnd, false);
  for (let i = 0; i < 10; i++) c.next();
  assert.equal(c.step, 5);
  assert.equal(c.atEnd, true);
  c.prev();
  assert.equal(c.step, 4);
  assert.equal(c.alunoState(3), 'current');
  assert.equal(c.alunoState(0), 'done');
  assert.equal(c.alunoState(4), 'pending');
  assert.match(c.narration, /INNER JOIN.*fica de fora/);
  c.setMode('left');
  assert.equal(c.step, 0);
  c.step = 4;
  assert.match(c.narration, /LEFT JOIN/);
  assert.ok(c.result.some((r) => c.isNewRow(r) && r.curso_id === null));
  const w = comps.whereAnimation();
  w.setCondition('diferente');
  w.step = 4;
  assert.match(w.narration, /DESCONHECIDO/);
  const n = comps.normalizationSteps();
  assert.equal(n.current.label, 'Planilhão');
  n.next();
  n.next();
  n.next();
  assert.equal(n.current.label, '3FN');
  assert.equal(n.atEnd, true);
});
test('tabelas da normalização têm colunas consistentes', () => {
  for (const s of m.NORMALIZATION_STEPS)
    for (const t of s.tables) {
      for (const r of t.rows) assert.equal(r.length, t.columns.length, t.name);
      for (const k of [...t.key, ...t.warn]) assert.ok(t.columns.includes(k), `${t.name}.${k}`);
    }
});
