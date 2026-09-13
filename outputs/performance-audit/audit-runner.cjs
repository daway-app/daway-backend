#!/usr/bin/env node
'use strict';

/**
 * Daway performance audit runner (isolated, guarded).
 *
 * Safety model
 *  - Fresh, dedicated browser profile directory (never the user's real profile).
 *  - Service workers blocked, so the app's offline/sync worker never registers.
 *  - Every request that is not GET/HEAD is aborted, except POST /login during the
 *    interactive login phase (authentication only; no business forms are submitted).
 *  - No form submission, no inventory update, no inquiry, no notification read,
 *    no sync push, no device-token registration.
 *  - Writes measurements to the audit output directory only. Application code is never touched.
 *
 * Usage
 *  node audit-runner.js --phase=public
 *  node audit-runner.js --phase=login --role=admin
 *  node audit-runner.js --phase=authed --role=admin
 */

const fs = require('fs');
const path = require('path');
const { chromium } = require('playwright-core');

const ORIGIN = process.env.AUDIT_ORIGIN || 'https://daway-backend-zlh2.onrender.com';
const OUT_DIR = __dirname;
const PROFILE_ROOT = path.join(OUT_DIR, '.profiles');
const RAW_DIR = path.join(OUT_DIR, 'raw');
const SETTLE_MS = Number(process.env.AUDIT_SETTLE_MS || 6000);
const NAV_TIMEOUT_MS = Number(process.env.AUDIT_NAV_TIMEOUT_MS || 60000);
const LOGIN_WAIT_MS = Number(process.env.AUDIT_LOGIN_WAIT_MS || 300000);

const DEVICES = {
    desktop: {
        label: 'سطح المكتب',
        viewport: { width: 1440, height: 900 },
        deviceScaleFactor: 1,
        isMobile: false,
        hasTouch: false,
        userAgent:
            'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/131.0.0.0 Safari/537.36',
        cpuThrottle: 1,
        network: null,
    },
    mobile: {
        label: 'موبايل',
        viewport: { width: 390, height: 844 },
        deviceScaleFactor: 3,
        isMobile: true,
        hasTouch: true,
        userAgent:
            'Mozilla/5.0 (Linux; Android 13; Pixel 7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/131.0.0.0 Mobile Safari/537.36',
        cpuThrottle: 4,
        network: { downloadMbps: 1.6, uploadMbps: 0.75, latencyMs: 150 },
    },
};

const PUBLIC_PAGES = [
    { id: 'login', url: '/login', label: 'تسجيل الدخول', expect: 'public' },
    { id: 'root-anon', url: '/', label: 'الجذر (زائر)', expect: 'redirect-or-login' },
];

const ADMIN_PAGES = [
    { id: 'admin-dashboard', url: '/', label: 'لوحة الأدمن' },
    { id: 'admin-medicines', url: '/medicines', label: 'الأدوية' },
    { id: 'admin-medicines-create', url: '/medicines/create', label: 'إضافة دواء' },
    { id: 'admin-categories', url: '/categories', label: 'التصنيفات' },
    { id: 'admin-users', url: '/users', label: 'المستخدمون' },
    { id: 'admin-pharmacies', url: '/pharmacies', label: 'الصيدليات' },
    { id: 'admin-patients', url: '/patients', label: 'المرضى' },
    { id: 'admin-inventory', url: '/inventory', label: 'المخزون' },
    { id: 'admin-settings', url: '/settings', label: 'الإعدادات' },
    { id: 'admin-logs', url: '/logs', label: 'السجلات' },
    { id: 'profile', url: '/profile', label: 'الملف الشخصي' },
    { id: 'notifications', url: '/notifications', label: 'الإشعارات' },
];

const PHARMACY_PAGES = [
    { id: 'pharmacy-dashboard', url: '/pharmacy/dashboard', label: 'لوحة الصيدلية' },
    { id: 'pharmacy-inventory', url: '/pharmacy/inventory', label: 'مخزون الصيدلية' },
    { id: 'pharmacy-inquiries', url: '/pharmacy/inquiries', label: 'الاستفسارات' },
    { id: 'pharmacy-medicines', url: '/pharmacy/medicines', label: 'أدوية الصيدلية' },
    { id: 'pharmacy-medicines-create', url: '/pharmacy/medicines/create', label: 'إضافة دواء للصيدلية' },
    { id: 'pharmacy-alternatives', url: '/pharmacy/alternatives', label: 'البدائل' },
    { id: 'pharmacy-alternatives-create', url: '/pharmacy/alternatives/create', label: 'إضافة بديل' },
    { id: 'pharmacy-ratings', url: '/pharmacy/ratings', label: 'التقييمات' },
    { id: 'pharmacy-profile', url: '/pharmacy/profile', label: 'ملف الصيدلية' },
];

const PRIORITY_PAGES = new Set([
    'admin-medicines-create',
    'pharmacy-inventory',
    'pharmacy-alternatives-create',
    'pharmacy-dashboard',
]);

/* ------------------------------------------------------------------ utils */

function argValue(name, fallback) {
    const prefix = `--${name}=`;
    const found = process.argv.find((a) => a.startsWith(prefix));
    return found ? found.slice(prefix.length) : fallback;
}

function ensureDirs() {
    for (const dir of [PROFILE_ROOT, RAW_DIR]) {
        fs.mkdirSync(dir, { recursive: true });
    }
}

function round(value, digits = 1) {
    if (value === null || value === undefined || Number.isNaN(value)) return null;
    const factor = 10 ** digits;
    return Math.round(value * factor) / factor;
}

function statePath(role) {
    return path.join(PROFILE_ROOT, `${role}-state.json`);
}

/* --------------------------------------------------------- init scripts */

/**
 * Applies CPU throttling and network emulation to a single page through CDP so
 * the "mobile" profile reflects a realistic mid-range phone on a slow
 * connection. Without this the mobile numbers would only differ by viewport.
 * Applied per page because these CDP domains are scoped to the target.
 */
async function applyThrottling(page, device) {
    const applied = { cpuRate: null, network: null, error: null };

    try {
        const client = await page.context().newCDPSession(page);

        if (device.cpuThrottle && device.cpuThrottle > 1) {
            await client.send('Emulation.setCPUThrottlingRate', { rate: device.cpuThrottle });
            applied.cpuRate = device.cpuThrottle;
        }

        if (device.network) {
            await client.send('Network.enable');
            await client.send('Network.emulateNetworkConditions', {
                offline: false,
                latency: device.network.latencyMs,
                downloadThroughput: Math.round((device.network.downloadMbps * 1024 * 1024) / 8),
                uploadThroughput: Math.round((device.network.uploadMbps * 1024 * 1024) / 8),
            });
            applied.network = device.network;
        }
    } catch (error) {
        applied.error = String((error && error.message) || error).slice(0, 200);
    }

    return applied;
}

const INIT_SCRIPT = () => {
    window.__dawayAudit = { lcp: null, cls: 0, clsEntries: [], longTasks: [], errors: [] };

    try {
        new PerformanceObserver((list) => {
            for (const entry of list.getEntries()) {
                window.__dawayAudit.lcp = {
                    startTime: entry.startTime,
                    size: entry.size || 0,
                    url: entry.url || '',
                    element: entry.element ? entry.element.tagName : null,
                };
            }
        }).observe({ type: 'largest-contentful-paint', buffered: true });
    } catch (e) {}

    try {
        new PerformanceObserver((list) => {
            for (const entry of list.getEntries()) {
                if (entry.hadRecentInput) continue;
                window.__dawayAudit.cls += entry.value;
                window.__dawayAudit.clsEntries.push({ value: entry.value, startTime: entry.startTime });
            }
        }).observe({ type: 'layout-shift', buffered: true });
    } catch (e) {}

    try {
        new PerformanceObserver((list) => {
            for (const entry of list.getEntries()) {
                window.__dawayAudit.longTasks.push({ start: entry.startTime, duration: entry.duration });
            }
        }).observe({ type: 'longtask', buffered: true });
    } catch (e) {}

    window.addEventListener('error', (event) => {
        window.__dawayAudit.errors.push({
            type: 'error',
            message: String(event.message || ''),
            source: event.filename ? String(event.filename) : '',
        });
    });

    window.addEventListener('unhandledrejection', (event) => {
        window.__dawayAudit.errors.push({
            type: 'unhandledrejection',
            message: String((event.reason && event.reason.message) || event.reason || ''),
            source: '',
        });
    });
};

/* ------------------------------------------------------- guarded routing */

function installGuards(context, options) {
    const denied = [];

    context.route('**/*', async (route) => {
        const request = route.request();
        const method = request.method().toUpperCase();
        let url;
        try {
            url = new URL(request.url());
        } catch (e) {
            return route.abort();
        }

        if (method === 'GET' || method === 'HEAD') {
            // The audit never navigates to the Excel export; block it defensively.
            if (url.pathname.startsWith('/logs/export-excel')) {
                denied.push({ method, path: url.pathname, reason: 'export-blocked' });
                return route.abort();
            }
            return route.continue();
        }

        const isLoginPost =
            options.allowLoginPost && method === 'POST' && url.pathname === '/login';

        if (isLoginPost) return route.continue();

        denied.push({ method, path: url.pathname, reason: 'non-read-method' });
        return route.abort();
    });

    return denied;
}

/* ------------------------------------------------------------- measuring */

async function collectMetrics(page) {
    return page.evaluate(() => {
        const nav = performance.getEntriesByType('navigation')[0] || null;
        const paint = performance.getEntriesByType('paint') || [];
        const resources = performance.getEntriesByType('resource') || [];
        const audit = window.__dawayAudit || { lcp: null, cls: 0, longTasks: [], errors: [] };

        const fcp = paint.find((p) => p.name === 'first-contentful-paint');

        const byType = {};
        let transferTotal = 0;
        let decodedTotal = 0;
        let unknownSize = 0;

        const resourceRows = resources.map((r) => {
            const type = r.initiatorType || 'other';
            const transfer = typeof r.transferSize === 'number' ? r.transferSize : 0;
            const decoded = typeof r.decodedBodySize === 'number' ? r.decodedBodySize : 0;
            // transferSize === 0 with a non-zero decodedBodySize usually means
            // memory/disk cache, not a zero-byte download.
            const fromCache = transfer === 0 && decoded > 0;
            if (!fromCache && transfer === 0) unknownSize += 1;

            byType[type] = byType[type] || { count: 0, transferBytes: 0, decodedBytes: 0 };
            byType[type].count += 1;
            byType[type].transferBytes += transfer;
            byType[type].decodedBytes += decoded;
            transferTotal += transfer;
            decodedTotal += decoded;

            return {
                url: r.name,
                type,
                startTime: Math.round(r.startTime),
                duration: Math.round(r.duration),
                transferBytes: transfer,
                decodedBytes: decoded,
                fromCache,
            };
        });

        const longTasks = audit.longTasks || [];

        return {
            navigation: nav
                ? {
                      // Full navigation TTFB (includes redirects and connection setup).
                      ttfbFullMs: Math.round(nav.responseStart),
                      requestTtfbMs: Math.round(nav.responseStart - nav.requestStart),
                      domContentLoadedMs: Math.round(nav.domContentLoadedEventEnd),
                      loadEventMs: Math.round(nav.loadEventEnd),
                      durationMs: Math.round(nav.duration),
                      transferSize: nav.transferSize || 0,
                      decodedBodySize: nav.decodedBodySize || 0,
                      redirectCount: nav.redirectCount || 0,
                      protocol: nav.nextHopProtocol || null,
                  }
                : null,
            fcpMs: fcp ? Math.round(fcp.startTime) : null,
            lcpMs: audit.lcp ? Math.round(audit.lcp.startTime) : null,
            lcpElement: audit.lcp ? audit.lcp.element : null,
            lcpUrl: audit.lcp ? audit.lcp.url : null,
            cls: Math.round((audit.cls || 0) * 10000) / 10000,
            clsShiftCount: (audit.clsEntries || []).length,
            longTaskCount: longTasks.length,
            longTaskTotalMs: Math.round(longTasks.reduce((sum, t) => sum + t.duration, 0)),
            longTaskMaxMs: longTasks.length ? Math.round(Math.max(...longTasks.map((t) => t.duration))) : 0,
            jsErrors: audit.errors || [],
            resourceCount: resources.length,
            resourceTransferTotal: transferTotal,
            resourceDecodedTotal: decodedTotal,
            resourcesWithUnknownTransfer: unknownSize,
            byType,
            resourceRows,
            domNodes: document.getElementsByTagName('*').length,
            htmlBytes: document.documentElement.outerHTML.length,
            docTitle: document.title,
            visibilityState: document.visibilityState,
            paintEntryNames: paint.map((p) => p.name),
            finalPath: location.pathname,
            optionCount: document.querySelectorAll('option').length,
            imgCount: document.images.length,
            imgWithoutDimensions: Array.from(document.images).filter(
                (img) => !img.getAttribute('width') || !img.getAttribute('height')
            ).length,
            largestImages: Array.from(document.images)
                .map((img) => ({
                    src: img.currentSrc || img.src,
                    naturalWidth: img.naturalWidth,
                    naturalHeight: img.naturalHeight,
                    renderedWidth: Math.round(img.getBoundingClientRect().width),
                    renderedHeight: Math.round(img.getBoundingClientRect().height),
                    loading: img.getAttribute('loading') || null,
                }))
                .sort((a, b) => b.naturalWidth * b.naturalHeight - a.naturalWidth * a.naturalHeight)
                .slice(0, 5),
        };
    });
}

async function measurePage(context, device, pageDef, options) {
    const page = await context.newPage();
    const record = {
        id: pageDef.id,
        label: pageDef.label,
        device: device.label,
        url: ORIGIN + pageDef.url,
        startedAt: new Date().toISOString(),
    };

    record.throttling = await applyThrottling(page, device);

    try {
        const response = await page.goto(ORIGIN + pageDef.url, {
            waitUntil: 'load',
            timeout: NAV_TIMEOUT_MS,
        });

        record.httpStatus = response ? response.status() : null;
        record.responseUrl = page.url();

        await page.waitForTimeout(SETTLE_MS);

        const metrics = await collectMetrics(page);
        Object.assign(record, metrics);
        record.measurementOk = true;

        record.isLoginRedirect = /\/login/.test(new URL(page.url()).pathname);
        record.isErrorPage = record.httpStatus !== null && record.httpStatus >= 400;
    } catch (error) {
        record.measurementOk = false;
        record.failure = { name: error.name, message: String(error.message || '').slice(0, 300) };
    } finally {
        await page.close().catch(() => {});
    }

    return record;
}

/* ----------------------------------------------------------------- phases */

async function runPhasePublic(browser, device) {
    const context = await browser.newContext({
        viewport: device.viewport,
        deviceScaleFactor: device.deviceScaleFactor,
        isMobile: device.isMobile,
        hasTouch: device.hasTouch,
        userAgent: device.userAgent,
        serviceWorkers: 'block',
        bypassCSP: false,
    });

    const denied = installGuards(context, { allowLoginPost: false });
    await context.addInitScript(INIT_SCRIPT);
    const results = [];

    for (const pageDef of PUBLIC_PAGES) {
        results.push(await measurePage(context, device, pageDef, {}));
    }

    await context.close();

    return {
        phase: 'public',
        device: device.label,
        origin: ORIGIN,
        measuredAt: new Date().toISOString(),
        deniedRequests: denied,
        results,
    };
}

async function runPhaseLogin(browser, role) {
    const context = await browser.newContext({
        viewport: DEVICES.desktop.viewport,
        userAgent: DEVICES.desktop.userAgent,
        serviceWorkers: 'block',
    });

    const denied = installGuards(context, { allowLoginPost: true });
    await context.addInitScript(INIT_SCRIPT);
    const page = await context.newPage();

    await page.goto(ORIGIN + '/login', { waitUntil: 'domcontentloaded', timeout: NAV_TIMEOUT_MS });

    console.log('');
    console.log('====================================================');
    console.log(`  سجّل الدخول الآن بحساب ${role === 'admin' ? 'الأدمن التجريبي' : 'الصيدلية التجريبي'}`);
    console.log('  في نافذة المتصفح المفتوحة.');
    console.log('  - الأدمن: اختر "أدمن" وأدخل البريد وكلمة المرور.');
    console.log('  - الصيدلية: اختر "صيدلية" وأدخل Pharmacy ID وكلمة المرور.');
    console.log('  لا ترسل كلمة المرور هنا. الأداة تنتظر فقط.');
    console.log('====================================================');
    console.log('');

    const deadline = Date.now() + LOGIN_WAIT_MS;
    let loggedIn = false;
    let landingUrl = '';

    while (Date.now() < deadline) {
        const current = page.url();
        const pathName = new URL(current).pathname;
        const isLoginPage = pathName === '/login';

        if (!isLoginPage) {
            const hasAuthMarker = await page
                .evaluate(() => document.body.classList.contains('authed-user'))
                .catch(() => false);
            const hasLogoutForm = await page
                .evaluate(() => !!document.querySelector('form[action*="logout"], a[href*="logout"]'))
                .catch(() => false);

            if (hasAuthMarker || hasLogoutForm) {
                loggedIn = true;
                landingUrl = current;
                break;
            }
        }

        await page.waitForTimeout(2000);
    }

    let saved = false;
    if (loggedIn) {
        await page.waitForTimeout(1500);
        const state = await context.storageState();
        fs.writeFileSync(statePath(role), JSON.stringify(state, null, 2), 'utf8');
        saved = true;
        console.log(`تم تسجيل الدخول. صفحة الوصول: ${landingUrl}`);
    } else {
        console.log('انتهت مدة الانتظار بدون تسجيل دخول.');
    }

    await context.close();

    return {
        phase: 'login',
        role,
        loggedIn,
        landingUrl,
        stateSaved: saved,
        deniedRequests: denied,
        finishedAt: new Date().toISOString(),
    };
}

async function runPhaseAuthed(browser, device, role, pages) {
    const stateFile = statePath(role);
    if (!fs.existsSync(stateFile)) {
        throw new Error(`لا يوجد ملف جلسة للدور ${role}. شغّل مرحلة الدخول أولًا.`);
    }

    const context = await browser.newContext({
        viewport: device.viewport,
        deviceScaleFactor: device.deviceScaleFactor,
        isMobile: device.isMobile,
        hasTouch: device.hasTouch,
        userAgent: device.userAgent,
        serviceWorkers: 'block',
        storageState: stateFile,
    });

    const denied = installGuards(context, { allowLoginPost: false });
    await context.addInitScript(INIT_SCRIPT);
    const results = [];

    for (const pageDef of pages) {
        results.push(await measurePage(context, device, pageDef, {}));
    }

    await context.close();

    return {
        phase: 'authed',
        role,
        device: device.label,
        origin: ORIGIN,
        measuredAt: new Date().toISOString(),
        deniedRequests: denied,
        results,
    };
}

/* ------------------------------------------------------------------- main */

async function main() {
    const phase = argValue('phase', 'public');
    const role = argValue('role', 'admin');
    const deviceKey = argValue('device', 'desktop');
    const scope = argValue('scope', 'screen');

    const device = DEVICES[deviceKey];
    if (!device) throw new Error(`جهاز غير معروف: ${deviceKey}`);

    ensureDirs();

    const headless = phase !== 'login';
    const browser = await chromium.launch({ channel: 'chrome', headless });

    try {
        let output;

        if (phase === 'public') {
            output = await runPhasePublic(browser, device);
        } else if (phase === 'login') {
            output = await runPhaseLogin(browser, role);
        } else if (phase === 'authed') {
            const pool = role === 'pharmacy' ? PHARMACY_PAGES : ADMIN_PAGES;
            const pages = scope === 'priority' ? pool.filter((p) => PRIORITY_PAGES.has(p.id)) : pool;
            output = await runPhaseAuthed(browser, device, role, pages);
        } else {
            throw new Error(`مرحلة غير معروفة: ${phase}`);
        }

        const file = path.join(RAW_DIR, `${phase}-${role}-${deviceKey}-${scope}.json`);
        fs.writeFileSync(file, JSON.stringify(output, null, 2), 'utf8');
        console.log(`تم الحفظ: ${file}`);
        console.log(
            JSON.stringify(
                {
                    phase: output.phase,
                    device: output.device || null,
                    role: output.role || null,
                    measured: output.results ? output.results.length : undefined,
                    deniedRequests: output.deniedRequests ? output.deniedRequests.length : undefined,
                    loggedIn: output.loggedIn,
                },
                null,
                2
            )
        );
    } finally {
        await browser.close().catch(() => {});
    }
}

main().catch((error) => {
    console.error('فشل التشغيل:', error && error.message ? error.message : error);
    process.exit(1);
});
