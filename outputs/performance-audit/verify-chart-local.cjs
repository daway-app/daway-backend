#!/usr/bin/env node
'use strict';

/**
 * يسجّل الدخول محليًا بحساب اختباري ثم يتحقق أن Chart.js المحلي يُحمَّل
 * ويُنشئ رسمًا فعليًا، وأنه لم تعد هناك طلبات CDN للرسوم.
 *
 * بيانات الدخول تُقرأ من متغيرات البيئة فقط (لا تُكتب في أي ملف):
 *   AUDIT_LOGIN_IDENTITY  — البريد (أدمن) أو Pharmacy ID (صيدلية)
 *   AUDIT_LOGIN_PASSWORD  — كلمة المرور
 *   AUDIT_LOGIN_TYPE      — admin | pharmacy
 *
 * الاستخدام:
 *   node verify-chart-local.cjs <baseUrl>
 */

const fs = require('fs');
const { chromium } = require('playwright-core');

const baseUrl = (process.argv[2] || 'http://127.0.0.1:8126').replace(/\/$/, '');
const identity = process.env.AUDIT_LOGIN_IDENTITY;
const password = process.env.AUDIT_LOGIN_PASSWORD;
const type = process.env.AUDIT_LOGIN_TYPE || 'admin';
const targetPath = process.env.AUDIT_TARGET_PATH || (type === 'admin' ? '/' : '/pharmacy/dashboard');

if (!identity || !password) {
    console.error('لازم تضبط AUDIT_LOGIN_IDENTITY و AUDIT_LOGIN_PASSWORD.');
    process.exit(1);
}

(async () => {
    const browser = await chromium.launch({ channel: 'chrome', headless: true });
    const context = await browser.newContext({
        viewport: { width: 1440, height: 900 },
        locale: 'ar',
        serviceWorkers: 'block',
    });
    const page = await context.newPage();

    const external = [];
    const localChart = [];
    const jsErrors = [];

    page.on('request', (req) => {
        const u = req.url();
        if (/cdn\.jsdelivr\.net|cdnjs\.cloudflare\.com/.test(u)) external.push(u);
        if (/chart\.umd\.js/.test(u)) localChart.push(u);
    });
    page.on('pageerror', (e) => jsErrors.push(String(e).slice(0, 160)));

    // --- تسجيل الدخول ---
    await page.goto(`${baseUrl}/login`, { waitUntil: 'domcontentloaded', timeout: 60000 });

    await page.selectOption('#account_type', type).catch(() => {});
    await page.fill('#identityInput', identity);
    await page.fill('#passwordInput', password);
    await Promise.all([
        page.waitForNavigation({ waitUntil: 'load', timeout: 60000 }).catch(() => {}),
        page.click('#submitBtn'),
    ]);

    await page.waitForTimeout(2000);
    const afterLogin = page.url();

    // --- الصفحة المستهدفة ---
    await page.goto(`${baseUrl}${targetPath}`, { waitUntil: 'load', timeout: 60000 }).catch(() => {});
    await page.waitForTimeout(3500);

    const result = await page.evaluate(() => {
        const hasChart = typeof window.Chart === 'function';
        const canvases = Array.from(document.querySelectorAll('canvas'));
        let drawn = null;
        for (const c of canvases) {
            try {
                const ctx = c.getContext('2d');
                const d = ctx.getImageData(0, 0, Math.min(c.width, 400), Math.min(c.height, 400)).data;
                let painted = 0;
                for (let i = 3; i < d.length; i += 4) if (d[i] !== 0) painted++;
                if (painted > 500) { drawn = { id: c.id || '(بلا id)', painted }; break; }
            } catch (e) {}
        }
        return {
            hasChart,
            version: (hasChart && window.Chart.version) || null,
            isStub: hasChart && window.Chart.register && window.Chart.register.toString().indexOf('noop') !== -1,
            canvasCount: canvases.length,
            drawn,
        };
    });

    console.log(`بعد الدخول       ${afterLogin}`);
    console.log(`الصفحة المقاسة   ${page.url()}`);
    console.log(`window.Chart     ${result.hasChart ? 'موجود' : 'مفقود'}`);
    console.log(`نسخة Chart.js    ${result.version || '—'}`);
    console.log(`ستَب وهمي؟       ${result.isStub ? 'نعم (سئ)' : 'لا (مكتبة حقيقية)'}`);
    console.log(`عدد canvas       ${result.canvasCount}`);
    console.log(`رسم مرسوم فعلاً  ${result.drawn ? 'نعم — "' + result.drawn.id + '" (' + result.drawn.painted + ' بكسل)' : 'لا'}`);
    console.log(`ملف chart المحلي ${localChart.length}`);
    localChart.forEach((u) => console.log(`   ${u.slice(0, 110)}`));
    console.log(`طلبات CDN للرسوم ${external.length}`);
    external.forEach((u) => console.log(`   ${u.slice(0, 110)}`));
    if (jsErrors.length) {
        console.log(`أخطاء JS (${jsErrors.length}):`);
        jsErrors.slice(0, 5).forEach((e) => console.log(`   ${e}`));
    } else {
        console.log('أخطاء JS         لا شيء');
    }

    await browser.close();
})();
