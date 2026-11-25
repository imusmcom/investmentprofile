/**
 * Yahoo Finance chart passthrough, for the day Yahoo starts refusing the server this app runs on.
 *
 * It forwards nothing but the one endpoint App\QuoteService asks for, and keeps the path intact so the
 * app needs no per-source code: paste this Worker's root URL into 設定 → 「轉接位址」 and the request
 * for VT becomes https://<worker>/v8/finance/chart/VT?range=6mo&interval=1d on Yahoo's own host again.
 *
 * QUOTE_TOKEN is mandatory. Deploy from the dashboard by pasting this file as the Worker entrypoint,
 * or pass the script path to wrangler (without a wrangler.toml, the default src/index.js is wrong):
 *
 *   npx wrangler deploy worker/yahoo-passthrough.js --name yahoo-passthrough --compatibility-date 2024-09-01
 *   npx wrangler secret put QUOTE_TOKEN --name yahoo-passthrough
 *
 * If you use wrangler.toml instead, its entrypoint is: main = "worker/yahoo-passthrough.js".
 * Then put https://yahoo-passthrough.<你的子網域>.workers.dev in 「轉接位址」, and the same token in
 * 「存取金鑰」. Missing or wrong tokens always return 401. Verify with:
 *
 *   curl -s -H 'X-Quote-Token: <同一個金鑰>' \
 *     'https://yahoo-passthrough.<…>.workers.dev/v8/finance/chart/VT?range=5d&interval=1d' | head -c 200
 *
 * A chart.result JSON means the exit is clean; 429/403 still means Yahoo is blocking that exit.
 * Each upstream attempt has an 8-second AbortController timeout, successful bodies are capped at 2 MiB,
 * and each isolate allows 60 requests/minute per source IP and per token hash. Bind the optional
 * Cloudflare Rate Limiting binding as QUOTE_RATE_LIMITER (60 requests per 60 seconds) to enforce those
 * same quotas across isolates; without it, the in-memory guardrails remain in use.
 */

const UPSTREAMS = ['https://query1.finance.yahoo.com', 'https://query2.finance.yahoo.com'];

// App\QuoteService::CHART_PATH. Anything else is not this Worker's business.
const ALLOWED_PATH = /^\/v8\/finance\/chart\/[^/]+$/;

// The endpoint answers without this, but a bare "worker" UA is what gets a caller classified as a bot.
const USER_AGENT = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 '
    + '(KHTML, like Gecko) Chrome/120.0 Safari/537.36';

const FETCH_TIMEOUT_MS = 8000;
const MAX_RESPONSE_BYTES = 2 * 1024 * 1024;
const QUOTA_LIMIT = 60;
const QUOTA_WINDOW_MS = 60 * 1000;
const QUOTA_MAX_KEYS = 4096;
const ipQuotas = new Map();
const tokenQuotas = new Map();

export default {
    async fetch(request, env) {
        const expectedToken = env.QUOTE_TOKEN;
        if (typeof expectedToken !== 'string' || expectedToken.trim() === ''
            || !tokenMatches(request.headers.get('X-Quote-Token'), expectedToken)) {
            // App reads 403 as "blocked, pause"; a missing/wrong key must not consume that pause.
            return refuse(401, 'wrong or missing X-Quote-Token, or Worker QUOTE_TOKEN is not configured');
        }

        if (request.method !== 'GET') {
            return refuse(405, 'only GET is proxied');
        }

        const url = new URL(request.url);
        if (!ALLOWED_PATH.test(url.pathname)) {
            return refuse(404, 'this worker only forwards the chart endpoint');
        }

        // Cloudflare sets CF-Connecting-IP; never trust a caller-supplied X-Forwarded-For here.
        const sourceIp = request.headers.get('CF-Connecting-IP') || 'unknown';
        const tokenDigest = await crypto.subtle.digest('SHA-256', new TextEncoder().encode(expectedToken));
        const tokenHash = Array.from(new Uint8Array(tokenDigest), byte => byte.toString(16).padStart(2, '0')).join('');
        // Binding keys are ip:<source IP> and token:<SHA-256 token hash>; configure 60/60s in Cloudflare.
        if (env.QUOTE_RATE_LIMITER) {
            for (const key of [`ip:${sourceIp}`, `token:${tokenHash}`]) {
                let decision;
                try {
                    decision = await env.QUOTE_RATE_LIMITER.limit({ key });
                } catch {
                    // A configured distributed guard must fail closed if the binding is unavailable.
                    return refuse(503, 'Worker rate limiter unavailable', QUOTA_WINDOW_MS / 1000);
                }
                if (!decision.success) {
                    return refuse(429, 'Worker quota exceeded', QUOTA_WINDOW_MS / 1000);
                }
            }
        } else {
            const now = Date.now();
            for (const [store, key] of [[ipQuotas, `ip:${sourceIp}`], [tokenQuotas, `token:${tokenHash}`]]) {
                const decision = takeQuota(store, key, now);
                if (!decision.allowed) {
                    return refuse(429, 'Worker quota exceeded', decision.retryAfter);
                }
            }
        }

        const headers = new Headers({ 'User-Agent': USER_AGENT, Accept: 'application/json' });
        let lastStatus = 502;
        let lastRetry = null;

        for (const upstream of UPSTREAMS) {
            const attempt = await fetchUpstream(upstream + url.pathname + url.search, headers);
            if (attempt === null) {
                lastStatus = 502;
                lastRetry = null;
                continue;
            }

            const { reply, controller, timer } = attempt;
            if (reply.ok) {
                if (!reply.body) {
                    clearTimeout(timer);
                    return refuse(502, 'upstream returned an empty response');
                }
                return pipe(reply, controller, timer);
            }

            lastStatus = reply.status;
            lastRetry = reply.headers.get('Retry-After');
            clearTimeout(timer);
            if (reply.body) {
                await reply.body.cancel().catch(() => {});
            }

            // Do not spend the second upstream when Yahoo is explicitly throttling/blocking us.
            // Return that exact status and Retry-After so App\QuoteService keeps its cooldown meaning.
            if (reply.status === 429 || reply.status === 403) {
                return refuse(reply.status, 'upstream refused the request', lastRetry);
            }
        }

        return refuse(lastStatus, 'upstream refused the request', lastRetry);
    },
};

function tokenMatches(given, expected) {
    if (typeof given !== 'string' || given.length !== expected.length) {
        return false;
    }
    let difference = 0;
    for (let i = 0; i < expected.length; i++) {
        difference |= given.charCodeAt(i) ^ expected.charCodeAt(i);
    }
    return difference === 0;
}

async function fetchUpstream(url, headers) {
    const controller = new AbortController();
    const timer = setTimeout(() => controller.abort(), FETCH_TIMEOUT_MS);
    try {
        const reply = await fetch(url, { headers, redirect: 'follow', signal: controller.signal });
        return { reply, controller, timer };
    } catch {
        clearTimeout(timer);
        return null;
    }
}

function takeQuota(store, key, now) {
    let entry = store.get(key);
    if (!entry || now >= entry.resetAt) {
        for (const [oldKey, oldEntry] of store) {
            if (now >= oldEntry.resetAt) {
                store.delete(oldKey);
            }
        }
        if (store.size >= QUOTA_MAX_KEYS) {
            return { allowed: false, retryAfter: Math.ceil(QUOTA_WINDOW_MS / 1000) };
        }
        entry = { count: 0, resetAt: now + QUOTA_WINDOW_MS };
        store.set(key, entry);
    }
    entry.count++;
    if (entry.count > QUOTA_LIMIT) {
        return { allowed: false, retryAfter: Math.max(1, Math.ceil((entry.resetAt - now) / 1000)) };
    }
    return { allowed: true, retryAfter: 0 };
}

function pipe(reply, controller, timer) {
    const reader = reply.body.getReader();
    let received = 0;
    const body = new ReadableStream({
        async pull(output) {
            try {
                const { done, value } = await reader.read();
                if (done) {
                    clearTimeout(timer);
                    reader.releaseLock();
                    output.close();
                    return;
                }
                received += value.byteLength;
                if (received > MAX_RESPONSE_BYTES) {
                    controller.abort();
                    await reader.cancel().catch(() => {});
                    throw new Error('upstream response exceeded the byte limit');
                }
                output.enqueue(value);
            } catch (error) {
                clearTimeout(timer);
                output.error(error);
            }
        },
        async cancel(reason) {
            clearTimeout(timer);
            await reader.cancel(reason).catch(() => {});
        },
    });

    return new Response(body, {
        status: reply.status,
        headers: {
            'Content-Type': reply.headers.get('Content-Type') || 'application/json',
            // Quotes are not private, but edge caching makes "just refreshed" unexpectedly stale.
            'Cache-Control': 'no-store',
        },
    });
}

function refuse(status, description, retryAfter = null) {
    const headers = { 'Content-Type': 'application/json', 'Cache-Control': 'no-store' };
    if (retryAfter) {
        headers['Retry-After'] = retryAfter;
    }
    return new Response(JSON.stringify({ chart: { error: { description } } }), { status, headers });
}
