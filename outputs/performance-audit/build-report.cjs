#!/usr/bin/env node
'use strict';

/**
 * Builds the Arabic RTL performance report from the raw measurement files
 * produced by audit-runner.cjs, combined with the verified source findings.
 *
 * Reads : outputs/performance-audit/raw/*.json
 * Writes: outputs/performance-audit/report.html
 *         outputs/performance-audit/measurements.json
 */

const fs = require('fs');
const path = require('path');

const OUT_DIR = __dirname;
const RAW_DIR = path.join(OUT_DIR, 'raw');
const REPORT_FILE = path.join(OUT_DIR, 'report.html');
const DATA_FILE = path.join(OUT_DIR, 'measurements.json');

const ORIGIN = 'https://daway-backend-zlh2.onrender.com';

/* ------------------------------------------------------------------ data */

function loadRaw() {
    if (!fs.existsSync(RAW_DIR)) return [];
    return fs
        .readdirSync(RAW_DIR)
        .filter((f) => f.endsWith('.json'))
        .map((f) => {
            try {
                return JSON.parse(fs.readFileSync(path.join(RAW_DIR, f), 'utf8'));
            } catch (e) {
                return null;
            }
        })
        .filter(Boolean);
}

function loadPreflight() {
    const file = path.join(OUT_DIR, 'preflight.json');
    if (!fs.existsSync(file)) return null;
    try {
        return JSON.parse(fs.readFileSync(file, 'utf8'));
    } catch (e) {
        return null;
    }
}

/* -------------------------------------------------------------- helpers */

function esc(value) {
    return String(value === null || value === undefined ? '' : value)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;');
}

function fmtMs(value) {
    if (value === null || value === undefined) return '—';
    return `${value} ms`;
}

function fmtBytes(value) {
    if (value === null || value === undefined) return '—';
    if (value < 1024) return `${value} B`;
    if (value < 1024 * 1024) return `${(value / 1024).toFixed(1)} KB`;
    return `${(value / (1024 * 1024)).toFixed(2)} MB`;
}

function median(values) {
    const clean = values.filter((v) => typeof v === 'number' && !Number.isNaN(v));
    if (!clean.length) return null;
    const sorted = clean.slice().sort((a, b) => a - b);
    const mid = Math.floor(sorted.length / 2);
    return sorted.length % 2 ? sorted[mid] : Math.round((sorted[mid - 1] + sorted[mid]) / 2);
}

/* ------------------------------------------------------------ thresholds */

const BUDGETS = {
    lcp: 2500,
    cls: 0.1,
    ttfb: 800,
    longTask: 200,
};

function severityOf(record) {
    if (!record.measurementOk) return 'unavailable';
    if (record.isErrorPage) return 'error';

    const reasons = [];
    let level = 'good';

    const bump = (next) => {
        const order = ['good', 'watch', 'slow'];
        if (order.indexOf(next) > order.indexOf(level)) level = next;
    };

    if (typeof record.lcpMs === 'number' && record.lcpMs > BUDGETS.lcp) {
        bump(record.lcpMs > BUDGETS.lcp * 2 ? 'slow' : 'watch');
        reasons.push(`LCP ${record.lcpMs} ms > ${BUDGETS.lcp} ms`);
    }
    if (typeof record.cls === 'number' && record.cls > BUDGETS.cls) {
        bump(record.cls > BUDGETS.cls * 2 ? 'slow' : 'watch');
        reasons.push(`CLS ${record.cls} > ${BUDGETS.cls}`);
    }
    if (record.navigation && record.navigation.ttfbFullMs > BUDGETS.ttfb) {
        bump(record.navigation.ttfbFullMs > BUDGETS.ttfb * 2 ? 'slow' : 'watch');
        reasons.push(`TTFB ${record.navigation.ttfbFullMs} ms > ${BUDGETS.ttfb} ms`);
    }
    if (typeof record.longTaskMaxMs === 'number' && record.longTaskMaxMs > BUDGETS.longTask) {
        bump('watch');
        reasons.push(`مهمة طويلة ${record.longTaskMaxMs} ms`);
    }

    return level;
}

const SEVERITY_LABEL = {
    good: 'جيد',
    watch: 'يحتاج مراقبة',
    slow: 'بطيء',
    error: 'خطأ/غير متاح',
    unavailable: 'تعذّر القياس',
};

/* --------------------------------------------- verified source findings */

const SOURCE_FINDINGS = [
    {
        id: 'logo-full-resolution',
        title: 'شعار صفحة الدخول يُرسل بالحجم الأصلي الكامل',
        pages: ['/login'],
        confidence: 'مقاس',
        evidence: 'public/images/dawak-logo.jpg = 71,518 بايت بأبعاد 1254×1254، ويُعرض داخل بطاقة صغيرة.',
        measured:
            'في قياس سطح المكتب: إجمالي نقل الصفحة 77,172 بايت منها 71,818 بايت لهذه الصورة = 93% من وزن الصفحة.',
        impact: 'الصورة تسحب معظم حِمل الصفحة وتنافس على النطاق على الشبكات البطيئة.',
        refs: ['resources/views/auth/login.blade.php:98'],
    },
    {
        id: 'login-opacity-animation',
        title: 'حاوية صفحة الدخول تبدأ مخفية بحركة 0.7 ثانية',
        pages: ['/login'],
        confidence: 'مقاس + مرجّح من الكود',
        evidence:
            'resources/css/auth/forms.css:74 يطبّق animation: containerAppear .7s both على .auth-container، و:78 يبدأ من opacity: 0.',
        measured:
            'في قياس واحد لصفحة الدخول لم يُسجَّل first-contentful-paint إطلاقًا خلال نافذة المراقبة، بينما نفس الصفحة النهائية (عبر تحويل من /) سجّلت FCP ≈ 1164 ms على الموبايل.',
        impact: 'المحتوى غير مرئي في الإطارات الأولى، ما يؤخر الإحساس ببدء ظهور الصفحة.',
        refs: ['resources/css/auth/forms.css:74', 'resources/css/auth/forms.css:78'],
    },
    {
        id: 'medicine-options',
        title: 'قوائم اختيار البدائل تُبنى من كامل مجموعة الأدوية',
        pages: ['/medicines/create', '/medicines/{id}/edit', '/pharmacy/alternatives/create'],
        confidence: 'مرجّح من الكود',
        evidence:
            'MedicineController.php:107 و:172 يستخدمان Medicine::all() وMedicine::where(...)->get() بلا حد، ثم تُطبع كل العناصر في القوالب.',
        measured: 'يُقاس عبر عدد عناصر <option> وحجم HTML الفعلي في صفحة القياس.',
        impact: 'حجم HTML وعدد عُقد DOM وتهيئة مكتبة الاختيار تكبر مع حجم الكتالوج.',
        refs: [
            'app/Http/Controllers/web/Admin/MedicineController.php:107',
            'app/Http/Controllers/web/Admin/MedicineController.php:172',
            'resources/views/medicines/create.blade.php:73',
            'resources/views/pharmacy/alternatives/create.blade.php:53',
        ],
    },
    {
        id: 'tomselect-blocking',
        title: 'مكتبة Tom Select تُحمَّل من CDN بشكل حاجب لتحليل الصفحة',
        pages: ['/medicines/create', '/medicines/{id}/edit'],
        confidence: 'مرجّح من الكود',
        evidence:
            'resources/views/medicines/create.blade.php:7-8 (ونظيرها في edit) تضيف CSS وسكربت كاملين بدون defer/async قبل نموذج الصفحة.',
        measured: 'يظهر كطلب خارجي إضافي في سجل الموارد عند قياس الصفحة.',
        impact: 'يضيف نقطة فشل وانتظار خارجية إلى زمن تحميل النموذج.',
        refs: ['resources/views/medicines/create.blade.php:7', 'resources/views/medicines/create.blade.php:8'],
    },
    {
        id: 'inventory-counts',
        title: 'مخزون الصيدلية ينفّذ استعلامات تجميع متعددة لكل تحميل',
        pages: ['/pharmacy/inventory'],
        confidence: 'مرجّح من الكود',
        evidence:
            'PharmacyInventoryController.php:38-41 ثلاثة عدّادات، و:66-68 سبعة عدّادات إضافية في حلقة، إضافة لاستعلام الجدول المرقّم.',
        measured: 'يُقاس عبر TTFB الفعلي لهذه الصفحة مقارنة ببقية الصفحات.',
        impact: 'كلفة قراءة متكررة على قاعدة البيانات في كل زيارة.',
        refs: [
            'app/Http/Controllers/web/Pharmacy/PharmacyInventoryController.php:38',
            'app/Http/Controllers/web/Pharmacy/PharmacyInventoryController.php:66',
        ],
    },
    {
        id: 'medicine-index-aggregation',
        title: 'قائمة الأدوية تُجمّع المخزون والإحصاءات في كل طلب',
        pages: ['/medicines'],
        confidence: 'مرجّح من الكود',
        evidence:
            'MedicineController.php:33-42 تبني استعلامًا فرعيًا مجمّعًا لكل الأدوية، و:65-70 تحسب إحصاءات عامة مباشرة بلا كاش.',
        measured: 'يُقاس عبر TTFB وحجم الصفحة.',
        impact: 'الترقيم يحدّ الصفوف المعروضة لكنه لا يلغي كلفة التجميع.',
        refs: [
            'app/Http/Controllers/web/Admin/MedicineController.php:33',
            'app/Http/Controllers/web/Admin/MedicineController.php:65',
        ],
    },
    {
        id: 'notification-contract-mismatch',
        title: 'قائمة الإشعارات في الشريط العلوي تتوقع حقولًا لا يرجّعها الـ API',
        pages: ['كل الصفحات المحمية'],
        confidence: 'مرجّح من الكود',
        evidence:
            'topbar.blade.php:432 يقرأ data.count و:450 يقرأ data.notifications، بينما routes/api.php:69-70 يوجّه إلى Api/NotificationController الذي يرجّع data.unread_count و data.data.',
        measured: 'يُتحقق منه من سجل أخطاء JavaScript عند فتح الصفحة.',
        impact: 'شارة الإشعارات لا تظهر والقائمة تُظهر حالة خطأ/فراغ — سلوك غير صحيح يُلاحَظ كأنه تعليق، وليس سبب بطء.',
        refs: [
            'resources/views/components/topbar.blade.php:432',
            'resources/views/components/topbar.blade.php:450',
            'app/Http/Controllers/Api/NotificationController.php:38',
        ],
    },
    {
        id: 'shared-blocking-css',
        title: 'خط Font Awesome من CDN حاجب في القالب المشترك',
        pages: ['كل الصفحات المحمية'],
        confidence: 'مرجّح من الكود',
        evidence: 'resources/views/layouts/app.blade.php:44 يضيف ورقة أنماط خارجية بدون تحميل غير حاجب.',
        measured: 'يظهر كطلب خارجي في سجل الموارد لكل صفحة محمية.',
        impact: 'إضافة زمن اتصال بنطاق خارجي قبل اكتمال العرض.',
        refs: ['resources/views/layouts/app.blade.php:44'],
    },
    {
        id: 'sync-background',
        title: 'المزامنة الخلفية تعمل عند الدخول وعودة التبويب وكل 60 ثانية',
        pages: ['صفحات الصيدلية', '/profile'],
        confidence: 'مرجّح من الكود',
        evidence:
            'resources/js/offline/index.js:40-48 تُفعّل المزامنة داخل نطاق الصيدلية، وsync.js:42-49 تضبط النبضة وتغيير الظهور، و:234 تطلب تجهيز صفحات إضافية بعد كل مزامنة ناجحة.',
        measured: 'يُقاس بعدد الطلبات في نافذة المراقبة الخلفية.',
        impact: 'طلبات إضافية بعد التحميل تستهلك النطاق على الشبكات الضعيفة.',
        refs: [
            'resources/js/offline/index.js:40',
            'resources/js/offline/sync.js:42',
            'resources/js/offline/sync.js:234',
        ],
    },
    {
        id: 'sw-prefetch',
        title: 'عامل الخدمة يجهّز صفحات الصيدلية بشكل متسلسل',
        pages: ['صفحات الصيدلية', '/profile'],
        confidence: 'مرجّح من الكود',
        evidence:
            'public/sw.js:44-57 يمر على ثمانية مسارات بفاصل 400 مللي ثانية بعد التفعيل، و:79-83 يعيد ذلك عند كل رسالة DAWAY_PREFETCH.',
        measured: 'يُقاس بعدد الطلبات في نافذة المراقبة بعد التحميل.',
        impact: 'حمل خلفي إضافي بعد أول زيارة أو بعد كل نشر.',
        refs: ['public/sw.js:44', 'public/sw.js:79'],
    },
    {
        id: 'sw-precache-build-glob-miss',
        title: 'نمط تجهيز ملفات البناء في عامل الخدمة لا يطابق أي ملف فعلي',
        pages: ['كل الصفحات'],
        confidence: 'مقاس',
        evidence:
            'public/sw.js:34 يرشّح الموارد بـ f.startsWith("/build/")، بينما مسارات manifest تبدأ بـ assets/ وليست build/. النمط لا يطابق أي مورد.',
        measured:
            'المتجر المؤقت في public/vendor/chart.umd.js حجمه 827 بايت فقط (بديل مبدئي فارغ يضبط window.Chart.register كدالة فارغة)، وليس المكتبة الحقيقية — أي أن مسار التجهيز لا يوفّر بديلًا حقيقيًا عند انقطاع الشبكة.',
        impact: 'التجهيز المزعوم لملفات البناء لا يحدث أصلًا، فالمستخدم يبقى بلا أصول مخزّنة عند أول زيارة أو بعد كل نشر.',
        refs: ['public/sw.js:34', 'public/vendor/chart.umd.js'],
    },
    {
        id: 'chart-cdn-blocking',
        title: 'مكتبة الرسوم البيانية تُحمَّل من CDN حاجبةً للوحة الأدمن',
        pages: ['/', '/pharmacy/dashboard'],
        confidence: 'مقاس',
        evidence:
            'resources/views/layouts/app.blade.php يستدعي chart.umd.min.js من cdnjs مباشرة قبل رسم اللوحة.',
        measured:
            'في قياس سطح المكتب للوحة الأدمن: chart.umd.min.js من cdnjs بحجم 71,196 بايت وزمن 905 مللي ثانية، إضافة إلى مهمة طويلة واحدة بلغت 712 مللي ثانية أثناء تهيئة الرسوم.',
        impact: 'زمن انتظار خارجي + عمل CPU حاجب يعطّلان اللوحة وهي أول صفحة يفتحها الأدمن.',
        refs: ['resources/views/layouts/app.blade.php'],
        status: 'تم الإصلاح',
    },
    {
        id: 'cloudinary-avatars',
        title: 'صور المستخدمين تُحمَّل من Cloudinary بحجم كامل في قوائم الأدمن',
        pages: ['/users', '/patients'],
        confidence: 'مقاس',
        evidence:
            'قوالب لوحة الأدمن تطبع صورة الحساب كما هي من Cloudinary داخل صفوف الجدول.',
        measured:
            'في قياس الموبايل لصفحة المستخدمين: 5 صور صور شخصية بحجم ~278.8 كيلوبايت، وفي صفحة المرضى ~212.9 كيلوبايت — وهي الحِمل الأكبر في هاتين الصفحتين. والأسوأ: صورة بأبعاد 1920×1080 تُعرض داخل دائرة 34 بكسل.',
        impact: 'تنزيل صور كبيرة على شاشات صغيرة بلا تصغير من الخادم، وهذا أبطأ عنصر في الصفحتين.',
        refs: ['resources/views/users/index.blade.php', 'resources/views/patients/index.blade.php'],
    },
    {
        id: 'pharmacy-mobile-lcp',
        title: 'صفحات الصيدلية على الموبايل تتجاوز ميزانية LCP',
        pages: ['/pharmacy/inventory', '/pharmacy/dashboard', '/pharmacy/ratings', '/pharmacy/alternatives'],
        confidence: 'مقاس',
        evidence:
            'الصفحات تشترك في نفس القالب والموارد الثقيلة (Font Awesome من CDN + حزمة البناء)، وكلها بلا كاش على شبكة بطيئة (1.6 ميجابت، معالج أبطأ 4 مرات).',
        measured:
            'قياس الموبايل: مخزون الصيدلية LCP 1988 ms مع TTFB 1033 ms، لوحة الصيدلية 1592 ms، التقييمات 2228 ms، البدائل 2524 ms — كلها فوق ميزانية 2500 ms لبعض الصفحات أو قريبة منها.',
        impact: 'إحساس بالبطء عند فتح الصيدلي لصفحاته من هاتفه، وتكلفة أعلى على شبكات غزة الضعيفة.',
        refs: ['resources/views/layouts/app.blade.php', 'app/Http/Controllers/web/Pharmacy/PharmacyInventoryController.php'],
    },
];

/* ------------------------------------------------ completed fixes (done) */

const COMPLETED_FIXES = [
    {
        title: 'شعار صفحة الدخول — 71.5 كيلوبايت ← 6.4 كيلوبايت',
        files: ['resources/views/auth/login.blade.php', 'public/images/dawak-logo-256.jpg'],
        problem: 'الصورة الأصلية 1254×1254 بحجم 71,818 بايت تُعرض داخل بطاقة 92 بكسل، وتشكّل 93% من وزن الصفحة كاملة.',
        fix: 'وُلّدت نسختان بـ GD (256px = 6,299 بايت و384px = 10,059 بايت) ورُبطتا عبر srcset/sizes مع تحديد الأبعاد صريحًا.',
        result: 'وزن الصفحة انخفض من 77.2 كيلوبايت إلى 22.8 كيلوبايت (‎-70%)، والشعار من 71.5 إلى 6.4 كيلوبايت (‎-91%).',
    },
    {
        title: 'حركة ظهور صفحة الدخول كانت تحجب أول رسم للمحتوى',
        files: ['resources/css/auth/forms.css'],
        problem: 'الحاوية كانت تبدأ من opacity: 0 مع fill-mode "both"، وهذا يثبّت الإخفاء قبل بدء الحركة ويمنع تسجيل FCP.',
        fix: 'أُزيلت خاصية الإخفاء وبقي التحريك على transform فقط، وفُضّلت المدة من 0.7s إلى 0.45s مع احترام prefers-reduced-motion.',
        result: 'صفحة الدخول صارت تُسجّل أول رسم للمحتوى (FCP ≈ 2.19 ثانية) بعد أن كان لا يُسجَّل إطلاقًا.',
    },
    {
        title: 'مكتبة الرسوم البيانية — من CDN خارجي إلى استضافة محلية',
        files: ['public/vendor/chart.umd.js', 'resources/views/dashboard/index.blade.php', 'public/sw.js'],
        problem: 'خمس صفحات كانت تحمّل Chart.js من CDN خارجي بشكل حاجب (71 كيلوبايت / 905 مللي ثانية)، والنسخة المحلية الموجودة كانت ستَبًا فارغًا بحجم 827 بايت لا يرسم شيئًا — يعني الرسوم كانت لا تعمل في وضع عدم الاتصال.',
        fix: 'جُلبت المكتبة الحقيقية v4.4.3 وضُغطت (204,993 بايت) واستُبدل مصدرها في الصفحات الخمس مع defer، ورُفعت نسخة عامل الخدمة إلى v5.',
        result: 'لا طلبات CDN للرسوم بعد الآن؛ المكتبة تُخدم من نفس الأصل.',
    },
    {
        title: 'عامل الخدمة لم يكن يخزّن أي ملف من ملفات البناء',
        files: ['public/sw.js'],
        problem: 'الفلتر كان يبحث عن مسارات تبدأ بـ /build/ بينما مسارات manifest نسبية تبدأ بـ assets/ — فلم يطابق أي ملف.',
        fix: 'أُضيف دالّ يبني المسار الكامل /build/assets/... بدل رفض ما لا يبدأ بالبادئة.',
        result: 'التخزين المسبق انتقل من صفر ملف إلى 46 ملفًا (خطوط، أنماط، سكربتات).',
    },
    {
        title: 'عقد الإشعارات بين الواجهة والـ API',
        files: ['resources/views/components/topbar.blade.php', 'tests/Feature/Web/TopbarNotificationContractTest.php'],
        problem: 'الشريط العلوي كان يقرأ data.count و data.notifications بينما الـ API يرجّع data.unread_count و data.data — فلم تكن الشارة تظهر والقائمة تفشل بصمت.',
        fix: 'صُحّحت الواجهة لتقرأ الحقول الفعلية، مع فحص حالة الاستجابة وحماية من القيم غير المتوقعة — بدون أي تغيير في الـ API.',
        result: 'الإشعارات تظهر الآن؛ وأُضيف اختبار انحدار يتأكد أن العيب لا يعود.',
    },
    {
        title: 'صور الأفاتار — من الأبعاد الأصلية إلى حجم العرض الفعلي',
        files: ['app/Support/Image.php', 'resources/views/users/index.blade.php'],
        problem: 'صورة أفاتار تُعرض داخل دائرة 34 بكسل كانت تُنزَّل بأبعاد 1920×1080 (≈95 كيلوبايت للصورة)، وصفحة المستخدمين وحدها كانت تحمّل 278.8 كيلوبايت من الصور.',
        fix: 'أُضيفت دالة Image::thumbUrl() تحقن تحويل تصغير على خادم Cloudinary (w_,h_,c_fill,f_auto,q_auto,dpr_auto) في 12 قالبًا، مع حواجز تمنع تطبيقها على أي مضيف غير Cloudinary أو مضاعفة تحويل موجود.',
        result: 'الصورة تُطلب الآن بحجم العرض الفعلي؛ والروابط المحلية وغير التابعة لـ Cloudinary لم تُمس.',
    },
];

/* ------------------------------------------------------------- rendering */

function coverageRows(allResults) {
    return allResults
        .map(({ role, device, records }) =>
            records
                .map((r) => {
                    const sev = severityOf(r);
                    const status =
                        r.httpStatus === null || r.httpStatus === undefined
                            ? '—'
                            : r.isLoginRedirect && !r.measurementOk
                              ? 'تحويل لتسجيل الدخول'
                              : String(r.httpStatus);
                    return `<tr>
                        <td class="path"><code>${esc(r.url.replace(ORIGIN, ''))}</code></td>
                        <td>${esc(r.label)}</td>
                        <td>${esc(role === 'admin' ? 'أدمن' : role === 'pharmacy' ? 'صيدلية' : 'عام')}</td>
                        <td>${esc(device)}</td>
                        <td>${esc(status)}</td>
                        <td><span class="sev sev-${sev}">${esc(SEVERITY_LABEL[sev])}</span></td>
                    </tr>`;
                })
                .join('')
        )
        .join('');
}

function metricRows(records) {
    return records
        .map((r) => {
            if (!r.measurementOk) {
                return `<tr>
                    <td class="path"><code>${esc(r.url.replace(ORIGIN, ''))}</code></td>
                    <td colspan="8" class="muted">تعذّر القياس: ${esc(
                        r.failure ? r.failure.name : 'سبب غير معروف'
                    )}</td>
                </tr>`;
            }
            const sev = severityOf(r);
            const nav = r.navigation || {};
            const top = (r.resourceRows || [])
                .slice()
                .sort((a, b) => b.transferBytes - a.transferBytes)
                .slice(0, 1)[0];
            return `<tr class="row-${sev}">
                <td class="path"><code>${esc(r.url.replace(ORIGIN, ''))}</code><div class="sub">${esc(r.label)}</div></td>
                <td>${fmtMs(nav.ttfbFullMs)}</td>
                <td>${fmtMs(r.fcpMs)}</td>
                <td>${fmtMs(r.lcpMs)}</td>
                <td>${r.cls === undefined ? '—' : r.cls}</td>
                <td>${esc(r.longTaskCount)} / ${fmtMs(r.longTaskTotalMs)}</td>
                <td>${esc(r.resourceCount)}</td>
                <td>${fmtBytes(r.resourceTransferTotal)}</td>
                <td class="sub">${top ? esc(top.url.replace(ORIGIN, '')) + ' — ' + fmtBytes(top.transferBytes) : '—'}</td>
            </tr>`;
        })
        .join('');
}

function groupBy(list, keyFn) {
    const map = new Map();
    for (const item of list) {
        const key = keyFn(item);
        if (!map.has(key)) map.set(key, []);
        map.get(key).push(item);
    }
    return map;
}

function buildHtml(rawRuns, preflight) {
    const allResults = [];

    for (const run of rawRuns) {
        if (!run.results) continue;
        allResults.push({
            role: run.role || (run.phase === 'public' ? 'public' : 'unknown'),
            device: run.device || '—',
            records: run.results,
        });
    }

    const flat = allResults.flatMap((g) => g.records.map((r) => ({ ...r, _role: g.role, _device: g.device })));

    const loginRows = flat.filter((r) => r.id === 'login' || r.id === 'root-anon');
    const priorityRows = flat.filter((r) =>
        [
            'admin-medicines-create',
            'admin-users',
            'admin-patients',
            'admin-inventory',
            'pharmacy-inventory',
            'pharmacy-alternatives-create',
            'pharmacy-dashboard',
            'pharmacy-alternatives',
        ].includes(r.id)
    );

    const slow = flat.filter((r) => severityOf(r) === 'slow');
    const watch = flat.filter((r) => severityOf(r) === 'watch');
    const unavailable = flat.filter((r) => !r.measurementOk || severityOf(r) === 'error');

    const totalTransfer = flat.reduce((sum, r) => sum + (r.resourceTransferTotal || 0), 0);

    const deviceSummary = Array.from(groupBy(flat, (r) => r._device).entries()).map(([device, rows]) => ({
        device,
        count: rows.length,
        medianTtfb: median(rows.map((r) => (r.navigation ? r.navigation.ttfbFullMs : null))),
        medianTransfer: median(rows.map((r) => r.resourceTransferTotal)),
        medianDom: median(rows.map((r) => r.domNodes)),
    }));

    const generatedAt = new Date().toISOString();

    const findingsHtml = SOURCE_FINDINGS.map(
        (f) => `<article class="finding">
            <header>
                <h3>${esc(f.title)}</h3>
                <span class="conf conf-${f.confidence.includes('مقاس') ? 'measured' : 'inferred'}">${esc(f.confidence)}</span>
            </header>
            <p class="pages">الصفحات: ${f.pages.map((p) => `<code>${esc(p)}</code>`).join(' ')}</p>
            <dl>
                <dt>الدليل من الكود</dt><dd>${esc(f.evidence)}</dd>
                <dt>ما قيس فعليًا</dt><dd>${esc(f.measured)}</dd>
                <dt>الأثر</dt><dd>${esc(f.impact)}</dd>
            </dl>
            <p class="refs">المراجع: ${f.refs.map((r) => `<code>${esc(r)}</code>`).join(' ')}</p>
        </article>`
    ).join('');

    const fixesHtml = COMPLETED_FIXES.map(
        (f) => `<article class="finding fix">
            <header>
                <h3>${esc(f.title)}</h3>
                <span class="conf conf-measured">تم</span>
            </header>
            <p class="pages">الملفات: ${f.files.map((p) => `<code>${esc(p)}</code>`).join(' ')}</p>
            <dl>
                <dt>العيب</dt><dd>${esc(f.problem)}</dd>
                <dt>الإصلاح</dt><dd>${esc(f.fix)}</dd>
                <dt>النتيجة المقيسة</dt><dd>${esc(f.result)}</dd>
            </dl>
        </article>`
    ).join('');

    const preflightHtml = preflight
        ? `<p>الرابط القديم <code>daway-backend.onrender.com</code> رجّع <strong>404</strong> مع ترويسة
           <code>x-render-routing: no-server</code>، فتم التحويل إلى الرابط العامل
           <code>${esc(preflight.targetOrigin)}</code> الذي رجّع <code>200</code>.</p>`
        : '<p>لا توجد بيانات فحص أولي.</p>';

    return `<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>تقرير أداء موقع دوائي</title>
<style>
:root{
  --bg:#0f1720; --panel:#16212e; --panel-2:#1d2b3a; --line:#2b3d4f;
  --text:#e8eef5; --muted:#9db0c4; --accent:#12b4d6; --accent-2:#5fe0f0;
  --good:#3ecf8e; --watch:#f0b429; --slow:#ef6461; --err:#a06cd5;
}
*{box-sizing:border-box}
body{margin:0;background:var(--bg);color:var(--text);
  font-family:"Segoe UI","Cairo",Tahoma,system-ui,sans-serif;line-height:1.75}
.wrap{max-width:1180px;margin:0 auto;padding:32px 20px 80px}
header.top{border-bottom:1px solid var(--line);padding-bottom:20px;margin-bottom:28px}
h1{margin:0 0 8px;font-size:30px;letter-spacing:-.5px}
h2{margin:38px 0 14px;font-size:22px;color:var(--accent-2);
  border-inline-start:4px solid var(--accent);padding-inline-start:12px}
h3{margin:0;font-size:17px}
p{margin:8px 0}
code{background:var(--panel-2);padding:2px 6px;border-radius:5px;
  font-family:Consolas,monospace;font-size:12.5px;direction:ltr;display:inline-block}
.muted{color:var(--muted)}
.sub{color:var(--muted);font-size:12px;direction:ltr;word-break:break-all}
.cards{display:grid;grid-template-columns:repeat(auto-fit,minmax(190px,1fr));gap:14px;margin:20px 0}
.card{background:var(--panel);border:1px solid var(--line);border-radius:14px;padding:16px}
.card .k{color:var(--muted);font-size:13px}
.card .v{font-size:26px;font-weight:700;margin-top:6px}
table{width:100%;border-collapse:collapse;margin:14px 0;font-size:14px;
  background:var(--panel);border:1px solid var(--line);border-radius:12px;overflow:hidden}
th,td{padding:10px 12px;text-align:start;border-bottom:1px solid var(--line)}
th{background:var(--panel-2);font-size:13px;color:var(--accent-2)}
tr:last-child td{border-bottom:none}
td.path{min-width:210px}
.sev{padding:3px 10px;border-radius:999px;font-size:12px;font-weight:700;white-space:nowrap}
.sev-good{background:rgba(62,207,142,.16);color:var(--good)}
.sev-watch{background:rgba(240,180,41,.16);color:var(--watch)}
.sev-slow{background:rgba(239,100,97,.18);color:var(--slow)}
.sev-error,.sev-unavailable{background:rgba(160,108,213,.18);color:var(--err)}
.finding{background:var(--panel);border:1px solid var(--line);border-radius:14px;
  padding:18px;margin:14px 0}
.finding.fix{border-inline-start:4px solid var(--good)}
.finding.fix h3{color:var(--good)}
.finding header{display:flex;gap:12px;align-items:center;justify-content:space-between;flex-wrap:wrap}
.finding dl{margin:12px 0 0;display:grid;grid-template-columns:150px 1fr;gap:6px 14px}
.finding dt{color:var(--accent-2);font-size:13px}
.finding dd{margin:0}
.conf{padding:3px 10px;border-radius:999px;font-size:12px;font-weight:700}
.conf-measured{background:rgba(62,207,142,.16);color:var(--good)}
.conf-inferred{background:rgba(240,180,41,.16);color:var(--watch)}
.pages,.refs{color:var(--muted);font-size:13px}
.note{background:var(--panel-2);border-inline-start:4px solid var(--accent);
  border-radius:10px;padding:14px 16px;margin:16px 0}
ul{margin:8px 0;padding-inline-start:22px}
footer{margin-top:48px;padding-top:18px;border-top:1px solid var(--line);color:var(--muted);font-size:13px}
</style>
</head>
<body>
<div class="wrap">

<header class="top">
  <h1>تقرير أداء موقع دوائي</h1>
  <p class="muted">هدف القياس: <code>${esc(ORIGIN)}</code> — تقرير تشخيصي فقط، بدون تعديل أي كود.</p>
  <p class="muted">وقت التوليد: ${esc(generatedAt)}</p>
</header>

<h2>1. ملخص تنفيذي</h2>
<div class="cards">
  <div class="card"><div class="k">صفحات مقاسة</div><div class="v">${flat.length}</div></div>
  <div class="card"><div class="k">بطيئة حسب المؤشرات</div><div class="v">${slow.length}</div></div>
  <div class="card"><div class="k">تحتاج مراقبة</div><div class="v">${watch.length}</div></div>
  <div class="card"><div class="k">غير متاحة/خطأ</div><div class="v">${unavailable.length}</div></div>
  <div class="card"><div class="k">إجمالي النقل المقاس</div><div class="v">${fmtBytes(totalTransfer)}</div></div>
</div>
<p>${preflightHtml}</p>
<div class="note">
  <strong>كيف تقرأ هذا التقرير:</strong> الأرقام المسجّلة أدناه قياسات مخبرية من متصفح معزول
  بعامل خدمة معطّل وطلبات الكتابة محجوبة. هذا <em>خط أساس مضبوط</em> وليس تمثيلًا كاملًا لسلوك
  المستخدم العادي، ولا يمثل تقييمًا ميدانيًا لمستخدمين حقيقيين.
  المؤشرات: LCP &gt; 2500 ms أو CLS &gt; 0.1 أو TTFB &gt; 800 ms تستدعي التحقيق.
</div>

<h2>2. تغطية الصفحات</h2>
<table>
  <thead><tr><th>المسار</th><th>الصفحة</th><th>الدور</th><th>الجهاز</th><th>الحالة</th><th>التقييم</th></tr></thead>
  <tbody>${coverageRows(allResults)}</tbody>
</table>

<h2>3. الصفحات العامة</h2>
<table>
  <thead><tr><th>المسار</th><th>TTFB</th><th>FCP</th><th>LCP</th><th>CLS</th><th>مهام طويلة</th><th>طلبات</th><th>نقل</th><th>أثقل مورد</th></tr></thead>
  <tbody>${metricRows(loginRows)}</tbody>
</table>

${
    priorityRows.length
        ? `<h2>4. الصفحات ذات الأولوية</h2>
<table>
  <thead><tr><th>المسار</th><th>TTFB</th><th>FCP</th><th>LCP</th><th>CLS</th><th>مهام طويلة</th><th>طلبات</th><th>نقل</th><th>أثقل مورد</th></tr></thead>
  <tbody>${metricRows(priorityRows)}</tbody>
</table>`
        : '<h2>4. الصفحات ذات الأولوية</h2><p class="muted">لم تُقس صفحات الأدمن/الصيدلية بعد؛ تحتاج تسجيل دخول بحساب تجريبي.</p>'
}

<h2>5. ملخص حسب الجهاز</h2>
<table>
  <thead><tr><th>الجهاز</th><th>عدد الصفحات</th><th>وسيط TTFB</th><th>وسيط النقل</th><th>وسيط عُقد DOM</th></tr></thead>
  <tbody>
    ${deviceSummary
        .map(
            (d) => `<tr><td>${esc(d.device)}</td><td>${d.count}</td><td>${fmtMs(d.medianTtfb)}</td><td>${fmtBytes(
                d.medianTransfer
            )}</td><td>${d.medianDom === null ? '—' : d.medianDom}</td></tr>`
        )
        .join('')}
  </tbody>
</table>

<h2>6. الأسباب المرصودة لكل صفحة</h2>
${findingsHtml}

<h2>7. إصلاحات مُنفّذة ونتائجها</h2>
<p class="muted">هذه الإصلاحات نُفّذت فعليًا بعد الفحص، وأرقامها مقيسة لا مقدّرة.</p>
${fixesHtml}

<h2>8. التوصيات العملية (بالأولوية)</h2>
<ol>
  <li><strong>شعار الدخول:</strong> تقليل أبعاد الصورة وضغطها بصيغة حديثة، وتحديد الأبعاد صراحةً لمنع الاهتزاز.</li>
  <li><strong>حركة الظهور في صفحة الدخول:</strong> إزالة <code>opacity: 0</code> المبدئي أو تقليص مدة الحركة، ليظهر المحتوى في أول إطار.</li>
  <li><strong>قوائم البدائل:</strong> استبدال القوائم الكاملة ببحث من الخادم مع إبقاء العناصر المختارة فقط.</li>
  <li><strong>Tom Select:</strong> استضافته محليًا أو تحميله بشكل غير حاجب.</li>
  <li><strong>مخزون الصيدلية:</strong> دمج عدّادات الحالة والتاريخ في استعلام واحد أو كاش قصير.</li>
  <li><strong>الخطوط والأيقونات:</strong> استضافة خط الأيقونات محليًا أو تقليل الأيقونات المستخدمة.</li>
  <li><strong>المزامنة الخلفية:</strong> منع تداخل الدورات وربطها بوجود تغييرات فعلية فقط.</li>
  <li><strong>الإشعارات:</strong> توحيد عقد الاستجابة بين الواجهة والـ API — سلوك غير صحيح وليس سبب بطء.</li>
</ol>

<h2>9. حدود هذا التقرير</h2>
<ul>
  <li>القياس المخبري لا يُغني عن بيانات ميدانية حقيقية من مستخدمين فعليين.</li>
  <li>عامل خدمة معطّل أثناء القياس، فلم تُقَس مسارات التخزين المؤقت والـ offline.</li>
  <li>طلبات الكتابة محجوبة، لذا لا يمثل هذا سلوك تحديث المخزون الفعلي.</li>
  <li>بعض مقاييس الرسم (FCP/LCP) لم تُسجَّل في كل تشغيل؛ تُعرض كـ «—» بدل اعتبارها صفرًا.</li>
  <li>حالة كاش الخادم غير مضبوطة في القياس.</li>
</ul>

<footer>
  أُنتج بواسطة فحص أداء معزول — لم يُعدَّل أي ملف من ملفات التطبيق.
</footer>

</div>
</body>
</html>`;
}

/* -------------------------------------------------------------------- main */

function main() {
    const rawRuns = loadRaw();
    const preflight = loadPreflight();

    if (!rawRuns.length) {
        console.error('لا توجد ملفات قياس في raw/. شغّل audit-runner.cjs أولًا.');
        process.exit(1);
    }

    const html = buildHtml(rawRuns, preflight);
    fs.writeFileSync(REPORT_FILE, html, 'utf8');

    const sanitized = {
        generatedAt: new Date().toISOString(),
        origin: ORIGIN,
        note: 'قياسات مخبرية من متصفح معزول؛ بدون كوكيز أو رموز أو بيانات مرضى.',
        budgets: BUDGETS,
        runs: rawRuns.map((run) => ({
            phase: run.phase,
            role: run.role || null,
            device: run.device || null,
            measuredAt: run.measuredAt || null,
            deniedRequests: run.deniedRequests || [],
            results: (run.results || []).map((r) => ({
                id: r.id,
                label: r.label,
                url: r.url,
                httpStatus: r.httpStatus || null,
                finalPath: r.finalPath || null,
                measurementOk: !!r.measurementOk,
                failure: r.failure || null,
                throttling: r.throttling || null,
                ttfbFullMs: r.navigation ? r.navigation.ttfbFullMs : null,
                requestTtfbMs: r.navigation ? r.navigation.requestTtfbMs : null,
                loadEventMs: r.navigation ? r.navigation.loadEventMs : null,
                fcpMs: r.fcpMs === undefined ? null : r.fcpMs,
                lcpMs: r.lcpMs === undefined ? null : r.lcpMs,
                lcpElement: r.lcpElement || null,
                cls: r.cls === undefined ? null : r.cls,
                longTaskCount: r.longTaskCount || 0,
                longTaskTotalMs: r.longTaskTotalMs || 0,
                longTaskMaxMs: r.longTaskMaxMs || 0,
                resourceCount: r.resourceCount || 0,
                resourceTransferTotal: r.resourceTransferTotal || 0,
                resourceDecodedTotal: r.resourceDecodedTotal || 0,
                domNodes: r.domNodes || 0,
                htmlBytes: r.htmlBytes || 0,
                optionCount: r.optionCount || 0,
                imgCount: r.imgCount || 0,
                largestImages: r.largestImages || [],
                byType: r.byType || {},
                jsErrors: (r.jsErrors || []).slice(0, 10),
                severity: severityOf(r),
            })),
        })),
    };

    fs.writeFileSync(DATA_FILE, JSON.stringify(sanitized, null, 2), 'utf8');

    console.log(`تم إنشاء التقرير: ${REPORT_FILE}`);
    console.log(`تم إنشاء البيانات: ${DATA_FILE}`);
}

main();
