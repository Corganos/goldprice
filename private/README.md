# Corgano — Private Server Files

This directory sits **above the web-accessible document root**. Files here are NOT served via HTTP.

## What belongs here

```
private/
├── config.php              # your REAL API keys (you create this — never committed to git)
├── config.example.php      # template copied in from the repo on every deploy
├── data/
│   ├── cache/              # runtime cache (auto-created by dashboard.php)
│   ├── news.sqlite         # news DB (auto-created by fetch-news.php)
│   ├── seasonality.json    # monthly-built 20Y seasonality snapshot
│   └── rhodium.json        # optional weekly manual rhodium price override
└── cron/
    ├── fetch-news.php      # RSS harvester — cron every 10 min
  ├── tag-news.php        # optional LLM classifier — cron daily
  └── build-seasonality.php  # rebuilds the seasonality panel monthly
```

## First-deploy checklist

After running **Deploy HEAD Commit** in cPanel for the first time:

```bash
cp config.example.php config.php
nano config.php        # fill in metalprice_key, finnhub_key, etc.
```

Then set up the cron jobs in cPanel → Cron Jobs. See top-level `README.md` in the git repo for exact cron entries.

## Manual overrides

**Rhodium price** — MetalpriceAPI doesn't include rhodium. To show a rhodium row on the dashboard, create `data/rhodium.json`:

```json
{
  "price": 4500.00,
  "change": -50.00,
  "change_pct": -1.10,
  "updated_at": "2026-04-21T12:00:00Z"
}
```

Update this file weekly from Kitco's rhodium page. The dashboard will use it automatically.

## What's NOT here

`config.php` is yours — it stays on the server, never in git. If you need to migrate to a new server, back it up separately.
