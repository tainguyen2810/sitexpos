// k6 load-test design for STAGING only (never production, never real AI providers).
// Run: k6 run -e BASE=https://api.staging.example.vn -e COOKIE='<session cookies>' -e WS=<workspace id> scripts/perf/k6-smoke.js
// AI endpoints are excluded: on staging set AI providers to the Fake provider before any load test.
import http from 'k6/http'
import { check, sleep } from 'k6'

export const options = {
  scenarios: {
    browse: { executor: 'ramping-vus', startVUs: 1, stages: [{ duration: '2m', target: 20 }, { duration: '5m', target: 50 }, { duration: '2m', target: 0 }] },
  },
  thresholds: {
    http_req_failed: ['rate<0.01'],
    'http_req_duration{group:::light}': ['p(95)<300'],
    'http_req_duration{group:::analytics}': ['p(95)<1500'],
  },
}

const H = { headers: { Accept: 'application/json', Cookie: __ENV.COOKIE, Referer: __ENV.BASE } }
const W = `${__ENV.BASE}/api/v1/workspaces/${__ENV.WS}`

export default function () {
  for (const p of ['/api/v1/me']) check(http.get(`${__ENV.BASE}${p}`, Object.assign({ tags: { group: '::light' } }, H)), { ok: (r) => r.status === 200 })
  for (const p of ['/sites', '/writer/articles', '/research/campaigns', '/billing/credits', '/agent/runs']) check(http.get(W + p, Object.assign({ tags: { group: '::light' } }, H)), { ok: (r) => r.status === 200 })
  for (const p of ['/analytics/overview', '/analytics/queries', '/analytics/pages']) check(http.get(W + p, Object.assign({ tags: { group: '::analytics' } }, H)), { ok: (r) => r.status === 200 })
  sleep(1)
}
