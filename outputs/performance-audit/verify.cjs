#!/usr/bin/env node
'use strict';

/**
 * Post-measurement verification for the Daway performance audit.
 *
 * Checks the raw measurement files for:
 *  - pages that were expected but never measured
 *  - measurements where the page silently fell back to /login (session not applied)
 *  - pages that stayed on an error status
 *  - writes that the guards blocked (must be exactly the known-safe set)
 *  - missing paint metrics, which the report must show as "—" and never as 0
 *
 * Read-only: prints findings, exits non-zero when something needs attention.
 */

const fs = require('fs');
const path = require('path');

const RAW_DIR = path.join(__dirname, 'raw');

const EXPECTED = {
    public: ['login', 'root-anon'],
    admin: [
        'admin-dashboard',
        'admin-medicines',
        'admin-medicines-create',
        'admin-categories',
        'admin-users',
        'admin-pharmacies',
        'admin-patients',
        'admin-inventory',
        'admin-settings',
        'admin-logs',
        'profile',
        'notifications',
    ],
    pharmacy: [
        'pharmacy-dashboard',
        'pharmacy-inventory',
        'pharmacy-inquiries',
        'pharmacy-medicines',
        'pharmacy-medicines-create',
        'pharmacy-alternatives',
        'pharmacy-alternatives-create',
        'pharmacy-ratings',
        'pharmacy-profile',
    ],
};

const ALLOWED_DENIED = new Set(['/api/sync/token', '/logs/export-excel']);

function loadRuns() {
    if (!fs.existsSync(RAW_DIR)) return [];
    return fs
        .readdirSync(RAW_DIR)
        .filter((f) => f.endsWith('.json'))
        .map((f) => JSON.parse(fs.readFileSync(path.join(RAW_DIR, f), 'utf8')))
        .filter((r) => r.results);
}

function main() {
    const runs = loadRuns();
    const problems = [];
    const notes = [];

    if (!runs.length) {
        console.error('لا توجد قياسات.');
        process.exit(1);
    }

    const seen = new Set();
    const byRole = {};

    for (const run of runs) {
        const role = run.role || 'public';
        byRole[role] = byRole[role] || { desktop: new Set(), mobile: new Set() };
        const bucket = run.device && run.device.includes('موبايل') ? 'mobile' : 'desktop';

        for (const r of run.results) {
            seen.add(r.id);
            bucket && byRole[role][bucket].add(r.id);

            if (!r.measurementOk) {
                problems.push(`تعذّر قياس ${r.id} (${role}/${bucket}): ${r.failure ? r.failure.name : 'غير معروف'}`);
                continue;
            }

            if (r.isLoginRedirect && r.id !== 'root-anon' && r.id !== 'login') {
                problems.push(`الصفحة ${r.id} (${role}/${bucket}) هبطت على /login — الجلسة لم تُطبَّق`);
            }

            if (r.isErrorPage) {
                notes.push(`خطأ HTTP ${r.httpStatus} في ${r.id} (${role}/${bucket})`);
            }

            if (r.fcpMs === null && r.lcpMs === null && r.id !== 'root-anon') {
                notes.push(`لا مؤشرات رسم في ${r.id} (${role}/${bucket}) — تُعرض كـ «—» في التقرير`);
            }
        }

        for (const denied of run.deniedRequests || []) {
            if (!ALLOWED_DENIED.has(denied.path)) {
                problems.push(`طلب محجوب غير متوقع: ${denied.method} ${denied.path} (${role})`);
            }
        }
    }

    for (const [role, expected] of Object.entries(EXPECTED)) {
        for (const id of expected) {
            if (!seen.has(id)) {
                notes.push(`لم تُقس بعد: ${id} (${role})`);
            }
        }
    }

    console.log('=== تغطية ===');
    for (const [role, buckets] of Object.entries(byRole)) {
        console.log(`${role}: سطح مكتب ${buckets.desktop.size} | موبايل ${buckets.mobile.size}`);
    }

    console.log('');
    console.log('=== ملاحظات ===');
    notes.length ? notes.forEach((n) => console.log('•', n)) : console.log('• لا شيء');

    console.log('');
    console.log('=== مشاكل ===');
    problems.length ? problems.forEach((p) => console.log('×', p)) : console.log('• لا شيء');

    process.exit(problems.length ? 2 : 0);
}

main();
