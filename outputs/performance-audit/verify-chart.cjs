#!/usr/bin/env node
'use strict';

/**
 * يتحقق أن Chart.js المحلي يُحمَّل ويُنشئ رسمًا فعليًا، وأنه لم تعد هناك
 * طلبات خارجية لـ CDN الخاصة بالرسوم.
 *
 * الاستخدام:
 *   node verify-chart.cjs <baseUrl> <storageStatePath>
 */

const fs = require('fs');
const path = require('path');
const { chromium } = require('playwright-core');

const baseUrl = (process.argv[2] || 'http://127.0.0.1:8126').replace(/\/$/, '');
const statePath = process.argv[3];

(async () => {
    const browser = await chromium.launch({ channel: 'chrome', headless: true });
    const contextOptions = {
        viewport: { width: 1440, height: 900 },
        locale: 'ar',
        serviceWorkers: 'block',
    };
    if (statePath && fs.existsSync(statePath)) {
        contextOptions.storageState = statePath;
    }

    const context = await browser.newContext(contextOptions);
    const page = await context.newPage();

    const external = [];
    const chartRequests = [];

    page.on('request', (req) => {
        const u = req.url();
        if (/cdn\.jsdelivr\.net|cdnjs\.cloudflare\.com/.test(u)) external.push(u);
        if (/chart/i.test(u)) chartRequests.push(u);
    });

    const url = `${baseUrl}/`;
    const resp = await page.goto(url, { waitUntil: 'load', timeout: 60000 }).catch(() => null);

    await page.waitForTimeout(3500);

    const status = resp ? resp.status() : null;
    const finalUrl = page.url();

    const result = await page.evaluate(() => {
        const hasChartGlobal = typeof window.Chart === 'function';
        const canvases = Array.from(document.querySelectorAll('canvas'));
        let drawnCanvas = null;
        for (const c of canvases) {
            try {
                const ctx = c.getContext('2d');
                const d = ctx.getImageData(0, 0, Math.min(c.width, 300), Math.min(c.height, 300)).data;
                let nonBlank = 0;
                for (let i = 3; i < d.length; i += 4) if (d[i] !== 0) nonBlank++;
                if (nonBlank > 500) {
                    drawnCanvas = { id: c.id, width: c.width, height: c.height, paintedPixels: nonBlank };
                    break;
                }
            } catch (e) {}
        }
        return {
            hasChartGlobal,
            isRealChart: hasChartGlobal && typeof window.Chart.register === 'function'
                && window.Chart.register.toString().indexOf('noop') === -1,
            canvasCount: canvases.length,
            drawnCanvas,
            chartVersion: hasChartGlobal && window.Chart.version ? window.Chart.version : null,
        };
    });

    console.log(`URL النهائي      ${finalUrl}`);
    console.log(`HTTP             ${status}`);
    console.log(`window.Chart     ${result.hasChartGlobal ? 'موجود' : 'مفقود'}`);
    console.log(`نسخة Chart.js    ${result.chartVersion || '—'}`);
    console.log(`رسم مرسوم فعلاً  ${result.drawnCanvas ? 'نعم (' + result.drawnCanvas.paintedPixels + ' بكسل)' : 'لا'}`);
    console.log(`طلبات chart      ${chartRequests.length}`);
    chartRequests.forEach((u) => console.log(`   ${u.slice(0, 100)}`));
    console.log(`طلبات CDN خارجية ${external.length}`);
    external.forEach((u) => console.log(`   ${u.slice(0, 100)}`));

    await browser.close();
})();
