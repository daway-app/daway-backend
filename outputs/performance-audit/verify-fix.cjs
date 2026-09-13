#!/usr/bin/env node
'use strict';

/**
 * يقيس صفحة الدخول قبل/بعد الإصلاح على نفس الجهاز ونفس ظروف الحجب،
 * ليُثبت أثر تعديل الشعار + حركة الظهور بالأرقام.
 *
 * الاستخدام:
 *   node verify-fix.cjs <baseUrl> <label>
 * مثال:
 *   node verify-fix.cjs http://127.0.0.1:8000 after
 */

const fs = require('fs');
const path = require('path');

const { chromium } = require('playwright-core');

const OUT_DIR = __dirname;
const RAW_DIR = path.join(OUT_DIR, 'raw-fix');

const baseUrl = (process.argv[2] || 'http://127.0.0.1:8000').replace(/\/$/, '');
const label = process.argv[3] || 'after';

const DEVICE = {
    name: 'سطح المكتب',
    viewport: { width: 1440, height: 900 },
    cpuRate: 1,
    network: null,
};

const INIT_SCRIPT = () => {
    window.__perf = { lcp: null, cls: 0, lcpElement: null };
    try {
        new PerformanceObserver((list) => {
            const entries = list.getEntries();
            const last = entries[entries.length - 1];
            if (last) {
                window.__perf.lcp = last.startTime;
                window.__perf.lcpElement = last.element ? last.element.tagName : null;
            }
        }).observe({ type: 'largest-contentful-paint', buffered: true });
    } catch (e) {}
    try {
        new PerformanceObserver((list) => {
            for (const entry of list.getEntries()) {
                if (!entry.hadRecentInput) window.__perf.cls += entry.value;
            }
        }).observe({ type: 'layout-shift', buffered: true });
    } catch (e) {}
};

function findExecutable() {
    const candidates = [
        process.env.AUDIT_CHROMIUM,
        'C:/Program Files/Google/Chrome/Application/chrome.exe',
        'C:/Program Files (x86)/Google/Chrome/Application/chrome.exe',
    ].filter(Boolean);
    for (const c of candidates) if (fs.existsSync(c)) return c;
    return null;
}

function collect(page) {
    return page.evaluate(() => {
        const nav = performance.getEntriesByType('navigation')[0] || {};
        const paints = performance.getEntriesByType('paint') || [];
        const fcp = paints.find((p) => p.name === 'first-contentful-paint');
        const resources = performance.getEntriesByType('resource').map((r) => ({
            url: r.name,
            type: r.initiatorType,
            transferBytes: r.transferSize || 0,
            decodedBytes: r.decodedBodySize || 0,
            startTime: Math.round(r.startTime),
            duration: Math.round(r.duration),
        }));
        return {
            ttfbFullMs: Math.round(nav.responseStart || 0),
            requestTtfbMs: Math.round(nav.requestStart ? nav.responseStart - nav.requestStart : 0),
            domContentLoadedMs: Math.round(nav.domContentLoadedEventEnd || 0),
            loadEventMs: Math.round(nav.loadEventEnd || 0),
            transferSize: nav.transferSize || 0,
            docDecoded: nav.decodedBodySize || 0,
            fcpMs: fcp ? Math.round(fcp.startTime) : null,
            lcpMs: window.__perf.lcp === null ? null : Math.round(window.__perf.lcp),
            lcpElement: window.__perf.lcpElement,
            cls: Number((window.__perf.cls || 0).toFixed(4)),
            resourceCount: resources.length,
            resourceTransferTotal: resources.reduce((s, r) => s + r.transferBytes, 0),
            resources,
            domNodes: document.getElementsByTagName('*').length,
            htmlBytes: document.documentElement.outerHTML.length,
        };
    });
}

(async () => {
    const executablePath = findExecutable();
    if (!executablePath) {
        console.error('ما لقيت Chromium. مرّر المسار في AUDIT_CHROMIUM.');
        process.exit(1);
    }

    fs.mkdirSync(RAW_DIR, { recursive: true });

    const browser = await chromium.launch({ executablePath, headless: true });
    const context = await browser.newContext({
        viewport: DEVICE.viewport,
        locale: 'ar',
        serviceWorkers: 'block',
    });
    await context.addInitScript(INIT_SCRIPT);

    const page = await context.newPage();
    await page.route('**/*', (route) => {
        const m = route.request().method();
        if (m !== 'GET' && m !== 'HEAD') return route.abort();
        return route.continue();
    });

    const url = `${baseUrl}/login`;
    await page.goto(url, { waitUntil: 'load', timeout: 60000 }).catch(() => {});
    await page.waitForTimeout(2500);

    const metrics = await collect(page);

    const out = {
        label,
        baseUrl,
        measuredAt: new Date().toISOString(),
        device: DEVICE.name,
        url,
        ...metrics,
    };

    const file = path.join(RAW_DIR, `login-${label}.json`);
    fs.writeFileSync(file, JSON.stringify(out, null, 2), 'utf8');

    const logo = metrics.resources
        .filter((r) => /dawak-logo/.test(r.url))
        .reduce((s, r) => s + r.transferBytes, 0);

    console.log(`[${label}] ${url}`);
    console.log(`  TTFB           ${metrics.ttfbFullMs} ms`);
    console.log(`  FCP            ${metrics.fcpMs === null ? 'لم تُسجَّل' : metrics.fcpMs + ' ms'}`);
    console.log(`  LCP            ${metrics.lcpMs === null ? 'لم تُسجَّل' : metrics.lcpMs + ' ms'} ${metrics.lcpElement || ''}`);
    console.log(`  CLS            ${metrics.cls}`);
    console.log(`  طلبات          ${metrics.resourceCount}`);
    console.log(`  إجمالي النقل   ${(metrics.resourceTransferTotal / 1024).toFixed(1)} KB`);
    console.log(`  الشعار          ${(logo / 1024).toFixed(1)} KB`);
    console.log(`  محفوظ في        ${file}`);

    await browser.close();
})();
