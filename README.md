# Market Desk

A private research tool for the Indian stock market (NSE/BSE). It reads the
national and international factors that move Indian equities, scores them, and
turns them into **intraday**, **swing (2–6 weeks)** and **long-term (6–18 months)**
buy/sell calls. Each call comes with an entry, stop-loss, targets, a position size,
the evidence behind it, and a backtest of the same rules on that stock's history.

> Not investment advice. These are rule-based probabilities. Always use a stop-loss.

## What's in it

| Tab | What it does |
|---|---|
| **Market pulse** | One regime score for Indian equities built from world indices (US, Europe, Asia), US & India VIX, US yields, the dollar, USD/INR, crude, gold, copper, the Nifty's trend, Nifty 50 breadth, FII/DII flows, the Nifty option chain (PCR, max pain), news sentiment and your India macro numbers. Also covers sector tailwinds for 28 sectors, sector indices vs Nifty, Nifty levels, top movers, scored headlines and an event radar. |
| **Analyze a stock** | Intraday call (5-minute bars with a 15-minute check), swing call (10-factor daily technical score) and long-term rating (fundamentals vs Indian sector norms + trend + momentum + sector + macro). Charts, support/resistance, candle patterns, fundamentals, sector and macro exposure, stock news, and backtests. |
| **Scanner** | Ranks Nifty 50, sector baskets or your watchlist into intraday buys/shorts, swing buys, long-term candidates and the weakest stocks. |
| **India macro inputs** | RBI repo rate and stance, CPI, GDP, PMI, IIP, GST, fiscal deficit, current account, monsoon, monthly FII/DII, and your own events. No free live feed exists for these, so you type them in. |
| **How it works** | Every weight and threshold, written out. |

The optional **✨ AI notes** have Claude write a research note or a market outlook from the computed data.

## Files

```
index.html          the app (one page, no build step)
api.php             JSON API + password login
lib.php             config, file storage, login, Claude call
market.php          data fetching: Yahoo Finance, NSE, RSS; assembles each view
market_engine.php   the maths: indicators, scores, signals, backtests (no I/O)
sources.php         fallback data: Upstox, CNBC, NSE index snapshot
config.sample.php   copy to config.php and set your password
tools/test-market-engine.php   43 offline checks: php tools/test-market-engine.php
tools/dev-server.php           offline demo with synthetic data
.github/workflows/deploy.yml   optional FTP deploy with config from secrets
```

## Put it online (Hostinger or any PHP 8 host)

1. Make a subdomain or folder, e.g. `markets.yourdomain.com` (hPanel → Domains → Subdomains).
2. Upload every file in this repo into its folder, including `.htaccess`.
3. Copy `config.sample.php` to `config.php` and set `$APP_PASSWORD`. Add `$ANTHROPIC_API_KEY` if you want the AI notes.
4. Use PHP 7.4 or newer, with the cURL, zlib and SimpleXML extensions (all standard on Hostinger).
5. Open the site and sign in.

The app creates a `data/` folder for its cache and your inputs. `.htaccess` blocks
web access to `data/`, `config.php` and `tools/`.

### Or let GitHub deploy it for you

`.github/workflows/deploy.yml` uploads the app over FTP on every push to `main`
and writes `config.php` on the server from secrets. In the repo on GitHub, open
**Settings → Secrets and variables → Actions** and add:

| Secret | Value |
|---|---|
| `FTP_SERVER`, `FTP_USERNAME`, `FTP_PASSWORD` | a **dedicated** FTP account for Market Desk (see below) |
| `APP_PASSWORD` | the password you'll sign in with (stored on the server only as a hash) |
| `ANTHROPIC_API_KEY` | optional, for the AI notes |

**Use an FTP account of its own.** In hPanel → Files → FTP Accounts, create a new
account and set its directory to the app's folder (for a subdomain like
`markets.yourdomain.com`, that subdomain's folder). The account can then only
touch Market Desk. Don't reuse another site's FTP account, because a deploy could
overwrite or delete that site's files.

The workflow uploads into the account's home folder. Only if you point it
somewhere else, add a **Variable** named `FTP_DIR` with the sub-folder, ending in `/`.
Then run **Actions → Deploy to server → Run workflow**.
Until the FTP secrets exist, the workflow does nothing and stays green.

## Run it on your computer

```
cp config.sample.php config.php   # set a password
php -S 127.0.0.1:8080
```
Open http://127.0.0.1:8080. To try it with no internet and made-up data:
`php -S 127.0.0.1:8792 tools/dev-server.php`.

## Data sources and their limits

The app tries Yahoo Finance first. Many shared-hosting servers get a permanent
"429 Too Many Requests" from Yahoo. When that happens, the app remembers it for
30 minutes and uses these sources instead, which do answer servers:

| What | Source |
|---|---|
| NSE/BSE stocks and indices: daily, 5- and 15-minute bars, and today's live bars | Upstox public candle API |
| World indices, VIX, US yields, dollar, rupee, crude, gold, copper, bitcoin | CNBC quote and chart feeds |
| Fundamentals (P/E, ROE, margins, debt/equity, dividend yield, market cap) | Yahoo, else CNBC. CNBC has no growth rates or P/B, so those factors drop out. |
| Nifty P/E, P/B and dividend yield; FII/DII flows; option chain | NSE |
| News | ET, Moneycontrol, Mint, Business Standard, Google News |

Each view shows which source its prices came from. **Api action `mkt_diag`**
(signed-in only) reports what every source returns to your server. The
**Smoke test live site** workflow runs it, together with a sign-in and a full
analysis, against the live URL.

- **News sentiment** comes from a finance word list, not a human reader.
- **Not modelled**: NSE holidays. On a holiday the app shows the last session.
- **Hard-coded lists to keep current**: index constituent lists and the Fed (FOMC) dates live in `market.php`.
  Update them after index rebalances, and each January for the FOMC dates.
- **Requirements**: PHP 7.4 or newer, with cURL, zlib and SimpleXML.

Everything fetched is cached in `data/`: briefly while the market is open, longer when it's closed.
