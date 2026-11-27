-- SQLite schema for the stock portfolio tracker.
-- Applied automatically and idempotently by app/Database::migrate().
--
-- Every timestamp here is UTC+8 (HKT, no daylight saving), the same clock the app runs on. INSERTs
-- write created_at / fetched_at themselves via now_stamp(), so those defaults only cover rows added by
-- hand or by another tool; datetime('now') on its own would have put them eight hours behind the rest.

CREATE TABLE IF NOT EXISTS settings (
    key   TEXT PRIMARY KEY,
    value TEXT NOT NULL
);

INSERT OR IGNORE INTO settings (key, value) VALUES
    ('base_currency', 'TWD'),
    ('quote_source',  'yahoo'),
    ('last_refresh',  '');

CREATE TABLE IF NOT EXISTS portfolios (
    id            INTEGER PRIMARY KEY AUTOINCREMENT,
    name          TEXT NOT NULL UNIQUE,
    description   TEXT NOT NULL DEFAULT '',
    created_at    TEXT NOT NULL DEFAULT (datetime('now','+8 hours'))
);

-- Instruments are shared across portfolios (one price history per symbol).
CREATE TABLE IF NOT EXISTS instruments (
    id             INTEGER PRIMARY KEY AUTOINCREMENT,
    symbol         TEXT NOT NULL,
    name           TEXT NOT NULL DEFAULT '',
    market         TEXT NOT NULL DEFAULT 'US',
    yahoo_symbol   TEXT NOT NULL UNIQUE,
    currency       TEXT NOT NULL DEFAULT 'USD',
    asset_class    TEXT NOT NULL DEFAULT 'EQUITY',
    exchange       TEXT NOT NULL DEFAULT '',
    manual_price   REAL,
    manual_price_at TEXT,
    notes          TEXT NOT NULL DEFAULT '',
    created_at     TEXT NOT NULL DEFAULT (datetime('now','+8 hours'))
);

CREATE TABLE IF NOT EXISTS transactions (
    id           INTEGER PRIMARY KEY AUTOINCREMENT,
    portfolio_id INTEGER NOT NULL REFERENCES portfolios(id) ON DELETE CASCADE,
    instrument_id INTEGER NOT NULL REFERENCES instruments(id) ON DELETE RESTRICT,
    side         TEXT NOT NULL CHECK (side IN ('BUY', 'SELL')),
    traded_on    TEXT NOT NULL CHECK (
        length(traded_on) = 10
        AND traded_on GLOB '[0-9][0-9][0-9][0-9]-[0-9][0-9]-[0-9][0-9]'
        AND date(traded_on) IS NOT NULL
        AND date(traded_on) = traded_on
    ),
    currency     TEXT NOT NULL DEFAULT '' CHECK (currency = '' OR length(currency) BETWEEN 2 AND 5),
    quantity     REAL NOT NULL CHECK (quantity > 0),
    price        REAL NOT NULL CHECK (price >= 0),
    fee          REAL NOT NULL DEFAULT 0 CHECK (fee >= 0),
    note         TEXT NOT NULL DEFAULT '',
    created_at   TEXT NOT NULL DEFAULT (datetime('now','+8 hours'))
);

CREATE INDEX IF NOT EXISTS idx_tx_portfolio ON transactions(portfolio_id, traded_on);
CREATE INDEX IF NOT EXISTS idx_tx_instrument ON transactions(instrument_id, traded_on);

-- Deliberate cash in/out. The balance is derived from this table plus every trade's settlement flow,
-- so editing or deleting a trade moves cash without anything needing to be kept in sync here.
CREATE TABLE IF NOT EXISTS cash_movements (
    id           INTEGER PRIMARY KEY AUTOINCREMENT,
    portfolio_id INTEGER NOT NULL REFERENCES portfolios(id) ON DELETE CASCADE,
    moved_on     TEXT NOT NULL CHECK (
        length(moved_on) = 10
        AND moved_on GLOB '[0-9][0-9][0-9][0-9]-[0-9][0-9]-[0-9][0-9]'
        AND date(moved_on) IS NOT NULL
        AND date(moved_on) = moved_on
    ),
    direction    TEXT NOT NULL CHECK (direction IN ('IN', 'OUT')),
    amount       REAL NOT NULL CHECK (amount > 0),
    currency     TEXT NOT NULL,
    note         TEXT NOT NULL DEFAULT '',
    created_at   TEXT NOT NULL DEFAULT (datetime('now','+8 hours'))
);

CREATE INDEX IF NOT EXISTS idx_cash_portfolio ON cash_movements(portfolio_id, moved_on);

-- Daily OHLC bars plus the most recent quote, one row per instrument per day.
--
-- adj_close 是除息、除權調整後的價格（Yahoo 的 adjclose），也就是「股利有續投入」的那條線；
-- close 是當天原封不動的收盤，成交、餘額、圖表與回報依這個口徑；策略判讀改用 adj_close
-- （COALESCE 回退 close），才不會把除息、除權誤判成價格跌幅。兩者不混用，避免調整後市值配上
-- 原始成交金額而算出假報酬。來源沒有給 adjclose 的標的（加密貨幣、部分外匯）就留 NULL。
-- high / low 是未調整的當日最高、最低價；舊資料或來源沒給時留 NULL，不可用 close 冒充。
CREATE TABLE IF NOT EXISTS prices (
    instrument_id INTEGER NOT NULL REFERENCES instruments(id) ON DELETE CASCADE,
    as_of         TEXT NOT NULL CHECK (
        length(as_of) = 10
        AND as_of GLOB '[0-9][0-9][0-9][0-9]-[0-9][0-9]-[0-9][0-9]'
        AND substr(as_of, 1, 4) BETWEEN '1000' AND '9999'
        AND date(as_of) IS NOT NULL
        AND date(as_of) = as_of
    ),
    close         REAL NOT NULL CHECK (close > 0),
    high          REAL CHECK (high > 0),
    low           REAL CHECK (low > 0),
    prev_close    REAL CHECK (prev_close > 0),
    adj_close     REAL CHECK (adj_close > 0),
    currency      TEXT NOT NULL CHECK (length(currency) BETWEEN 2 AND 5),
    source        TEXT NOT NULL DEFAULT 'yahoo' CHECK (source IN ('yahoo', 'manual')),
    fetched_at    TEXT NOT NULL DEFAULT (datetime('now','+8 hours')),
    PRIMARY KEY (instrument_id, as_of)
);

-- recentlyFetched() only needs automatic rows for one instrument, newest fetch first.
CREATE INDEX IF NOT EXISTS idx_prices_fetched_recent
ON prices(instrument_id, fetched_at DESC)
WHERE source <> 'manual';

CREATE INDEX IF NOT EXISTS idx_prices_adj_pending
ON prices(instrument_id)
WHERE adj_close IS NULL;

CREATE INDEX IF NOT EXISTS idx_prices_range_pending
ON prices(instrument_id, as_of)
WHERE source <> 'manual' AND (high IS NULL OR low IS NULL);

-- Units of `currency` per 1 USD, so totals stay correct when the base currency changes.
CREATE TABLE IF NOT EXISTS fx_rates (
    currency     TEXT PRIMARY KEY,
    units_per_usd REAL NOT NULL CHECK (units_per_usd > 0),
    as_of        TEXT NOT NULL,
    source       TEXT NOT NULL DEFAULT 'yahoo',
    manual       INTEGER NOT NULL DEFAULT 0,
    fetched_at   TEXT NOT NULL DEFAULT (datetime('now','+8 hours'))
);

-- One shared gate, not one row per person: exactly one account (CHECK keeps it that way),
-- created by bin/create-admin.php. Deliberately left out of Backup::TABLES so restoring an
-- old backup can never lock whoever is reading this machine out of it.
CREATE TABLE IF NOT EXISTS access (
    id            INTEGER PRIMARY KEY CHECK (id = 1),
    username      TEXT NOT NULL UNIQUE,
    password_hash TEXT NOT NULL,
    created_at    TEXT NOT NULL DEFAULT (datetime('now','+8 hours'))
);

-- Per-source brute-force locks plus namespaced password-change counters. The reserved *global row
-- only adds delay after distributed login failures; it never locks the owner out. Like `access`, this
-- table is not in Backup::TABLES: restoring a snapshot must neither lift a lock nor add one.
CREATE TABLE IF NOT EXISTS login_throttle (
    ip            TEXT PRIMARY KEY,
    fail_count    INTEGER NOT NULL DEFAULT 0,
    locked_until  INTEGER NOT NULL DEFAULT 0,
    seen_at       INTEGER NOT NULL DEFAULT 0
);
