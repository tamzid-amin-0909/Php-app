// ==========================================
// 9. INIT APP
// ==========================================

// Polls every 10 s. validateTokenWithServer() now handles forceLogout internally
// for expired, invalidated, and mismatched sessions — the poller just starts/stops it.
let _sessionPollInterval = null;
function startSessionPoller() {
    if (_sessionPollInterval) return;
    _sessionPollInterval = setInterval(async () => {
        if (!currentUser) { clearInterval(_sessionPollInterval); _sessionPollInterval = null; return; }
        // null = network blip, skip. false = already force-logged-out inside validateTokenWithServer.
        await validateTokenWithServer();
    }, 10000);
}

async function initApp() {
    let attempt = 0;
    const delays = [1000, 2000, 3000, 5000, 5000, 8000, 8000, 10000];

    async function boot() {
        attempt++;

        // ── PHASE 1: Network fetch ────────────────────────────────────────
        // ONLY errors here trigger the retry loop. A throw means the server
        // is unreachable. JS errors in Phase 2 are code bugs — not retries.
        try {
            db = await fetchFreshDB();
        } catch (e) {
            console.warn(`Boot attempt ${attempt} failed (network):`, e);
            if (attempt <= delays.length) {
                const delay = delays[attempt - 1];
                showRetryOverlay(attempt, delays.length, delay);
                setTimeout(boot, delay);
            } else {
                showRetryOverlay(attempt, delays.length, 0, true);
            }
            return; // do NOT fall into Phase 2
        }

        const overlay = document.getElementById('boot-retry-overlay');
        if (overlay) overlay.remove();

        // ── PHASE 2: Auth + render ────────────────────────────────────────
        // fetch() succeeded. Errors here are application bugs.
        // Log them clearly and do NOT trigger the connection-retry overlay.
        try {
            // Validate existing authToken — always check, even if currentUser
            // is already hydrated. A stale session must be caught here.
            const authToken = localStorage.getItem('authToken');
            if (authToken) {
                const valid = await validateTokenWithServer();
                // null = network blip on first load, proceed cautiously
                if (valid === false) return; // forceLogout already called inside
            }

            // Try remember-me cookie if still no logged-in user
            if (!currentUser) {
                const cookie = getRememberCookie();
                if (cookie) {
                    try {
                        const res = await fetch('api.php?action=auth&type=cookie', {
                            method: 'POST',
                            headers: { 'Content-Type': 'application/json' },
                            body: JSON.stringify({ userId: cookie.userId, rememberToken: cookie.token, deviceId: getDeviceId() })
                        });
                        if (res.ok) {
                            const data = await res.json();
                            localStorage.setItem('authToken', data.token);
                            currentUser = data.user;
                            sessionStorage.setItem('currentUser', JSON.stringify(currentUser));
                            db = await fetchFreshDB();
                        } else {
                            const err = await res.json().catch(() => ({}));
                            if (err.error !== 'device_locked') clearRememberCookie();
                            currentUser = null;
                            sessionStorage.removeItem('currentUser');
                            localStorage.removeItem('authToken');
                        }
                    } catch(e) { /* network blip — leave cookie, continue as guest */ }
                }
            }

            applyTheme();
            if (db.content) precacheThumbnails(db.content).catch(() => {});
            window.addEventListener('hashchange', render);

            // ── TG CHANNEL MEMBERSHIP GATE (Awaited) ──────────────────────
            if (currentUser?.isTelegramUser) {
                const isJoined = await checkTgChannelMembership();
                // If false, the gate is shown and rendering is blocked until they join
                if (isJoined === false) return;
            }

            // If we are here, either they aren't a TG user, or they passed the gate.
            render();

            // Start polling only for logged-in users who passed the check
            if (currentUser) startSessionPoller();

        } catch (e) {
            console.error('App boot error (not a connection issue — check render/theme/header):', e);
        }
    }

    boot();
}

function showRetryOverlay(attempt, maxAttempts, nextDelay, final = false) {
    let overlay = document.getElementById('boot-retry-overlay');
    if (!overlay) {
        overlay = document.createElement('div');
        overlay.id = 'boot-retry-overlay';
        overlay.style.cssText = `
            position:fixed;inset:0;z-index:9998;display:flex;align-items:center;
            justify-content:center;background:rgba(0,0,0,0.85);backdrop-filter:blur(8px);padding:1rem;`;
        document.body.appendChild(overlay);
    }

    const secs = Math.round(nextDelay / 1000);
    overlay.innerHTML = `
        <div style="background:#1e293b;border:1px solid rgba(255,255,255,0.1);border-radius:24px;
                    padding:2rem 2.5rem;max-width:360px;width:100%;text-align:center;">
            <div style="width:52px;height:52px;margin:0 auto 1rem;border-radius:50%;
                        background:rgba(245,158,11,0.15);display:flex;align-items:center;justify-content:center;">
                ${final
                    ? `<svg style="width:26px;height:26px;" fill="none" stroke="#ef4444" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01M10.29 3.86L1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 3.86a2 2 0 00-3.42 0z"/></svg>`
                    : `<svg style="width:26px;height:26px;animation:spin 1s linear infinite;" fill="none" stroke="#f59e0b" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/></svg>`
                }
            </div>
            <h3 style="color:#f1f5f9;font-size:1rem;font-weight:700;margin-bottom:.4rem;">
                ${final ? 'Connection Failed' : 'Connecting…'}
            </h3>
            <p style="color:#94a3b8;font-size:.8rem;line-height:1.6;margin-bottom:1.5rem;">
                ${final
                    ? 'Could not reach the server. Please check your connection.'
                    : `Retrying in ${secs}s… (${attempt}/${maxAttempts})`
                }
            </p>
            <button onclick="window.location.reload()"
                style="width:100%;padding:.7rem 1rem;background:#f59e0b;color:#0f172a;font-weight:700;
                       font-size:.85rem;border:none;border-radius:12px;cursor:pointer;">
                ${final ? 'Reload Page' : 'Retry Now'}
            </button>
        </div>
        <style>@keyframes spin{to{transform:rotate(360deg)}}</style>`;
}

// ── TG CHANNEL MEMBERSHIP GATE ───────────────────────────────────────────────
async function checkTgChannelMembership() {
    const channels = db.settings?.requiredTgChannels || [];
    if (!channels.length) return true;

    try {
        const res = await fetch('api.php?action=check_tg_channels', {
            headers: { 'Authorization': 'Bearer ' + (localStorage.getItem('authToken') || '') }
        });
        if (!res.ok) return true; // network blip — don't block
        
        const data = await res.json();
        const notJoined = data.notJoined || [];
        
        if (!notJoined.length) {
            return true;
        } else {
            showTgChannelGate(notJoined);
            return false;
        }
    } catch(e) {
        return true; // network blip — don't block
    }
}

function showTgChannelGate(channels) {
    document.getElementById('tg-channel-gate')?.remove();

    const channelRows = channels.map(ch => {
        const link = ch.link || ch.username;
        const href = link.startsWith('http') ? link : `https://t.me/${link.replace(/^@/, '')}`;
        return `<a href="${href}" target="_blank" rel="noopener noreferrer"
            style="display:flex;align-items:center;gap:12px;padding:12px 16px;
                   background:rgba(255,255,255,0.06);border:1px solid rgba(255,255,255,0.1);
                   border-radius:14px;text-decoration:none;color:#fff;font-weight:700;font-size:14px;
                   transition:.2s;" onmouseover="this.style.background='rgba(255,255,255,0.12)'"
                                   onmouseout="this.style.background='rgba(255,255,255,0.06)'">
            <svg style="width:22px;height:22px;flex-shrink:0;color:#29aae1;" viewBox="0 0 24 24" fill="currentColor">
                <path d="M12 0C5.373 0 0 5.373 0 12s5.373 12 12 12 12-5.373 12-12S18.627 0 12 0zm5.894 8.221-1.97 9.28c-.145.658-.537.818-1.084.508l-3-2.21-1.447 1.394c-.16.16-.295.295-.605.295l.213-3.053 5.56-5.023c.242-.213-.054-.333-.373-.12L8.32 13.617l-2.96-.924c-.643-.204-.657-.643.136-.953l11.57-4.461c.537-.194 1.006.131.828.942z"/>
            </svg>
            <span style="flex:1;">${ch.name || ch.username}</span>
            <svg style="width:14px;height:14px;opacity:.5;" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24">
                <path d="M18 13v6a2 2 0 01-2 2H5a2 2 0 01-2-2V8a2 2 0 012-2h6M15 3h6v6M10 14L21 3"/>
            </svg>
        </a>`;
    }).join('');

    const gate = document.createElement('div');
    gate.id = 'tg-channel-gate';
    gate.innerHTML = `
        <div style="position:fixed;inset:0;z-index:9997;display:flex;align-items:center;justify-content:center;
                    background:rgba(8,10,18,0.93);backdrop-filter:blur(14px);padding:1rem;">
            <div style="background:rgba(20,25,40,0.9);border:1px solid rgba(255,255,255,0.1);
                        border-radius:24px;padding:28px 28px 24px;max-width:400px;width:100%;
                        box-shadow:0 24px 60px rgba(0,0,0,0.6);">
                <div style="text-align:center;margin-bottom:20px;">
                    <div style="width:52px;height:52px;background:rgba(41,170,225,0.15);border-radius:50%;
                                display:flex;align-items:center;justify-content:center;margin:0 auto 12px;">
                        <svg style="width:26px;height:26px;color:#29aae1;" viewBox="0 0 24 24" fill="currentColor">
                            <path d="M12 0C5.373 0 0 5.373 0 12s5.373 12 12 12 12-5.373 12-12S18.627 0 12 0zm5.894 8.221-1.97 9.28c-.145.658-.537.818-1.084.508l-3-2.21-1.447 1.394c-.16.16-.295.295-.605.295l.213-3.053 5.56-5.023c.242-.213-.054-.333-.373-.12L8.32 13.617l-2.96-.924c-.643-.204-.657-.643.136-.953l11.57-4.461c.537-.194 1.006.131.828.942z"/>
                        </svg>
                    </div>
                    <h2 style="color:#fff;font-size:18px;font-weight:800;margin:0 0 6px;">Join Our Channels</h2>
                    <p style="color:#94a3b8;font-size:13px;margin:0;">Please join the following channels to access the site</p>
                </div>
                <div style="display:flex;flex-direction:column;gap:10px;margin-bottom:20px;">
                    ${channelRows}
                </div>
                <button onclick="recheckTgChannels(this)"
                    style="width:100%;padding:13px;background:var(--color-primary,#f59e0b);color:#1a1a1a;
                           font-weight:800;font-size:14px;border:none;border-radius:14px;cursor:pointer;
                           transition:.2s;" onmouseover="this.style.opacity='.85'" onmouseout="this.style.opacity='1'">
                    ✅ I've Joined — Check Again
                </button>
            </div>
        </div>`;
    document.body.appendChild(gate);
}

window.recheckTgChannels = async function(btn) {
    const orig = btn.textContent;
    btn.textContent = 'Checking…';
    btn.disabled = true;
    try {
        const res = await fetch('api.php?action=check_tg_channels', {
            headers: { 'Authorization': 'Bearer ' + (localStorage.getItem('authToken') || '') }
        });
        if (!res.ok) throw new Error('Network error');
        
        const data = await res.json();
        const notJoined = data.notJoined || [];
        
        if (!notJoined.length) {
            // Passed! Remove gate and trigger the render/poll that were paused in boot()
            document.getElementById('tg-channel-gate')?.remove();
            if (typeof render === 'function') render();
            if (currentUser) startSessionPoller();
        } else {
            document.getElementById('tg-channel-gate')?.remove();
            showTgChannelGate(notJoined);
        }
    } catch(e) {
        // Silent catch, let the finally block reset the button
    } finally {
        if (btn) {
            btn.textContent = orig;
            btn.disabled = false;
        }
    }
};
