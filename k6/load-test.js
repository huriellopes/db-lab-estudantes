import http from 'k6/http';
import { check, group, sleep } from 'k6';
import { Rate } from 'k6/metrics';

/**
 * Teste de carga (k6) do DB Lab Estudantes.
 *
 * Dois cenários rodando em paralelo, imitando o tráfego real da app:
 *   - guest_browsing:         páginas públicas, sem login — /login, /register, /esqueci-senha.
 *   - authenticated_browsing: páginas que exigem sessão — dashboard, guia, laboratório de
 *     modelagem, perfil, conectar (ver Auth::requireLogin em cada Action correspondente).
 *
 * A sessão autenticada é criada UMA ÚNICA VEZ em setup() (registro + login de uma conta
 * descartável) e reaproveitada por todos os VUs do cenário autenticado, em vez de cada VU
 * logar por conta própria. Isso é de propósito, não um atalho: App\Services\RateLimiter
 * limita POST /login a 15 tentativas/5min por IP (App\Actions\Auth\LoginAction) e POST
 * /register a 8/15min por IP (App\Actions\Auth\RegisterAction) — como todo o tráfego do k6
 * sai do mesmo IP visto pela app, um login por VU estouraria esse limite com poucos VUs e o
 * teste de carga viraria, ele mesmo, um teste do rate limiter.
 *
 * Só GET nas páginas autenticadas (nada de POST /dashboard/sql, /schemas etc.) — o objetivo
 * aqui é validar que a aplicação continua respondendo bem sob carga, sem gerar dado real
 * (schemas MySQL, e-mails, linhas em saved_queries...) que precisaria de limpeza depois.
 *
 * Uso local:
 *   docker compose up -d --build
 *   k6 run k6/load-test.js
 *   BASE_URL=http://localhost:8080 LOAD_VUS=30 LOAD_DURATION=1m k6 run k6/load-test.js
 *
 * Variáveis de ambiente:
 *   BASE_URL      URL base da aplicação (padrão: http://localhost:8080)
 *   LOAD_VUS      VUs simultâneos no platô de cada cenário (padrão: 10)
 *   LOAD_DURATION Duração do platô de cada cenário (padrão: 20s)
 */

const BASE_URL = (__ENV.BASE_URL || 'http://localhost:8080').replace(/\/$/, '');
const PEAK_VUS = Number(__ENV.LOAD_VUS || 10);
const PLATEAU = __ENV.LOAD_DURATION || '20s';

// Conta descartável, criada uma única vez em setup() — nunca reaproveita uma conta que já
// exista. RUN_ID junta timestamp com um sufixo aleatório pra não colidir mesmo com duas
// execuções disparadas no mesmo milissegundo (ex.: dois jobs de CI em paralelo).
const RUN_ID = `${Date.now()}${Math.floor(Math.random() * 1000)}`;
const TEST_NAME = 'K6 Load Test';
const TEST_EMAIL = `k6-load-${RUN_ID}@example.test`;
const TEST_USERNAME = `k6load${RUN_ID}`.slice(0, 32);
const TEST_PASSWORD = 'K6-load-test-P4ssw0rd!9';

// Lista fixa espelhando App\Support\GuideTopics::ALL — só pra sortear um slug válido a
// cada iteração (o próprio backend rejeita qualquer slug fora dessa lista com 404).
const GUIDE_SLUGS = [
  'modelagem-er',
  'formas-normais',
  'sql-ansi',
  'mysql',
  'postgresql',
  'oracle',
  'sql-server',
  'mongodb',
  'redis',
];

const errorRate = new Rate('errors');

export const options = {
  thresholds: {
    // Sob esse pico de VUs, a app não deve falhar nem 1% das requisições, nem ficar lenta.
    http_req_failed: ['rate<0.01'],
    'http_req_duration{group:::guest: páginas públicas}': ['p(95)<800'],
    'http_req_duration{group:::autenticado: navegação típica}': ['p(95)<1000'],
    errors: ['rate<0.01'],
  },
  scenarios: {
    guest_browsing: {
      executor: 'ramping-vus',
      exec: 'guestBrowsing',
      startVUs: 0,
      stages: [
        { duration: '10s', target: PEAK_VUS },
        { duration: PLATEAU, target: PEAK_VUS },
        { duration: '10s', target: 0 },
      ],
      gracefulRampDown: '5s',
    },
    authenticated_browsing: {
      executor: 'ramping-vus',
      exec: 'authenticatedBrowsing',
      startVUs: 0,
      stages: [
        { duration: '10s', target: PEAK_VUS },
        { duration: PLATEAU, target: PEAK_VUS },
        { duration: '10s', target: 0 },
      ],
      gracefulRampDown: '5s',
    },
  },
};

/** GET /csrf-token devolve {"token": "..."} — ver App\Actions\Auth\CsrfTokenAction. */
function fetchCsrfToken() {
  const res = http.get(`${BASE_URL}/csrf-token`);
  check(res, { 'csrf-token: 200': (r) => r.status === 200 });

  const body = res.json();

  return body && body.token;
}

/**
 * Registra e loga a conta descartável usada pelo cenário autenticado. Roda uma vez só,
 * antes de qualquer VU — se falhar, aborta o teste inteiro (sem sessão, o cenário
 * autenticado não tem como rodar).
 */
export function setup() {
  const csrf = fetchCsrfToken();
  if (!csrf) {
    throw new Error('k6 setup: não recebeu token CSRF de /csrf-token — abortando.');
  }

  const registerRes = http.post(`${BASE_URL}/register`, {
    name: TEST_NAME,
    email: TEST_EMAIL,
    username: TEST_USERNAME,
    password: TEST_PASSWORD,
    password_confirm: TEST_PASSWORD,
    _csrf: csrf,
  });
  check(registerRes, { 'register (setup): sucesso': (r) => r.status === 200 });

  const loginRes = http.post(
    `${BASE_URL}/login`,
    {
      identifier: TEST_EMAIL,
      password: TEST_PASSWORD,
      _csrf: csrf,
    },
    { redirects: 0 },
  );
  check(loginRes, { 'login (setup): redireciona pro dashboard': (r) => r.status === 302 });

  const sessionCookie = loginRes.cookies.PHPSESSID;
  const sessionId = sessionCookie && sessionCookie.length ? sessionCookie[0].value : null;

  if (!sessionId) {
    throw new Error(
      'k6 setup: não recebeu PHPSESSID após login (usuário de teste não foi criado/autenticado) — abortando.',
    );
  }

  // Login::regenerate_id preserva os dados da sessão (ver App\Core\Auth::login), então o
  // mesmo token CSRF de antes do login continua valendo — não precisa buscar outro.
  return { sessionId, csrf };
}

export function guestBrowsing() {
  group('guest: páginas públicas', () => {
    const responses = http.batch([
      ['GET', `${BASE_URL}/login`],
      ['GET', `${BASE_URL}/register`],
      ['GET', `${BASE_URL}/esqueci-senha`],
    ]);

    responses.forEach((res) => {
      const ok = check(res, { 'status 200': (r) => r.status === 200 });
      errorRate.add(!ok);
    });
  });

  sleep(1);
}

export function authenticatedBrowsing(data) {
  // Cada VU precisa da própria cookie jar apontando pra sessão criada em setup() — jars não
  // atravessam de setup() pros VUs sozinhos, por isso o sessionId volta explícito em `data`.
  http.cookieJar().set(BASE_URL, 'PHPSESSID', data.sessionId);

  group('autenticado: navegação típica', () => {
    const slug = GUIDE_SLUGS[Math.floor(Math.random() * GUIDE_SLUGS.length)];

    const pages = [
      ['dashboard: 200', `${BASE_URL}/dashboard`],
      ['guia: 200', `${BASE_URL}/guia`],
      [`guia/${slug}: 200`, `${BASE_URL}/guia/${slug}`],
      ['laboratorio/modelagem: 200', `${BASE_URL}/laboratorio/modelagem`],
      ['profile: 200', `${BASE_URL}/profile`],
      ['conectar: 200', `${BASE_URL}/conectar`],
    ];

    pages.forEach(([label, url]) => {
      const res = http.get(url);
      const ok = check(res, { [label]: (r) => r.status === 200 });
      errorRate.add(!ok);
    });
  });

  sleep(1);
}
