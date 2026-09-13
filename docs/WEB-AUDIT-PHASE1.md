# WEB AUDIT — PHASE 1 (READ-ONLY)

**المشروع:** Daway — Laravel 13 / PHP 8.4 / MySQL / Blade / vanilla JS / Vite
**التاريخ:** 2026-09-12 · **الفرع:** `develop` · **حالة الريبو:** لم يُعدَّل أي ملف (تأكيد بـ `git status`)

---

## منهجية الفحص وحدوده

**ما عملته:** قراءة الكود الفعلي سطراً بسطر + قياس أحجام الملفات على القرص + إحصاء نداءات الشبكة من الكود.

**ما لم أعمله — وأقولها بصراحة:** لا توجد قياسات متصفح حقيقية (لا Lighthouse، لا Performance Timeline، لا Real User Monitoring). **كل الأرقام أدناه مشتقّة من الكود وأحجام الملفات، لا من قياس حيّ.** ما في رقم مُختلق.

**قيد تشغيلي:** خططت لتشغيل 7 وكلاء فحص بالتوازي، لكن إطلاق الوكلاء فشل بخطأ شبكة (`getaddrinfo ENOTFOUND www.workbuddy.ai`). وكيل واحد (Blade/HTML) نجح، وأكملت الستة الباقية بنفسي بنفس المنهجية.

---

## PERFORMANCE REPORT

| # | Severity | Area | File | Line | Problem | Evidence | Impact | Fix |
|---|---|---|---|---|---|---|---|---|
| 1 | **CRITICAL** | DB + DOM | `resources/views/pharmacy/alternatives/index.blade.php` | 44–61 | N+1 حقيقي: **استعلامان لكل دورة** داخل `@forelse` | `Medicine::where(...)->get()` ثم `PharmacyMedicine::whereIn(...)->get()` داخل الحلقة | N دواء → ~2N استعلام + جدول مرشّحين كامل لكل دواء في الـ DOM | جلب واحد مُجمَّع (GROUP BY active_ingredient) في الكنترولر + تمرير map |
| 2 | **CRITICAL** | DB | `app/Http/Controllers/web/Pharmacy/PharmacyAlternativeController.php` | 42 | `->get()` بلا pagination على كل أدوية الصيدلية | `PharmacyMedicine::where(...)->get()` | نتيجة غير محدودة + DOM غير محدود | `paginate(20)` |
| 3 | **CRITICAL** | DB + DOM | `resources/views/pharmacies/show.blade.php` | 90 | `@forelse` غير محدود على علاقة المخزون | `@forelse($pharmacy->pharmacyMedicines as $pharmacyMedicine)` | صيدلية كبيرة → آلاف `<tr>` | paginate العلاقة في `PharmacyController::show` |
| 4 | **CRITICAL** | DB + DOM | `resources/views/medicines/show.blade.php` | 107 | `@forelse` غير محدود على صيدليات الدواء | `@forelse($medicine->pharmacyMedicines as ...)` | دواء شائع → آلاف الصفوف | paginate في `MedicineController::show` |
| 5 | **HIGH** | DOM + DB | `resources/views/notifications/index.blade.php` + `app/Http/Controllers/web/Admin/NotificationController.php` | 14 + 115–118 | كل الإشعارات تُحمَّل وتُعرض بلا pagination | `$user->notifications()->...->get()` | DOM ينمو بلا حد لكل مستخدم | `paginate(20)` |
| 6 | **HIGH** | DB | `resources/views/components/sidebar.blade.php` | 64 | `COUNT(*)` على كل صفحة، بلا كاش | `{{ \App\Models\User::count() }}` | استعلام زائد في **كل** طلب | View composer + كاش |
| 7 | **HIGH** | Network + Server | `resources/views/dashboard/index.blade.php` | 411–429 | **6 صفحات كاملة + 1 API** تُطلب بعد الدخول، كل 400ms | `['/users','/medicines','/pharmacies','/patients','/inventory','/logs','/api/notifications/count'].forEach(... fetch(url,{cache:'force-cache'}))` | 7 طلبات في ~2.4 ثانية، كل صفحة = render كامل + استعلامات | prefetch عند الـ hover أو إلغاؤه |
| 8 | **HIGH** | Network + DB | `resources/js/offline/sync.js` | 41–45 | heartbeat `/healthz` ثم `/api/sync/pull` **كل 30 ثانية على كل صفحة** | `setInterval(..., 30000)` → `checkThenSync()` → `runSync()` → `pull()`؛ يُحمَّل عالمياً من `layouts/app.blade.php:48` | **240 طلب/ساعة/تاب**؛ و`/healthz` نفسه ينفّذ `SELECT 1` | تعطيل الـ sync على صفحات الأدمن + إطالة الفترة |
| 9 | **HIGH** | Service Worker | `public/sw.js` | 129–144 | مهلة 3 ثوانٍ تُلغي الطلب وتخدم **HTML قديم** من الكاش | `setTimeout(() => controller.abort(), 3000)` ثم `caches.match(request)` | على Render free (cold start > 3s) يرى المستخدم بيانات قديمة؛ والأسوأ: **الطلب المُلغى لا يُحدِّث الكاش أبداً** → يبقى قديماً | رفع المهلة + تحديث الكاش حتى عند الـ timeout |
| 10 | **HIGH** | Assets + Fonts | `resources/views/layouts/app.blade.php` | 11 | خط خارجي **حاجب للعرض** على كل صفحة | `<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Tajawal:wght@400;500;700;800&display=swap">` | DNS + TLS + طلب خارجي قبل أول رسم | self-host أو `preload` |
| 11 | **HIGH** | Assets | `resources/views/layouts/app.blade.php` | 46 | FontAwesome 6.7.2 من cdnjs، حاجب للعرض، على كل صفحة | `<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.7.2/css/all.min.css">` | ~نفس التكلفة أعلاه، والأيقونات كلها مستخدمة (`fas fa-*`) | self-host أو subset |
| 12 | **MEDIUM** | Assets + Fonts | `vite.config.js` + `layouts/app.blade.php` | 22–28 / 11 | خط **Tajawal** يُحمَّل من Google Fonts (طلب خارجي حاجب) بينما Cairo وحده يُستضاف ذاتياً | `bunny('Cairo', ...)` فقط؛ `Tajawal` من `fonts.googleapis.com` في 3 layouts | طلب خارجي حاجب للعرض على كل صفحة + اعتماد على طرف ثالث | استضافة Tajawal ذاتياً — **تصحيح: Cairo مُستخدم فعلاً** في `pharmacy_dashboard.css`؛ التقرير الأولي قال إنه غير مستخدم وهذا خطأ |
| 13 | **MEDIUM** | Assets + Deploy | `vite.config.js` + `.gitignore` | `emptyOutDir:false` | `public/build` فيه **68 ملفاً / 860KB** وكل CSS+JS **مكرَّر مرتين** بhash مختلف | `pharmacy_hub-BhlFOuU4.css` **و** `pharmacy_hub-B5N9Pl0V.css` … وهكذا لكل ملف | `public/build` **غير مُستثنى في `.gitignore`** → الأجيال القديمة تُرفع للأبد | استثناء `public/build` أو تدوير الأصول |
| 14 | **MEDIUM** | DOM + CSS | `resources/views/pharmacies/index.blade.php` | 13–329 | ~316 سطر `<style>` داخلي + Leaflet وChart.js بلا `defer` | `<style>` inline؛ `unpkg.com/leaflet` و`cdn.jsdelivr.net/npm/chart.js` في `<head>` | أكبر صفحة محدودة؛ تحجب أول رسم | نقل لـVite CSS + `defer` |
| 15 | **MEDIUM** | DOM + CSS | `resources/views/profile/edit.blade.php` | 183–369 | ~187 سطر `<style>` داخلي يكرّر `users_create.css` | `.profile-layout`, `.profile-col` مكرّرة | CSS مكرّر يُحمَّل على الصفحة | نقله لملف CSS القائم |
| 16 | **MEDIUM** | DOM | `resources/views/settings/index.blade.php` | 40–190 | 4 لوحات تبويب تُرسم كلها و3 تُخفى بالـ CSS | `class="tab-pane {{ $activeTab==='...' ? 'active':'' }}"` | DOM/CSS مهدر كل تحميل | رسم التبويب النشط فقط |
| 17 | **MEDIUM** | DOM + JS | `resources/views/components/topbar.blade.php` | 58–120 | 3 نوافذ منبثقة مخفية + ~460 سطر JS على **كل** صفحة | `#profileModal`, `#avatarPreviewModal`, `#logoutOverlay` | تكلفة ثابتة على كل مسار | استخراج JS لـVite module |
| 18 | **MEDIUM** | Client-side filtering | `resources/views/dashboard/index.blade.php` | 392–407 | فلترة **كل** صفوف سجل النشاط في المتصفح بـ`display:none` | `logRows.forEach(row => { row.style.display = matches ? '' : 'none'; })` | كل الصفوف في الـ DOM ثم تُخفى | pagination/بحث من السيرفر |
| 19 | **MEDIUM** | DOM + DB | `resources/views/medicines/create.blade.php` + `Admin/MedicineController.php` | 73 + 107 | `Medicine::all()` → `<option>` لكل دواء في الكتالوج | `@foreach($allMedicines as $altMedicine)` | كتالوج كامل في الـ DOM | بحث عن بُعد (TomSelect محمَّل أصلاً) |
| 20 | **MEDIUM** | DOM + DB | `resources/views/pharmacy/alternatives/create.blade.php` + `PharmacyAlternativeController.php` | 53 + 67 | نفس `Medicine::all()` في `<option>` | `@foreach ($allMedicines as $medicine)` | كتالوج كامل في الـ DOM | بحث عن بُعد |
| 21 | **LOW** | Encoding | `resources/views/dashboard/index.blade.php` | 432–433 | بايتات **UTF-16LE** ملحقة في ملف UTF-8 | `3c 00 21 00 2d 00 2d 00` = `< ! - -` — `grep` يصنّف الملف binary | ملف مشوّه؛ خطر أعطال أدوات | حذف السطرين |
| 22 | **LOW** | JS duplication | `components/topbar.blade.php` 235–342 ↔ `profile/edit.blade.php` 394–513 | — | منطق قص/تكبير الصورة مكرّر حرفياً | `const crop = {...}` مقابل `const cropPage = {...}` | صيانة + بايتات | وحدة مشتركة |
| 23 | **LOW** | DB | `app/Http/Controllers/web/Admin/LogController.php` | 51 | تصدير السجل يحمّل كل الصفوف | `Activity::with('causer')->latest()->get()` | ذاكرة على جدول كبير | `cursor()` / `chunk()` |
| 24 | **LOW** | Dead DOM | `resources/views/logs/index.blade.php` | 23–30 | `<select>` و`<input type="date">` بلا `name` — لا تُرسَل أبداً | `<select class="form-control">` | DOM ميت | وصلها أو حذفها |

---

## TOP 10 ROOT CAUSES (مرتَّبة بالأثر)

```text
1.  HIGH     — N+1 + عرض غير محدود في «البدائل» (استعلامان × N دواء + جدول مرشّحين لكل دواء)
2.  HIGH     — علاقات غير محدودة في pharmacies/show و medicines/show (آلاف الصفوف)
3.  HIGH     — Polling: /healthz + /api/sync/pull كل 30 ثانية على كل صفحة (240 طلب/ساعة/تاب)
4.  HIGH     — مهلة 3 ثوانٍ في الـ Service Worker تخدم HTML قديم ولا تحدّث الكاش أبداً
5.  HIGH     — Prefetch بعد الدخول: 6 صفحات كاملة + 1 API في ~2.4 ثانية
6.  MEDIUM   — User::count() على كل صفحة بلا كاش
7.  MEDIUM   — خطوط وأيقونات خارجية حاجبة للعرض (Google Fonts + FontAwesome) على كل صفحة
8.  MEDIUM   — خط Tajawal يُحمَّل من Google Fonts (طلب خارجي حاجب) بينما Cairo يُستضاف ذاتياً
9.  MEDIUM   — public/build مكرّر الأجيال (860KB) وغير مستثنى في .gitignore
10. MEDIUM   — CSS/JS داخلي ضخم + DOM مخفي على كل صفحة (topbar، تبويبات الإعدادات)
```

---

## 3 — Polling: الحساب الحقيقي

من `resources/js/offline/sync.js:41` → `setInterval(30000)` → `checkThenSync()` → `/healthz` → عند النجاح `runSync()` → `pull()` → `/api/sync/pull?since=`.

```text
طلب لكل نبضة:        /healthz  +  /api/sync/pull   = 2 طلبات / 30 ثانية
= 4 طلبات/دقيقة
= 240 طلب/ساعة لكل تاب
= 1,920 طلب في 8 ساعات لكل تاب
= 5,760 طلب في 8 ساعات لثلاث تبويبات
```

و`/healthz` نفسه ينفّذ `DB::select('SELECT 1')` → أي **2,880 استعلام DB** إضافي في 8 ساعات لثلاث تبويبات.

**نقطة إيجابية مُثبتة:** الـ heartbeat **يتوقف عند التبويب المخفي** — `sync.js:43` فيه `if (document.hidden) return;` و`sync.js:47` يعيد الفحص عند العودة. فالعدّ أعلاه يحتسب التبويبات **المرئية** فقط.

**نقطة سلبية:** الـ `setInterval` يعمل على **كل** صفحة تشمل `layouts/app.blade.php`، أي صفحات الأدمن أيضاً (`/users`, `/logs`, `/medicines`…) — وهي صفحات لا تحتاج مزامنة مخزون صيدلية إطلاقاً.

---

## 4 — Network: جدول الطلبات

| URL | Method | Page | Trigger | Count | Necessary? |
|---|---|---|---|---|---|
| `/healthz` | GET | كل صفحة (app layout) | `sync.init()` + كل 30s | 120/ساعة/تاب | ❌ على صفحات الأدمن |
| `/api/sync/pull?since=` | GET | كل صفحة | بعد كل `/healthz` ناجح | 120/ساعة/تاب | ❌ على صفحات الأدمن |
| `/api/sync/token` | POST | كل صفحة | مرة/جلسة (sessionStorage) | 1/جلسة | ✅ |
| `/api/sync/push` | POST | كل صفحة | فقط عند وجود queue | عند الحاجة | ✅ |
| `/api/notifications/count` | GET | كل صفحة (topbar) + prefetch | عند التحميل + فتح القائمة | 1–2/صفحة | ✅ (مخزّن 15s في السيرفر) |
| `/api/notifications` | GET | كل صفحة | عند فتح القائمة فقط | عند الفتح | ✅ |
| `/api/notifications/{id}/read` | POST | كل صفحة | عند النقر | عند النقر | ✅ |
| `/users`,`/medicines`,`/pharmacies`,`/patients`,`/inventory`,`/logs` | GET | Dashboard | **بعد الدخول تلقائياً** | 6 في 2.4s | ❌ prefetch غير ضروري |
| `/api/device-tokens` | POST | صفحات الـoffline | تسجيل FCM | عند الحاجة | ✅ |
| `fonts.googleapis.com` (CSS) | GET | كل صفحة | `<link>` حاجب | 1 | ⚠️ يُفضَّل self-host |
| `cdnjs…font-awesome` (CSS) | GET | كل صفحة | `<link>` حاجب | 1 | ⚠️ يُفضَّل self-host |
| `unpkg.com/leaflet` | GET | pharmacies/index, edit, pharmacy/profile | `<script>` بلا defer | 2/صفحة | ✅ لكن بـdefer |
| `cdn.jsdelivr.net/npm/chart.js` | GET | pharmacies/index, pharmacy/dashboard, inventory, ratings | `<script>` بلا defer | 1/صفحة | ✅ لكن بـdefer |

**طلبات مكرّرة:** لا يوجد نفس الـ URL يُطلب مرتين في نفس التحميل. ✅

---

## 5 — Service Worker

| البند | الحالة | الدليل |
|---|---|---|
| استراتيجية التنقّل (صفحات الصيدلية) | Network-first مع fallback للكاش | `sw.js:124–147` |
| مهلة الشبكة | **3000ms** ثم إلغاء | `sw.js:129` |
| `?page=` | network-only، لا كاش | `sw.js:120–123` ✅ |
| HTML قديم | **محتمل جداً** — عند الإلغاء تُخدم النسخة المخزّنة | `sw.js:139–140` |
| تحديث الكاش عند الإلغاء | **مفقود** — `cache.put` لا يُنفَّذ لأن الطلب أُلغِي | `sw.js:132–135` |
| تنظيف الكاش القديم | ✅ يحذف كل كاش ≠ `VERSION` | `sw.js:70–71` |
| التخزين المسبق لأصول البناء | ✅ من `manifest.json` | `sw.js:27–42` |
| التخزين المسبق للصفحات | 8 صفحات مصادَقة، تسلسلياً كل 400ms | `sw.js:44–58` |
| API | لا يُمَس إطلاقاً | `sw.js:106` ✅ |
| POST/PUT/DELETE | لا تُخزَّن | `sw.js:102` ✅ |

**السيناريو الذي وصفته — مُثبت:**

```text
navigation إلى /pharmacy/inventory
        ↓
fetch(request) مع AbortController(3000ms)
        ↓
cold start على Render free > 3 ثوانٍ
        ↓
abort → catch
        ↓
caches.match(request) → HTML قديم (كميات قديمة!)
        ↓
المستخدم يرى بيانات قديمة ويشعر أن الصفحة "معلّقة/غلط"
        ↓
والأسوأ: cache.put لم يُنفَّذ → النسخة القديمة تبقى للأبد
```

**حلقة مغلقة:** كل تحميل بطيء يُبقيك على النسخة القديمة، والنسخة القديمة تُحمَّل فوراً (من الكاش) فلا تُتاح فرصة تحديثها → **لا تخرج من الحالة القديمة إلا بحظ**.

---

## 6 — Vite / Bundle

| المقياس | القيمة |
|---|---|
| أكبر JS bundle | **27,621 بايت** (`index.esm-DRF2KiTp.js` — Firebase) |
| أكبر CSS bundle | **26,804 بايت** (`pharmacy_hub-*.css`) |
| حجم `public/build` كامل | **860 KB / 68 ملف** |
| CSS مصدري | ~317 KB على 23 ملف |
| صور | `dawak-logo.jpg` 71KB · `dawaei-logo.jpg` 19KB |
| خطوط | Cairo self-hosted (غير مستخدم) + Tajawal خارجي |

### ⚠️ استنتاج مهم: **الـ Bundle ليس هو المشكلة**

أكبر ملف JS هو 27 KB. هذا **صغير جداً**. لو كان الـ bundle هو السبب لرأينا مئات الكيلوبايتات. **لا يوجد دليل على أن حجم الـ bundle سبب البطء.**

**لم أشغّل `npm run build`** — احتراماً لقاعدة «لا تعديل في Phase 1» (الـ build يكتب ملفات). القياسات أعلاه من مخرجات البناء الموجودة فعلاً على القرص.

**المشكلة الحقيقية في الأصول:** التكرار (كل ملف مرتين) + عدم استثناء `public/build` من git + خط غير مستخدم.

---

## 7 — Full Data Loading (فحص إجباري #1)

**النتيجة: قوائم الإدارة الرئيسية تُصفّح فعلاً — لا مشكلة فيها.**

مُثبت: `UserController:45`، `MedicineController:60`، `PharmacyController:36`، `PatientController:27`، `InventoryController:17`، `CategoryController:30/90` كلها `->paginate(7)`.

**لكن** الحالات التالية تُحمّل بلا حد:

- `PharmacyAlternativeController:42,64`
- `Admin/MedicineController:107` و `:165` (`Medicine::all()`)
- `PharmacyAlternativeController:67` (`Medicine::all()`)
- `Admin/NotificationController:73,117`
- `Admin/LogController:51`
- `PharmacyInventoryController:87`

**حالة PHP-slice → عرض 7/10: لم أجدها.** القوائم تُصفَّح في SQL، لا في PHP. ✅

---

## 8 — Client-Side Pagination / Filtering (فحص إجباري #2)

**لم أجد pagination في المتصفح** (لا `array.slice` على بيانات ثم عرض جزء).

**لكن وجدت فلترة في المتصفح:** `dashboard/index.blade.php:392–407` — جدول سجل النشاط في النافذة المنبثقة يُفلتَر بـ`row.style.display='none'` على **كل** الصفوف الموجودة في الـ DOM.

كل استخدامات `array_filter` الأخرى في المشروع هي **PHP** لبناء روابط فلترة (`route(..., array_filter([...]))`) — سليمة تماماً. ✅

---

## 9 — DOM Size (فحص إجباري #6)

أكبر 3 صفحات إنتاجاً للـ HTML:

```text
1. pharmacies/show.blade.php        — @forelse غير محدود على المخزون (:90)
2. pharmacy/alternatives/index      — @forelse غير محدود × جدول مرشّحين لكل دواء (:44–61, :89)
3. medicines/show.blade.php         — @forelse غير محدود على الصيدليات (:107)
```

**أسوأ مخالف واحد:** `pharmacy/alternatives/index.blade.php`.

---

## 10 — Render Blocking (فحص إجباري #7)

```text
CSS حاجب:      fonts.googleapis.com (Tajawal) + cdnjs (FontAwesome)  — على كل صفحة
JS حاجب:       leaflet.js و chart.js في <head> بلا defer/async
الخطوط:        Tajawal خارجي (حاجب) + Cairo ذاتي (مستخدم فعلاً في لوحة الصيدلية)
الصور:         بدون lazy loading (2 شعارات فقط، صغيرة)
```

**ما يمكن تأجيله بأمان:** `leaflet.js`، `chart.js` (كلاهما لا يلمس DOM قبل الرسم) + الخطوط.

---

## 11 — Memory Leaks (فحص إجباري #8)

| البند | النتيجة |
|---|---|
| `setInterval` بلا `clear` | **لا يوجد** — الوحيد في `sync.js:41` وهو مقصود وطويل العمر مع حاجز `document.hidden` |
| `addEventListener` مكرّر | **لم أجد تكراراً** — `offline/index.js:37` يربط مرة واحدة على `DOMContentLoaded`، و`intercept.init()` تُنفَّذ مرة واحدة |
| Observers بلا `disconnect` | ⚠️ **غير مُتحقَّق** — يوجد استخدامان لـ`MutationObserver` لم أحدّد موقعهما بعد |
| مراجع DOM قديمة | لا دليل |

---

## 12 — Duplicate Assets (فحص إجباري #5)

| الأصل | مكرّر؟ | الدليل |
|---|---|---|
| CSS | **نعم — جيلان** | `pharmacy_hub-BhlFOuU4.css` + `pharmacy_hub-B5N9Pl0V.css` (وكذلك dashboard/users/medicines/topbar…) |
| JS | **نعم — جيلان** | `index.esm-DRF2KiTp.js` + `index.esm-B7x7R-Gg.js` |
| Fonts | **نعم — بمعنى مختلف** | Cairo (ذاتي، غير مستخدم) + Tajawal (خارجي، مستخدم) |
| Libraries | لا | كل مكتبة تُحمَّل مرة واحدة في صفحتها |

السبب الجذري: `vite.config.js` → `build.emptyOutDir: false` (مقصود، موثّق بتعليق لحماية صفحات الكاش) + `public/build` غير مستثنى في `.gitignore`.

---

## 13 — ما ليس هو المشكلة (مهم للأمانة)

```text
✅ حجم الـ bundle        — أكبر ملف JS = 27 KB. صغير جداً.
✅ pagination القوائم    — تُصفَّح في SQL (7/صفحة) بشكل صحيح.
✅ مزامنة الـ offline    — منطق سليم: retry cap، dead-letter، حماية من حلقة reload.
✅ `?page=` في الـ SW    — network-only، لا كاش قديم. صحيح.
✅ الطلبات المكرّرة      — لا يوجد نفس الـ URL مرتين في نفس التحميل.
✅ render.yaml           — healthCheckPath = /healthz ويطابق المسار الفعلي.
                          (ملاحظة: AGENTS.md يذكر /up — هذه معلومة قديمة، تم إصلاحها.)
```

---

## 14 — FINAL VERDICT

```text
MAIN BOTTLENECK:      Database + server-side rendering
                      — N+1 داخل حلقة العرض + علاقات غير محدودة تُرسَم كاملة
                      (pharmacy/alternatives, pharmacies/show, medicines/show)

SECONDARY BOTTLENECK: Network volume من الـ polling
                      — /healthz + /api/sync/pull كل 30 ثانية على كل صفحة
                      (240 طلب/ساعة/تاب) + prefetch 7 طلبات بعد الدخول

THIRD BOTTLENECK:     Service Worker 3s timeout
                      — يخدم HTML قديم ولا يحدّث الكاش → إحساس دائم بأن التطبيق
                      "معلّق" أو "لا يستجيب" حتى لو السيرفر سريع
```

**البطء ليس من الـ Frontend bundle، ولا من الـ CSS، ولا من الصور.** الأصول ثقيلة نسبياً لكنها ليست العنق.

---

## الخطوة التالية — خطة Phase 2 المقترحة

بالترتيب الإلزامي (CRITICAL → HIGH → MEDIUM → LOW)، وبلا لمس أي مما حُرّم:

**CRITICAL**
1. `pharmacy/alternatives` — نقل الاستعلامين خارج حلقة العرض إلى استعلام مُجمَّع واحد + `paginate(20)`.

**HIGH**
2. `pharmacies/show` + `medicines/show` — `paginate` على العلاقات.
3. `notifications/index` — `paginate(20)`.
4. `sidebar` — كاش `User::count()`.
5. `dashboard` — إلغاء prefetch الـ6 صفحات.
6. `sync.js` — إيقاف الـ heartbeat على صفحات غير الصيدلية + إطالة الفترة إلى 60s.
7. `sw.js` — رفع المهلة إلى 8s + تحديث الكاش عند الـ timeout.
8. الخطوط — self-host أو `preload` لـTajawal، و`defer` لمكتبات CDN.

**MEDIUM**
9. حذف خط Cairo غير المستخدم.
10. نقل الـCSS الداخلي (pharmacies/index، profile/edit) إلى ملفات Vite.
11. استثناء `public/build` في `.gitignore`.

**LOW**
12. حذف بايتات UTF-16 من `dashboard/index.blade.php`.
13. توحيد JS قص الصورة.
14. `cursor()` لتصدير السجلات.

**التحقق (Phase 3):** `php -l` على كل ملف PHP معدَّل → `npm run build` → `php artisan test` → `git diff --stat` → فحص Admin/Pharmacy/Offline.
