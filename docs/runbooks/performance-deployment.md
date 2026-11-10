# Performance and Deployment Runbook

Companion to [ADR 0029](../adr/0029-performance-optimizations.md). Everything under "Ready-to-use
configuration" is documentation only: nothing here was installed or changed on the development
machine. Apply it on the machine you want to speed up, one step at a time, and re-run the checks at
the end.

## 1. Quick wins on the development PC (no new software)

| Step | Command / change | What it fixes |
| --- | --- | --- |
| Serve the optimised frontend | `powershell -File scripts/start-local.ps1 -Production` | `next dev` compiles on demand and ships unminified, code-unsplit bundles. `-Production` builds once (a few minutes) and runs `next start`. |
| Build without touching a running dev server | `set NEXT_DIST_DIR=.next-perf` then `npx next build` in `frontend/` | Writes to `.next-perf` instead of `.next`. Compare the route chunk sizes printed by the build. |
| Enable OPcache | In `C:\xampp\php\php.ini` uncomment `zend_extension=opcache` and set `opcache.enable=1`, `opcache.memory_consumption=192`, `opcache.interned_strings_buffer=16`, `opcache.max_accelerated_files=20000`, `opcache.validate_timestamps=1`, `opcache.revalidate_freq=2`; restart `php artisan serve` / Apache | PHP re-compiles every Laravel file on every request without it. Keep `validate_timestamps=1` while developing (set it to `0` in production and reload PHP after each deploy). |
| Cache config and routes | `cd backend && php artisan optimize` (undo with `php artisan optimize:clear`) | Skips parsing config and route files per request. **Do not run the test suite with a cached config**; `.env` and `.env.testing` are ignored until `optimize:clear`. |
| Quieter logs | `LOG_LEVEL=warning` in `backend/.env` | `debug` logs every handled error. The N+1 guard now writes one line per model and relation, not per violation. |
| Faster local writes | In `C:\xampp\mysql\bin\my.ini`, `innodb_flush_log_at_trx_commit=2` | Removes one fsync per commit. Development only: an OS crash can lose the last second of commits. |
| Watch the database | Set `LAZY_LOADING_VIOLATIONS=log`, run the suite, read `storage/logs/laravel.log` | Lists every N+1 in one run instead of failing at the first. Set it back to `throw`. |

`php artisan serve` is a single process (`PHP_CLI_SERVER_WORKERS` is ignored on Windows), so one slow
request queues every other request. Serving the API through Apache (section 2) is the real fix for
concurrency; the steps above only make each request cheaper.

## 2. Ready-to-use configuration

### 2.1 API behind Apache (XAMPP) instead of `artisan serve`

```apache
# httpd-vhosts.conf
Listen 8000
<VirtualHost *:8000>
    DocumentRoot "C:/xampp/htdocs/GRC-ENROLLMENT/backend/public"
    <Directory "C:/xampp/htdocs/GRC-ENROLLMENT/backend/public">
        AllowOverride All
        Require all granted
    </Directory>
</VirtualHost>
```

Apache runs several PHP workers, so slow requests stop blocking each other. Compression is already done
by Laravel (`API_COMPRESS_JSON=true`). If you prefer Apache to do it, enable `mod_deflate`, add
`AddOutputFilterByType DEFLATE application/json`, and set `API_COMPRESS_JSON=false` so a body is never
compressed twice.

### 2.2 Production Next.js behind a reverse proxy / CDN

The frontend is client-rendered (ADR 0013) and calls the Laravel API directly. In front of `next start`:

- Static assets: `/_next/static/*` are content-hashed. Serve them with
  `Cache-Control: public, max-age=31536000, immutable` (`next start` already sends this) and let a CDN
  cache them at the edge. Enable brotli or gzip for text.
- HTML and the API: never cache. Every private API GET sends `Cache-Control: no-store, private` on
  purpose; a CDN must pass `/api/*` straight through.
- With a fixed API origin, add `<link rel="preconnect" href="https://api.example.edu">` and consider
  `next/font` for the two font families (both are skipped in development because the API origin is
  rewritten for phones on the LAN).

### 2.3 Several ML replicas behind one address (load balancer)

There is one ML process today, so there is nothing to balance yet. When there are two or more (the
Random Forest / XGBoost fit is CPU-bound), put nginx in front and point Laravel at it:

```nginx
upstream prediction_service {
    least_conn;
    server 10.0.0.11:8100;
    server 10.0.0.12:8100;
    keepalive 16;
}

server {
    listen 8100;
    location /internal/ {
        proxy_pass http://prediction_service;
        proxy_http_version 1.1;
        proxy_set_header Connection "";
        proxy_set_header X-Request-ID $request_id;
        proxy_read_timeout 35s;   # a little above Laravel's 30 s client timeout
    }
}
```

```dotenv
PREDICTION_SERVICE_URL=http://ml-lb-host:8100
```

The prediction API is private (`/internal/v1/...`): never expose it publicly. Laravel already sends
`X-Request-ID`, retries twice with a 2 s connect timeout, and caches successful predictions for one hour.
Run each replica with `uvicorn app.main:app --workers 2` if the host has spare cores.

### 2.4 Run ML jobs outside the web request

`QUEUE_CONNECTION=sync` (the `.env.example` default) executes a schedule-generation job inside the
HTTP request, holding a PHP process for up to 30 s. For a shared environment:

```powershell
cd backend
php artisan make:queue-table    # or: php artisan queue:table, then migrate
php artisan migrate
# .env
QUEUE_CONNECTION=database
# start a worker next to the API
php artisan queue:work --tries=2 --timeout=60
```

The frontend already polls the run status, so it needs no change.

### 2.5 Database connection pooling

PHP opens one connection per request, so a classic pool does not apply. In order of effort:
`PDO::ATTR_PERSISTENT => true` under `options` in `config/database.php` (one line; watch
`max_connections`); Laravel Octane / FrankenPHP or RoadRunner (workers keep connections; a Linux
production move); ProxySQL or MaxScale in front of MariaDB for a multi-server deployment. None is needed
at the current scale.

## 3. What changed in the code (so you can reason about it)

- Fewer queries per request: `/sections`, `/enrollments` (and its queue positions), payment confirmation.
- Fewer writes: Sanctum stamps `last_used_at` at most every five minutes.
- Fewer requests: polling slowed and paused in hidden tabs; search boxes debounced 300 ms; paging keeps
  the previous page on screen.
- Smaller responses: gzip for JSON over 1 KB (`API_COMPRESS_JSON`).
- Smaller first load: every workspace is its own chunk.
- Indexes: see `2026_09_25_000001_add_performance_indexes.php`.

## 4. Checks after you change anything

1. `cd backend && php artisan test` (against a test database) and `LAZY_LOADING_VIOLATIONS=log php artisan test`
   for the N+1 inventory. A regression shows up as a `LazyLoadingViolationException` or a query-count test.
2. `EXPLAIN` the two list queries on a realistic copy of the data:
   `EXPLAIN SELECT ... FROM enrollments WHERE academic_term_id = ? AND status = ? ORDER BY submitted_at DESC`
   should use `enrollments_term_status_submitted_at_idx`.
3. `cd frontend && npx tsc --noEmit && npx eslint . && npx vitest run`, then build with
   `NEXT_DIST_DIR=.next-perf npx next build` and read the per-route sizes.
4. In Chrome DevTools, throttle to "Fast 4G", hard-reload `/portal`, and check: the shell paints before
   the workspace chunk arrives, no request repeats every 5 seconds, and `Content-Encoding: gzip` appears on
   large `/api/v1/*` responses.
5. Watch `storage/logs/laravel.log`: it should not grow by megabytes per hour in normal use.
