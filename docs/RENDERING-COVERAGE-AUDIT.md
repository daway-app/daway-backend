# تدقيق تغطية الـRendering — المحاسبة والماسح (READ-ONLY)

**التاريخ:** 2026-09-16 · **النوع:** فحص قراءة فقط · **الفرع:** `develop` (working tree)
**النطاق:** كل قالب/مكوّن/وحدة JS في واجهة المحاسبة + الماسح

> **لم يُعدَّل أي ملف إنتاج. لا commit. لا push. لا إصلاح Git.**
> الملف الوحيد المكتوب هو هذا التقرير (توثيق، ليس كودًا).

---

## 0 · القرار النهائي

```
Coverage: INCOMPLETE
```

**لا يوجد أي Bug rendering حقيقي.** كل مكوّن مُضمَّن في الصفحة **يُرسم فعليًا وسليم** —
أُثبت ذلك تنفيذيًا بـ`Blade::render()` لكل مكوّن (تفصيل في §5).
الفجوات كلها **فجوات تغطية اختبارية**، لا أعطال.

---

## 1 · Rendering Coverage

```
Total UI artifacts:            25   (16 Blade + 8 JS + 1 CSS)
Rendered & asserted in tests:  13   (كل الصفحات الأربع + 8 مكوّنات + 1 partial)
Live but NOT in any test:       2   (barcode-link-modal · barcode-conflict-modal)
Dead / never rendered:          1   (barcode-activity-card)
JS modules behaviourally tested: 1 full + 2 partial  من 8
CSS coverage:                  partial (حالتان من 8)
Missing coverage items:        15
Real rendering bugs:            0
```

---

## 2 · جدول التغطية الكامل

### 2.1 صفحات Blade

| Component / Template | Test file | Rendered? | Covered? | Interactive state covered? |
|---|---|---|---|---|
| `accounting/overview.blade.php` | `AccountingPagesTest` | ✅ | ✅ 6 KPI + charts + 4 ranges + JSON config | ✅ (رanges, alerts) |
| `accounting/sales.blade.php` | `AccountingPagesTest` | ✅ | ✅ headers, filters, search, pagination, empty-state, عزل الصيدليات | ✅ (filter round-trip) |
| `accounting/sale-create.blade.php` (POS) | `AccountingPagesTest` + `AccountingPosWiringRenderTest` + `PhoneScannerTest` + `AccountingEndToEndTest` | ✅ | ✅ | ⚠️ markup فقط (لا سلوك JS) |
| `accounting/invoice.blade.php` | `AccountingPagesTest` | ✅ | ✅ بنود حقيقية + 404 + print-ready | ✅ (print) |

### 2.2 مكوّنات Blade

| Component / Template | Test file | Rendered? | Covered? | Interactive state covered? |
|---|---|---|---|---|
| `barcode-input` | `AccountingPagesTest` · `PhoneScannerTest` | ✅ | ✅ `data-barcode-input`, `inputmode=numeric`, hint | ⚠️ لا (حالات الحقل الستّ) |
| `barcode-scan-button` | `AccountingPagesTest` · `PhoneScannerTest` | ✅ | ✅ `data-barcode-scan` | ✅ |
| `barcode-status-badge` | `PhoneScannerTest` | ✅ | ⚠️ `is-unknown` فقط | ❌ `pending` · `verified` · `conflict` |
| `phone-scanner-button` | `AccountingPagesTest` · `PhoneScannerTest` | ✅ | ✅ `data-phone-scanner-open` + نصّ الزر | ✅ |
| `phone-scanner-modal` | `PhoneScannerTest` | ✅ | ✅ 9 حالات · `aria-live` · 29 خطّافًا | ❌ فتح/إغلاق/focus |
| `connected-device-card` | `PhoneScannerTest` | ✅ | ✅ 7 خطّافات + تفويض الحدث | ✅ |
| `pairing-qr-card` | `PhoneScannerTest` | ✅ | ✅ `data-pair-code` · `data-pair-qr` · `-placeholder` | ✅ |
| `scan-session-status` | `PhoneScannerTest` | ✅ | ✅ 9 حالات · `aria-live="polite"` | ✅ |
| `barcode-link-modal` (باركود مجهول) | **— لا شيء —** | ❌ | ❌ | ❌ كامل |
| `barcode-conflict-modal` (تعارض) | **— لا شيء —** | ❌ | ❌ | ❌ كامل |
| `barcode-activity-card` | **— لا شيء —** | ❌ **ميت** | ❌ | ❌ |

### 2.3 partials

| Component / Template | Test file | Rendered? | Covered? | Interactive state covered? |
|---|---|---|---|---|
| `partials/accounting-i18n` | كل اختبارات الصفحات (غير مباشر) | ✅ | ✅ endpoints الـ19 · مفاتيح i18n · `scanSessions:null` | ✅ |

### 2.4 وحدات JS

| Module | Test file | Rendered? | Covered? | Interactive state covered? |
|---|---|---|---|---|
| `accounting-scanner-session.js` | `tests/js/phone-scanner-session.test.mjs` · `scripts/audit-scanner-state-machine.cjs` · الحاملة | ✅ | ✅ | ✅ **74 + 28 فحصًا** |
| `accounting-phone-scanner.js` | الحاملة (وجود + 4 خطّافات) | ✅ | ⚠️ جزئي | ❌ فتح/إغلاق النافذة |
| `accounting-barcode.js` | الحاملة (وجود) · `PhoneScannerTest` (ثابت) | ✅ | ⚠️ جزئي (CSS + regex) | ❌ `resolveBarcode` وقت التشغيل |
| `accounting-pos.js` | **— لا شيء —** | ✅ | ❌ | ❌ كامل (سلة · مجاميع · حفظ) |
| `accounting-sales.js` | **— لا شيء —** | ✅ | ❌ | ❌ كامل |
| `accounting-overview.js` | **— لا شيء —** | ✅ | ❌ | ❌ كامل |
| `accounting-invoice.js` | **— لا شيء —** | ✅ | ❌ | ❌ كامل |
| `accounting-shared.js` | **— لا شيء —** | ✅ | ❌ | ❌ عقد `AccountingApi` |

### 2.5 CSS

| Artifact | Test file | Rendered? | Covered? | Missing |
|---|---|---|---|---|
| `css/pages/pharmacy_accounting.css` | `PhoneScannerTest` | ✅ | ⚠️ `.ac-bcode-badge.is-unknown` + `@media print` | dark-mode · 9 breakpoints · 5 حالات |

---

## 3 · فحص الـModals (المطلوب في §4)

| المطلوب | `phone-scanner-modal` | `barcode-link-modal` | `barcode-conflict-modal` |
|---|---|---|---|
| فتحه | ❌ لا اختبار | ❌ | ❌ |
| ظهوره في DOM | ✅ `data-phone-scanner-modal` | ❌ | ❌ |
| العنوان | ⚠️ غير مؤكَّد | ❌ | ❌ |
| الأزرار الأساسية | ⚠️ جزئي (allow/reject/disconnect) | ❌ (`data-link-search`, `-save`, `-clear`) | ❌ (`data-conflict-review`, `-cancel`) |
| إغلاقه | ❌ | ❌ | ❌ |
| keyboard/focus | ❌ | ❌ | ❌ |

**إثبات الـrendering الفعلي** (تنفيذ حقيقي، لا قراءة كود):

```
barcode-link-modal       len=4522   hooks=12
  data-barcode-link-modal, data-endpoint, data-link-sub, data-modal-close,
  data-barcode-link-cancel, data-link-barcode, data-link-search, data-link-results,
  data-link-chosen, data-link-chosen-name, data-link-clear, data-barcode-link-save

barcode-conflict-modal   len=2632   hooks=6
  data-barcode-conflict-modal, data-modal-close, data-conflict-cancel,
  data-conflict-barcode, data-conflict-existing, data-conflict-review

phone-scanner-modal      len=12820  hooks=29

barcode-activity-card    len=1282   hooks=0     ← لا خطّافات، ولا يُضمَّن في أي صفحة
```

⇒ الـmodal‑ان **يُرسمان سليمين**. العيب تغطية فقط، لا عطل.

---

## 4 · فحص مسارات POS (المطلوب في §5)

| المسار | الحالة | الدليل |
|---|---|---|
| Search | ✅ | `data-ac-search` + `accounting.pos.search_label` |
| Keyboard barcode | ✅ | `data-barcode-input` + `inputmode="numeric"` + hint |
| Phone barcode | ✅ | `data-phone-scanner-open` + نصّ الزر |
| Known medicine | ⚠️ markup فقط | `data-ac-found-add` · `-badge` · `-name` · `-meta` موجودة، **غير مؤكَّدة** |
| Unknown barcode | ❌ | المكوّن يُرسم، لا اختبار |
| Conflict | ❌ | المكوّن يُرسم، لا اختبار |
| Add to cart | ❌ | `data-ac-cart-body` مؤكَّد كوجود، **السلوك لا** |
| Remove | ❌ | لا اختبار |
| Totals | ⚠️ markup | `data-ac-subtotal` · `-total` · `-paid` · `-remaining` مؤكَّدة كوجود، **الحساب لا** |
| Complete Sale | ⚠️ markup | `data-ac-submit` مؤكَّد كوجود، **الإرسال لا** |

**خطّافات POS الموجودة فعلًا: 29** — المؤكَّد منها في الاختبارات: **5 فقط**
(`data-ac-search`, `data-ac-cart-body`, `data-ac-subtotal`, `data-ac-total`, `data-ac-paid`, `data-ac-submit`).
غير مؤكَّد: `data-ac-found-add` · `-badge` · `-name` · `-meta` · `data-ac-scan-found` · `data-ac-scan-unknown` ·
`data-ac-cart-count` · `-empty` · `-table` · `-toolbar` · `data-ac-clear` · `data-ac-customer-matches` ·
`data-ac-discount` · `data-ac-focus-search` · `data-ac-msg` · `data-ac-payment` · `data-ac-remaining` ·
`-line` · `data-ac-results` · `data-ac-search-msg` · `data-ac-submit-label`.

---

## 5 · Responsive / Dark Mode (المطلوب في §6)

| المكوّن الحرج | styling مختلف؟ | تغطية قائمة؟ |
|---|---|---|
| POS (`sale-create`) | ✅ 3 breakpoints | ❌ لا شيء |
| Phone scanner modal | ✅ 2 breakpoints | ❌ لا شيء |
| جداول (`sales` · `invoice`) | ✅ 2 breakpoints | ❌ لا شيء |
| Sidebar | ✅ (خارج ملف المحاسبة) | ⚠️ اختبارات وجود فقط |
| KPI cards | ✅ 2 breakpoints | ❌ لا شيء |
| **Dark mode (كامل الصفحة)** | ✅ | ❌ **لا شيء** |

**نتيجتان ملموستان:**
1. **`pharmacy_accounting.css` فيه سطر `dark-mode` واحد فقط** — أي أن صفحة المحاسبة **ليست مُنمَّقة للوضع الداكن** أصلًا (قرار تصميمي أم سهو؟ يحتاج قرارك).
2. **صفر اختبار** في `tests/` كله يشير إلى `dark-mode` / `prefers-color-scheme` / `@media`.
   وأدوات التحقق القائمة (`outputs/dark-verify-harness.html` · `wcag-verification.py` ·
   `a11y-audit.py` · `interactive-audit.py`) **لا تذكر صفحة المحاسبة إطلاقًا** (بحث بـgrep ⇒ صفر).

---

## 6 · قائمة الفجوات (15) — لا تُصلَح الآن

| # | الفجوة | الملفات المطلوبة | الاختبار المطلوب |
|---|---|---|---|
| 1 | `barcode-link-modal` بلا أي اختبار | — | `assertSee('data-barcode-link-modal')` + العنوان + `data-link-search`/`-results`/`-save`/`-cancel` |
| 2 | `barcode-conflict-modal` بلا أي اختبار | — | `assertSee('data-barcode-conflict-modal')` + العنوان + `data-conflict-review`/`-cancel` |
| 3 | حالات الشارة: `pending` · `verified` · `conflict` | — | اختبار لكل `BarcodeStatus::cssClass()` + التسمية |
| 4 | حالات الحقل: `is-pending` · `is-verified` · `is-conflict` · `is-loading` · `is-invalid` | — | تأكيد وجود الأصناف في CSS + منطق JS |
| 5 | فتح/إغلاق الـmodal + focus/keyboard | `accounting-phone-scanner.js` | اختبار JS: `openScanner()` ⇒ ظاهر · `closeScanner()` ⇒ مخفي · Esc |
| 6 | `accounting-pos.js` بلا اختبار سلوكي | `accounting-pos.js` | Node vm: إضافة للسلة · حذف · المجاميع · الحفظ |
| 7 | `accounting-sales.js` بلا اختبار | `accounting-sales.js` | Node vm: الفلاتر · الترقيم · الإلغاء |
| 8 | `accounting-overview.js` بلا اختبار | `accounting-overview.js` | Node vm: تغيير المدى · الرسم |
| 9 | `accounting-invoice.js` بلا اختبار | `accounting-invoice.js` | Node vm: الطباعة · البنود |
| 10 | `accounting-shared.js` بلا اختبار | `accounting-shared.js` | Node vm: عقد `{ok,data\|reason,message}` |
| 11 | 21 خطّاف `data-ac-*` غير مؤكَّد | `sale-create.blade.php` | تأكيد الوجود في اختبار الرسم |
| 12 | **Dark mode لصفحة المحاسبة** | `pharmacy_accounting.css` | سطر `dark-mode` واحد فقط — يحتاج قرارًا ثم تغطية |
| 13 | 9 نقاط responsive بلا تغطية | `pharmacy_accounting.css` | حاملة viewport + قياس إزاحة |
| 14 | `barcode-activity-card` **مكوّن ميت** | `barcode-activity-card.blade.php` | إمّا يُضمَّن في `overview` أو يُحذف |
| 15 | اسم اختبار قديم: `..._with_all_eight_states` | `PhoneScannerTest.php` | يفحص 8 حالات فقط بينما العقد 9 (مُغطّى في اختبار آخر) — تحديث الاسم/القائمة |

---

## 7 · Bugs حقيقية

**لا شيء.** لم أكتشف أي Bug rendering مُثبَت.

- كل المكوّنات المُضمَّنة تُرسم بلا خطأ (أُثبت بـ`Blade::render()`).
- العطلان المُصلَحان سابقًا (`D2` أزرار الأجهزة · `D3` خانة QR) مُغطّيان بحراس انحدار
  ويعملان.
- **`barcode-activity-card` ميت لكنه لا يُسبب عطلًا** — مجرد كود لا يُستخدم.

⇒ **لم أعدّل أي ملف إنتاج، ولم أوقف العمل لطلب موافقة.**

---

## 8 · Git status (بلا أي أمر إصلاحي)

```
$ git status --short
A  .dockerignore
A  .editorconfig
A  .env.example
…
مُجهَّز: 1016  |  غير مُتتبَّع: 27

$ git log --oneline -1
fatal: your current branch 'develop' does not have any commits yet

$ git log --all --oneline -1
fatal: bad object refs/heads/feature/ai-ocr-integration

$ git fsck --no-progress
error: refs/heads/feature/ai-ocr-integration: invalid sha1 pointer 4586d586…
error: refs/heads/feature/arabic-i18n:         invalid sha1 pointer ecc66c5b…
error: refs/heads/feature/audit-fixes:         invalid sha1 pointer 035d34e9…
error: refs/heads/main:                        invalid sha1 pointer f3b22704…
error: refs/heads/new-version:                 invalid sha1 pointer f7d73b30…
error: refs/heads/develop: invalid reflog entry ce1a5be9…
14 broken link from tree bc122008…
13 invalid sha1 pointer
```

### ⇒ تصنيف: **repository infrastructure issue — منفصل تمامًا عن الـUI**

- **كل المراجع المحلية الخمسة معطوبة** (`invalid sha1 pointer`) — الأجسام مفقودة.
- `refs/heads/develop` **له reflog** لكن أجسامه مفقودة ⇒ الفرع كان له تاريخ ثم فُقد.
- **13 مرجعًا معطوبًا** + **14 رابطًا مكسورًا** من شجرة `bc122008`.
- `git fetch` يفشل: `pack has 2 unresolved deltas`.

**هذا لا علاقة له بأي عطل واجهة.** الأعراض (كل الملفات `A`) سببها أن الـindex مرتبط بفرع
`develop` غير موجود، لا أن الكود جديد.

**لم أنفّذ أي أمر إصلاحي** — لا `fetch` ناجح، لا `fsck --fix`، لا `reflog expire`، لا إعادة استنساخ.
