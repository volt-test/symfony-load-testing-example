# Symfony load testing example

A production-mode Symfony 7.4 shop built as a load-testing target for
[VoltTest](https://volt-test.com), and the code behind the article
[Symfony Load Testing: FrankenPHP vs PHP-FPM](https://volt-test.com/blog/symfony-load-testing).
Products, cart and checkout are exposed twice: a JSON API secured with JWT, and
server-rendered HTML pages with session cookies and CSRF-protected forms. The
`loadtest/` folder holds the VoltTest scenarios written in PHP and the raw results
of every run quoted in the article.

## Stack

| Piece | Choice | Why it matters under load |
|---|---|---|
| Runtime | FrankenPHP **worker mode** (`runtime/frankenphp-symfony`) | Kernel boots once per worker instead of once per request |
| PHP | 8.4, `opcache.preload` of the compiled container, `validate_timestamps=0` | Prod opcache settings, nothing is re-parsed |
| Database | PostgreSQL via Doctrine ORM (16 in the Compose stack, 18 on Fly for the article's runs) | Stock is reserved with a conditional `UPDATE` inside a transaction |
| Sessions | Redis (`RedisSessionHandler`, Predis) | No file locking; works on more than one machine |
| API auth | `json_login` + LexikJWTAuthenticationBundle, stateless firewall | Bearer tokens, no session on `/api/*` |
| Web auth | `form_login` with session CSRF (`_csrf_token`), Form component CSRF (`add_to_cart[_token]`) | The recipe's stateless CSRF was removed on purpose (see `config/packages/csrf.yaml`) |

Failure paths return real status codes so a load test can assert on status alone:
invalid form → **422**, bad CSRF on checkout → **403**, empty cart / out of stock → **409**,
missing or bad JWT → **401**, wrong password on the API → **401**, wrong password on the
form → **302 to /login** (then `GET /cart` answers 302 instead of 200).

## Endpoints

### JSON API (`/api/v1`)
| Method | Path | Auth | Notes |
|---|---|---|---|
| POST | `/login` | – | `{"email","password"}` → `{"token"}` |
| GET | `/products?page=1&per_page=12` | – | `{data: [...], meta: {...}}` |
| GET | `/products/{id}` | – | 404 if unknown |
| GET | `/me` | Bearer | |
| GET | `/cart` | Bearer | |
| POST | `/cart/items` | Bearer | `{"product_id","quantity"}` → 201, 422 on bad quantity |
| POST | `/checkout` | Bearer | 201 with `data.order_id`, 409 if cart empty |

### HTML
| Method | Path | Notes |
|---|---|---|
| GET/POST | `/login` | `_username`, `_password`, `_csrf_token` (token id `authenticate`) |
| POST | `/logout` | `_csrf_token` (token id `logout`) |
| GET | `/` | 12 featured `a.product-link[data-product-id]` |
| GET | `/products?page=N` | catalog |
| GET | `/products/{id}` | `h1.product-name`, `span.product-price`, `form.add-to-cart-form` with `add_to_cart[product_id]`, `add_to_cart[quantity]`, `add_to_cart[_token]` |
| POST | `/cart/add` | 302 → `/cart`, 422 on invalid/CSRF |
| GET | `/cart` | `table.cart-table`, `form.checkout-form` with `_token` (token id `checkout`) |
| POST | `/checkout` | 302 → `/orders/{id}`, 403 on bad CSRF, 303 → `/cart` if empty |
| GET | `/orders/{id}` | `strong.order-reference`, `span.order-status` |
| GET | `/health` | checks DB + Redis |

## Run it locally

```bash
docker compose up --build -d          # app :8088, postgres :5433, redis :6380
# first time only: seed the catalog and 30,000 users (password: "password")
docker compose exec app php bin/console doctrine:fixtures:load --no-interaction
docker compose exec app php bin/console app:export-users /tmp/users.csv
for f in users users-1 users-2 users-3; do docker compose cp app:/tmp/$f.csv loadtest/data/$f.csv; done
curl localhost:8088/health
```

`MIGRATE_ON_START=1` in `compose.yaml` runs migrations on boot. The app container
runs `APP_ENV=prod`, so template or config changes need a rebuild.

For a plain dev loop without Docker for the app itself:

```bash
docker compose up -d postgres redis
php bin/console lexik:jwt:generate-keypair --skip-if-exists
php bin/console doctrine:migrations:migrate -n
php -d memory_limit=1G bin/console doctrine:fixtures:load -n   # dev mode profiles every query; prod needs no extra memory
php bin/console app:export-users
php -S 127.0.0.1:8000 -t public       # dev mode, single-threaded: fine for curl, useless for load
```

## Load test (`loadtest/`)

```bash
cd loadtest && composer install     # PHP 8.2+, volt-test/php-sdk ^1.2.2 (downloads the engine on first run)
TARGET_URL=http://localhost:8088 VUS=100 DURATION=1m php symfony-shop-test.php                       # headless, constant load
TARGET_URL=http://localhost:8088 STAGES="1m:50,1m:100,1m:200,1m:400,1m:800" php symfony-shop-test.php  # headless, staged
TARGET_URL=https://<your-app>.fly.dev DATA_DIR=data/fly VOLTTEST_API_KEY=... STAGES="1m:50,1m:100,1m:200,1m:400,1m:800" php symfony-shop-test.php   # cloud
```

Environment knobs: `VUS`/`DURATION`/`RAMP_UP` (constant load) or `STAGES` (staged), `SCENARIOS`
(comma list of `api-browse,api-checkout,html,flash-sale`), `DATA_DIR` (folder holding the
`users-*.csv` shards; keep a separate export per deployment because product ids differ),
`REGIONS` (cloud only, e.g. `us-east-1:100`; some plans enforce a minimum number of VUs per
region when regions are given explicitly).

`symfony-shop-test.php` runs four weighted scenarios (API browse 50 / API checkout 20 /
HTML shopper 30 / API flash sale 15). `SCENARIOS=flash-sale` (comma list) runs a subset. Each scenario reads its own disjoint shard (`data/users-1.csv` …
`users-3.csv`, written by `app:export-users`) in `unique` mode, so one account is
never in two flows at once. Each CSV row carries a
`product_id` and `quantity` so traffic spreads across the catalog instead of
hammering product #1. The HTML scenario uses `autoHandleCookies()`, pulls every
CSRF token out of the page with `extractFromHtml`, and logs out at the end because
a virtual user keeps its cookie jar for the whole run.

### Flash sale: proving checkout never oversells

The fixtures seed one scarce product (`flash-sale-studio-headphones`, 500 units) and
`app:export-users` reserves the last quarter of the users for it in
`data/users-flash-sale.csv`. Every virtual user in that scenario buys that one product,
so it sells out within seconds and the rest of the checkouts answer **409**. That is the
correct outcome, and the SDK can only assert a single expected status per step, so the
checkout step is left unvalidated and the assertion lives in the database:

```bash
docker compose exec app php bin/console app:flash-sale reset     # stock back to 500, carts cleared
cd loadtest && TARGET_URL=http://localhost:8088 SCENARIOS=flash-sale VUS=100 DURATION=30s php symfony-shop-test.php
sleep 5   # let in-flight checkouts commit; the engine stops sending before the server finishes
docker compose exec app php bin/console app:flash-sale report    # fails if stock < 0 or units sold > 500
```

Expected report: stock 0, units sold 500, exit code 0. At 100 VUs on Docker Desktop the
500 units took about 70 seconds to sell out, so give it `DURATION=90s` or more. Stock is reserved with
`UPDATE product SET stock = stock - :qty WHERE id = :id AND stock >= :qty` inside the
checkout transaction, so concurrent buyers serialize on that row and the last unit is
sold exactly once. That row lock is also where latency climbs under contention.

## Runtime comparison: FrankenPHP worker mode vs nginx + php-fpm

`Dockerfile.fpm` builds the same app on nginx + php-fpm (PHP 8.4, same opcache and
preload settings, `pm.max_children` = 9 to match FrankenPHP's thread count on a 4-core
box). It runs as the `fpm` compose profile on host port 8089 against the same database
and Redis, so a load test compares runtimes, not setups:

```bash
docker compose --profile fpm up -d fpm
curl localhost:8089/health
cd loadtest && TARGET_URL=http://localhost:8089 VUS=100 DURATION=1m php symfony-shop-test.php
```

On Fly, build the FPM variant with `fly deploy -c fly.fpm.toml --build-only --push --image-label fpm-vN`
(flyctl ignores `--dockerfile` when the config names one) and deploy that image to the same app;
redeploy the FrankenPHP image to go back.

## Deploy to Fly.io

See the comment block at the top of `fly.toml`. The article's runs used three Fly apps in
`iad`: the app on one `performance-2x` machine (2 dedicated vCPUs), a single-node Fly
Postgres, and a plain `redis:7-alpine` machine reached over the private network. Rename the
apps in `fly.toml` / `fly.fpm.toml` to your own.

## Results of the article's runs (`loadtest/results/`)

Raw JSON pulled from the VoltTest API (`results`, `metrics`, `requests`, `errors`) for each run:

| Files | Run | Setup |
|---|---|---|
| `run1-*` | 2,000 VUs, 5 min, constant | FrankenPHP, bcrypt cost 13 — the collapse (93% timeouts) |
| `run1b-*` | staged 50→800 | FrankenPHP, cost 13 — baseline |
| `run2-*` | staged 50→800 | nginx + php-fpm, cost 13 |
| `run4-*` | staged 50→800 | FrankenPHP, cost 10 |
| `run3-*` | flash sale only, 500 VUs, 2 min | FrankenPHP, cost 10 — 500 sold, never oversold |

All on the same Fly machine, database and Redis, 2026-09-06. The per-stage tables in the article
are computed from the `metrics` files (the overall series has an empty `scenario_name`).

## License

MIT — see `LICENSE`.
