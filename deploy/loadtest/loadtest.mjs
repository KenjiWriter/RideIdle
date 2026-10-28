#!/usr/bin/env node
// A load test of the API of RideIdle: many drivers at once, each doing what the game does by itself while it is open (asking for the run every 15 seconds,
// now and then the garage, the map, the fleet and the shop). It needs no package: Node 18 or later.
//
//   1. On the machine that has the database make the accounts (the API lets an address sign up ten guests an hour, so they are not made through it):
//        cd api && php artisan loadtest:accounts 200 --output=/tmp/tokens.json
//   2. Run the test from another machine (see the note about the limit of an address below):
//        node deploy/loadtest/loadtest.mjs --base=https://raceidle.example --tokens=tokens.json --users=200 --duration=300
//   3. Remove the accounts and everything they did:
//        cd api && php artisan loadtest:cleanup
//
// Options:  --base=URL (default http://127.0.0.1:8000)   --tokens=FILE (required)   --users=N (all the accounts of the file)   --duration=SECONDS (60)
//           --poll=MS (15000, what the game does; less is a harder test)   --ramp=SECONDS (10, the drivers come in over this time)   --timeout=MS (15000)
//           --max-p95=MS (1000) and --max-error-rate=PERCENT (1): the test fails (exit 1) when the 95th percentile of a request is slower, or more requests fail
//           --json (the report as JSON)
//
// What it shows: for each kind of request how many, how many answered, and how long they took (median, 95th, 99th, longest); how many were refused by a limit (429) or
// failed (5xx, no answer); and how many requests a second the server took. A 429 is not a failure of the game: nginx lets an address make 20 requests a second
// (deploy/nginx/rideidle-global.conf), so one machine tells how the limit behaves, and to measure what the server can do the test has to come from several
// addresses (or the limit has to be raised for the time of the test). The test does not buy or sell anything: it reads, and the server settles the runs as it does for a driver.

import { readFileSync } from 'node:fs'
import { performance } from 'node:perf_hooks'

const options = Object.fromEntries(
  process.argv.slice(2).flatMap((argument) => {
    const match = /^--([a-z0-9-]+)(?:=(.*))?$/.exec(argument)

    return match ? [[match[1], match[2] ?? 'true']] : []
  }),
)

const base = String(options.base ?? 'http://127.0.0.1:8000').replace(/\/$/, '')
const durationMs = Number(options.duration ?? 60) * 1000
const pollMs = Number(options.poll ?? 15000)
const rampMs = Number(options.ramp ?? 10) * 1000
const timeoutMs = Number(options.timeout ?? 15000)
const maxP95 = Number(options['max-p95'] ?? 1000)
const maxErrorRate = Number(options['max-error-rate'] ?? 1)

if (!options.tokens) {
  console.error('Say where the tokens are: --tokens=FILE (php artisan loadtest:accounts N --output=FILE).')
  process.exit(2)
}

const accounts = JSON.parse(readFileSync(String(options.tokens), 'utf8'))
const users = Math.min(Number(options.users ?? accounts.length), accounts.length)

if (!Number.isFinite(durationMs) || durationMs <= 0 || !Number.isFinite(pollMs) || pollMs < 100 || users < 1) {
  console.error('The numbers are wrong: duration, poll (at least 100 ms) and users (at least 1).')
  process.exit(2)
}

/** @type {{name: string, ms: number, status: number | string}[]} */
const samples = []
const startedAt = performance.now()

async function call(token, name, path, init = {}) {
  const controller = new AbortController()
  const timer = setTimeout(() => controller.abort(), timeoutMs)
  const began = performance.now()

  try {
    const response = await fetch(base + path, {
      ...init,
      signal: controller.signal,
      headers: { Accept: 'application/json', 'Content-Type': 'application/json', Authorization: `Bearer ${token}`, ...init.headers },
    })
    const body = await response.text()

    samples.push({ name, ms: performance.now() - began, status: response.status })

    return { status: response.status, json: body ? safeJson(body) : null }
  } catch (failure) {
    samples.push({ name, ms: performance.now() - began, status: failure?.name === 'AbortError' ? 'timeout' : 'network' })

    return { status: 0, json: null }
  } finally {
    clearTimeout(timer)
  }
}

function safeJson(text) {
  try {
    return JSON.parse(text)
  } catch {
    return null
  }
}

const sleep = (ms) => new Promise((resolve) => setTimeout(resolve, ms))

/** One driver: has a car on the track (makes and starts one when there is none), then asks for what the screens ask for until the time is up. */
async function driver(account, index) {
  await sleep((rampMs * index) / users)

  const { token } = account
  // The first question is about a driver who may have no car yet: its 404 is the answer, so it is counted apart.
  let run = await call(token, 'GET /runs/current (first)', '/api/v1/runs/current')

  if (run.status === 404) {
    const made = await call(token, 'POST /runs', '/api/v1/runs', { method: 'POST', body: JSON.stringify({ car: 'rdzak-gt-4', track: 'zlomowo-street' }) })
    const id = made.json?.data?.id

    if (id) {
      await call(token, 'POST /runs/{id}/start', `/api/v1/runs/${id}/start`, { method: 'POST' })
    }
  }

  for (let round = 0; performance.now() - startedAt < durationMs; round++) {
    run = await call(token, 'GET /runs/current', '/api/v1/runs/current')

    // The garage and the map when a screen opens, the fleet and the shop now and then: what a driver who looks around asks for.
    if (round % 4 === 1) {
      await call(token, 'GET /garage', '/api/v1/garage')
    }

    if (round % 4 === 2) {
      await call(token, 'GET /map', '/api/v1/map')
    }

    if (round % 8 === 3) {
      await call(token, 'GET /cars', '/api/v1/cars')
      await call(token, 'GET /shop', '/api/v1/shop')
    }

    // A little jitter, so that the drivers do not ask in step.
    await sleep(pollMs * (0.9 + Math.random() * 0.2))
  }

  return run.status
}

function percentile(sorted, share) {
  if (sorted.length === 0) {
    return null
  }

  return sorted[Math.min(sorted.length - 1, Math.floor(sorted.length * share))]
}

const round1 = (value) => (value === null ? null : Math.round(value * 10) / 10)

function report() {
  const seconds = (performance.now() - startedAt) / 1000
  const names = [...new Set(samples.map((sample) => sample.name))].sort()
  const rows = names.map((name) => {
    const own = samples.filter((sample) => sample.name === name)
    const answered = own.filter((sample) => typeof sample.status === 'number' && sample.status >= 200 && sample.status < 300)
    const sorted = answered.map((sample) => sample.ms).sort((a, b) => a - b)

    return {
      request: name,
      count: own.length,
      ok: answered.length,
      limited: own.filter((sample) => sample.status === 429).length,
      server_errors: own.filter((sample) => typeof sample.status === 'number' && sample.status >= 500).length,
      no_answer: own.filter((sample) => sample.status === 'timeout' || sample.status === 'network').length,
      other: own.filter((sample) => typeof sample.status === 'number' && sample.status >= 400 && sample.status < 500 && sample.status !== 429).length,
      p50_ms: round1(percentile(sorted, 0.5)),
      p95_ms: round1(percentile(sorted, 0.95)),
      p99_ms: round1(percentile(sorted, 0.99)),
      max_ms: round1(sorted.length ? sorted[sorted.length - 1] : null),
    }
  })

  const total = samples.length
  const failures = samples.filter((sample) => sample.status === 'timeout' || sample.status === 'network' || (typeof sample.status === 'number' && sample.status >= 500)).length
  const all = samples.filter((sample) => typeof sample.status === 'number' && sample.status >= 200 && sample.status < 300).map((sample) => sample.ms).sort((a, b) => a - b)
  const p95 = round1(percentile(all, 0.95))
  const errorRate = total === 0 ? 0 : (failures / total) * 100

  return {
    base,
    users,
    seconds: round1(seconds),
    poll_ms: pollMs,
    requests: total,
    requests_per_second: round1(total / seconds),
    limited: samples.filter((sample) => sample.status === 429).length,
    failures,
    error_rate_percent: round1(errorRate),
    p95_ms: p95,
    thresholds: { max_p95_ms: maxP95, max_error_rate_percent: maxErrorRate },
    passed: failures === 0 || errorRate <= maxErrorRate ? p95 === null || p95 <= maxP95 : false,
    rows,
  }
}

console.error(`${users} drivers against ${base} for ${durationMs / 1000} s (they ask every ${pollMs} ms, and come in over ${rampMs / 1000} s)`)
await Promise.all(accounts.slice(0, users).map((account, index) => driver(account, index)))

const result = report()

if (options.json) {
  console.log(JSON.stringify(result, null, 2))
} else {
  console.table(result.rows)
  console.log(
    `${result.requests} requests in ${result.seconds} s (${result.requests_per_second} a second); ${result.limited} refused by a limit, ${result.failures} failed (${result.error_rate_percent}%); ` +
      `the 95th percentile of the answers is ${result.p95_ms} ms (the test fails above ${maxP95} ms or ${maxErrorRate}% failed).`,
  )
  console.log(result.passed ? 'PASSED' : 'FAILED')
}

process.exit(result.passed ? 0 : 1)
