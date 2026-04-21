# Gold Terminal — goldprice.corgano.com

Source for **goldprice.corgano.com**, part of the Corgano Network. A dense, fast-loading dashboard for precious metals — built for people who keep the page open all day. Prices refresh on your schedule, not ours.

Static-first HTML/CSS/JS frontend, PHP backend aggregating MetalpriceAPI + Finnhub + RSS feeds + the corgano.com WordPress site, with Kitco fallback charts when live feeds fail.

---

## What's in here

| Section | Purpose |
|---|---|
| Live prices | Gold, silver, platinum, palladium, rhodium — refresh 30s/60s/5m |
| SVG charts | Timeframe tabs (1D/1W/1M/3M/1Y/5Y), no TradingView dependency |
| News firehose | Every 10min from Kitco, Mining.com, NorthernMiner, BullionVault |
| Corgano editorial | Pulled from corgano.com via WordPress REST, filtered by tag (`gold`, `precious-metals`, etc.) |
| Multi-timeframe perf | Today / 30D / 6M / 1Y / 5Y / 20Y in one compact table |
| Per-unit strip | Live price in oz / gram / kilo / tola at a glance |
| Mega footer | 322 links for SEO (country pages, calculator tools, bullion products) |
| AI widget | Pre-filled prompts into Perplexity/ChatGPT/Claude/Google — user's own free account, zero Corgano-side cost |
| Kitco fallback | Auto-serves hotlinked Kitco charts when metals feed fails |
| /performance.html | Standalone page: annual % change × 9 currencies, 2011–2026 |

---

## Repository structure

```
/
├── .cpanel.yml             # cPanel deployment script — runs on every deploy
├── .gitignore              # excludes secrets, cache, logs, OS cruft
├── README.md               # this file
├── public/                 # → copied to subdomain docroot (web-accessible)
│   ├── index.html          # main dashboard
│   ├── performance.html    # annual % change reference page
│   ├── robots.txt
│   └── api/
│       └── dashboard.php   # backend endpoint (returns unified JSON)
└── private/                # → copied above docroot (NOT web-accessible)
    ├── config.example.php  # template — copy to config.php and fill in real keys
    ├── README.md           # notes about the private dir
    └── cron/
        ├── fetch-news.php  # RSS harvester (run every 10 min)
        └── tag-news.php    # optional LLM classifier (run daily if OpenAI key set)
```

---

## First-time deployment on NameHero

### Step 1 — Get your API keys

Before touching cPanel, sign up for the services the dashboard pulls from. You'll paste these keys into `config.php` later.

| Service | What for | Cost | Required? |
|---|---|---|---|
| [MetalpriceAPI](https://metalpriceapi.com/) | Live PM prices + FX | ~$25/month (Standard plan) | **Yes** |
| [Finnhub](https://finnhub.io/) | Mining stock quotes | Free tier | Yes |
| [OpenAI](https://platform.openai.com/) | News tagging (GPT-4o-mini) | ~$1–2/month | Optional |

WordPress side: on corgano.com, make sure you've created the relevant tags (`gold`, `precious-metals`, `bullion`, `mining`, `central-banks`) and tagged at least one published post with them, otherwise the "Contributed Analysis" panel will stay on demo content. Check what tags exist: `https://corgano.com/wp-json/wp/v2/tags?per_page=100`

### Step 2 — Create the subdomain in cPanel

1. Log into NameHero cPanel.
2. **Domains** → **Create New Domain** (or **Subdomains** on older cPanel versions).
3. Subdomain: `goldprice`, parent: `corgano.com`. Full domain will be `goldprice.corgano.com`.
4. Document root: **accept the default** (`public_html/goldprice/`). If you pick a custom path, you'll need to update `.cpanel.yml` to match.

Write down the cPanel username (top-right of the cPanel UI, or run `whoami` in SSH). You'll need it in step 4.

### Step 3 — Connect this GitHub repo

In cPanel:
1. Find **Git™ Version Control** (usually under "Files" section).
2. Click **Create**.
3. Clone URL: `https://github.com/Corganos/goldprice.git`
4. Repository path: accept the default (usually `/home/YOURUSER/repositories/goldprice`).
5. Repository name: `goldprice`.
6. Click **Create** — cPanel will clone immediately.

### Step 4 — Edit `.cpanel.yml` for your account

Open `.cpanel.yml` in this repo. Two lines need your cPanel username:

```yaml
- export DEPLOYPATH=/home/corganos/public_html/goldprice/
- export PRIVATEPATH=/home/corganos/private/
```

Replace `corganos` with your actual cPanel username (from step 2). If you picked a custom document root, also change `DEPLOYPATH` accordingly.

Commit and push:
```bash
git commit -am "Configure .cpanel.yml for production paths"
git push origin main
```

### Step 5 — First deploy (and create your config.php)

Back in cPanel → **Git Version Control** → your `goldprice` repo → **Manage** → **Pull or Deploy** tab:
1. Click **Update from Remote** — pulls latest from GitHub.
2. Click **Deploy HEAD Commit** — runs `.cpanel.yml`.

You should see green checkmarks as each file copies. If anything errors, check the deployment log — usually it's a wrong `DEPLOYPATH` or the subdomain directory doesn't exist yet (go back to step 2).

After this first deploy, `config.example.php` will exist at `~/private/config.example.php`. You need to turn it into a real config with your keys.

SSH in (NameHero: **Terminal** in cPanel, or `ssh YOURUSER@corgano.com`):

```bash
cd ~/private
cp config.example.php config.php
nano config.php
```

Fill in:
- `metalprice_key` — from your MetalpriceAPI dashboard
- `finnhub_key` — from your Finnhub dashboard
- `openai_key` — from OpenAI (or leave empty string to disable tagging)
- `corgano_tag_slugs` — edit if your WordPress tags use different slugs

Save (in nano: Ctrl+O, Enter, Ctrl+X).

### Step 6 — Set up cron jobs

In cPanel → **Cron Jobs**, add two entries. Replace `YOURUSER` with your actual username.

**News harvester** — runs every 10 minutes, keeps the news feed fresh:
```
*/10 * * * * /usr/bin/php /home/YOURUSER/private/cron/fetch-news.php >> /home/YOURUSER/private/cron.log 2>&1
```

**News classifier** — runs daily at 4:15 AM, adds topic tags to headlines using OpenAI. Skip this if you left `openai_key` empty:
```
15 4 * * * /usr/bin/php /home/YOURUSER/private/cron/tag-news.php >> /home/YOURUSER/private/cron.log 2>&1
```

Note: NameHero sometimes uses `/usr/local/bin/php` instead of `/usr/bin/php`. If your cron log shows "php not found", try the other path. `which php` in SSH will confirm.

### Step 7 — Verify

In your browser:

| URL | Expected |
|---|---|
| `https://goldprice.corgano.com/` | Full dashboard renders. First load shows demo data; refreshes to live within ~2 seconds. |
| `https://goldprice.corgano.com/api/dashboard.php` | Raw JSON blob starting with `{"ok":true,`. If you see `{"ok":false,"error":"config.php not found"}`, the path in `dashboard.php` didn't match your layout — see "Troubleshooting" below. |
| `https://goldprice.corgano.com/performance.html` | Annual % change table. |

If the dashboard loads but news section is empty, the cron hasn't run yet. Wait 10 minutes, or trigger manually:

```bash
php ~/private/cron/fetch-news.php
```

If the "FALLBACK" pill appears in the top right, MetalpriceAPI isn't working — usually a bad key or exhausted quota. Check the JSON endpoint's `warnings` field.

---

## Ongoing deployment

After first-time setup, the loop is:

```bash
# make your changes locally
git commit -am "Add 12 more country pages"
git push origin main
```

Then cPanel → Git Version Control → your repo → **Pull or Deploy** → **Update from Remote** → **Deploy HEAD Commit**.

Or set up a GitHub webhook to auto-deploy on push (if NameHero's cPanel supports it — varies by plan).

---

## Local development

To preview locally before committing:

```bash
cd public
php -S localhost:8000
```

Open http://localhost:8000 — the dashboard will try to fetch `/api/dashboard.php` which runs via the PHP built-in server. For real data, you'll need a local `../private/config.php`. Without it, the frontend stays on demo data and shows the fallback pill — which is actually useful for testing graceful degradation.

To test the Corgano WordPress feed locally without other APIs:
```bash
curl "http://localhost:8000/api/dashboard.php" | jq '.corgano_articles'
```

---

## Status — what's live vs what's still demo

Being honest: the dashboard is **deployable today** and will look great with live data, but some panels are still on placeholder content. What's real and what isn't:

### Fully live after deployment
- Spot prices for all 4 metals + FX conversions (16 currencies)
- Per-unit conversion strip (oz/gram/kilo/tola)
- "Today" row in the multi-timeframe performance table
- News firehose (once cron runs)
- Mining stock tables (once Finnhub key is set)
- Corgano editorial (once you tag WordPress posts)
- FX table (gold priced in 16 currencies)
- Gold/silver ratio, gold/Pt ratio, etc.
- KGX move-attribution panel
- Kitco fallback charts when feeds fail

### Still on demo data (easy follow-up wiring)
- Multi-timeframe 30D/6M/1Y/5Y/20Y rows — needs a historical snapshot endpoint
- Main SVG chart — uses seeded pseudo-random data; needs real OHLC (MetalpriceAPI time-series add-on)
- Mining indices, correlated markets, London Fix, lease rates, futures, commentary, press releases, featured miners, technical analyses — static demo content
- Annual performance page — numbers are plausible but not verified against LBMA; replace with real data before treating as authoritative

### Intentionally empty (SEO scaffolding for later)
- ~300 mega-footer links point to `#` — country pages, calculator tools, bullion product pages don't exist yet. The footer structure is there so when you build a country page (`/gold-price/india/`), the internal link is already there network-wide.

---

## Troubleshooting

**Dashboard loads but shows "FALLBACK" pill immediately**
→ `config.php` not found, or `metalprice_key` is wrong/exhausted. Visit `/api/dashboard.php` directly — the JSON will have an `error` or `warnings` field explaining.

**Dashboard 500 errors**
→ PHP version probably. Needs PHP 8.1+. cPanel → MultiPHP Manager → set `goldprice.corgano.com` to PHP 8.1 or newer.

**"config.php not found" error**
→ `dashboard.php` tries two common paths. If your layout is unusual, add an absolute path to the `$configCandidates` array at the top of `public/api/dashboard.php`.

**News section stays empty after 10+ minutes**
→ Cron isn't running. Check `~/private/cron.log` for errors. Common: wrong path to `php` binary (try `/usr/local/bin/php` instead of `/usr/bin/php`).

**Corgano commentary panel stays on demo content**
→ Either: (a) no WP posts are tagged with the slugs in `config.php`, or (b) the tag slugs in config don't match what's on corgano.com. Check tags at `https://corgano.com/wp-json/wp/v2/tags?per_page=100`.

**"Deploy HEAD Commit" errors with permission denied**
→ cPanel sometimes needs `~/public_html/goldprice/` to exist first. Create it via cPanel File Manager, or SSH: `mkdir -p ~/public_html/goldprice ~/private`.

---

## Related projects

The Corgano Network subdomains share this codebase — only `corgano_tag_slugs` in `config.php` differs:

| Subdomain | Tags pulled | Status |
|---|---|---|
| goldprice.corgano.com | `gold`, `precious-metals`, `bullion`, `mining`, `central-banks` | Active (this repo) |
| silverprice.corgano.com | `silver`, `industrial-metals`, `mining` | Planned |
| bitcoinprice.corgano.com | `bitcoin`, `crypto`, `digital-assets` | Planned |

---

## License

Private. © 2026 Corgano Network.
