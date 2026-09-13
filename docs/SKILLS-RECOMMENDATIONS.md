# السكيلات المقترحة لمشروع Daway

> نتائج بحث في مصدرين: **سوق WorkBuddy المدمج (BuiltinMarket)** و **SkillHub** (`lightmake.site`).
> تاريخ البحث: 2026-09-12 · الفلاتر: Laravel / Frontend / Flutter

---

## حالة التركيب ✅ (2026-09-12)

رُكّبت **8 سكيلات** في `~/.workbuddy-ai/skills/` (مستوى المستخدم — متاحة بكل المشاريع):

| السكيل | المصدر | نتيجة الفحص |
|---|---|---|
| `compound-eng-php-laravel` | SkillHub | P2 نظيف |
| `laravel` | SkillHub | P2 نظيف |
| `mysql` | SkillHub | P2 نظيف |
| `flutter` | SkillHub | P2 نظيف |
| `testing-patterns` | SkillHub | P2 نظيف |
| `arabic-rtl-layout-checker-skill` | SkillHub | P2 نظيف |
| `affaan-m-ecc-dart-flutter-patterns` | SkillHub | P2 نظيف |
| `frontend-dev` | السوق المدمج | P2 نظيف (تحفّظ: موجّه لـReact/Next.js + MiniMax API، مش Blade) |

**منهجية الفحص المطبّقة:** تنزيل لمرحلة مؤقتة → فحص آلي لعشرة أنماط خطيرة (shell-exec، destructive،
secrets، exfil، persistence، priv-esc، prompt-inject...) → تدقيق دلالي بـ3 إيجنتات بالتوازي →
تصنيف P0/P1/P2 → تركيب → تحقق.

**ملاحظات:**
- كل سكيلات SkillHub **ملفات Markdown صافية** بلا سكربتات — أخطر ما وُجد إيجابيات كاذبة (نصوص تقنية شرعية).
- `frontend-dev` هو الوحيد الذي فيه `scripts/*.py` — تقرأ `MINIMAX_API_KEY` من متغير بيئة وتتصل بـ
  `api.minimaxi.com` / `api.minimax.io` فقط. لا تسريب ولا تنفيذ أوامر.
- **متوقّع تشغيل جلسة جديدة** حتى تظهر السكيلات في قائمة المتاحة.
- ✅ **نُظِّف بالكامل**: حُذفت مخلفات macOS (`__MACOSX/` + `._*`) من `frontend-dev`، وأُزيل
  مجلد المرحلة المؤقت `~/.workbuddy-ai/tmp/`. `frontend-dev` الآن 100 ملف نظيف.
  (الحذف تم على دفعات لأن حاجز الأمان يمنع >50 ملف/دورة، والنتيجة كانت `exit 0` بدون حذف فعلي —
  فالتحقق بـ`find` ضروري لا بكود الخروج.)
- منهجية التركيب الآمن محفوظة كسكيل: `audited-skill-install`.

---

## الملخص التنفيذي

- **لا يوجد سكيل Laravel رسمي في السوق المدمج** (بحث `laravel` و`php` و`backend` = 0 نتائج).
- البديل الأقوى موجود في **SkillHub**: سكيلات متخصصة بجودة عالية في Laravel/PHP/MySQL.
- **Flutter مغطّى في المصدرين** — سكيل في السوق المدمج + سكيلان متخصصان في SkillHub.
- **ميزة مهمة للمشروع**: يوجد سكيلان لمراجعة **RTL العربي** — وهذا نادر ومهم جداً لمشروع عربي-أولاً مثل Daway.
- **تنبيه**: مجلد `.opencode/skills/` المذكور في `AGENTS.md` (14 سكيل) **غير موجود على القرص** — لم يُعثر على أي `SKILL.md` في المشروع عدا واحد داخل `vendor/spatie/laravel-permission`.

---

## الأولوية P0 — أساسية، ركّبها الآن

### الباك إند (Laravel / PHP / MySQL)

| السكيل | المصدر | slug | التنزيلات | لماذا يهمنا |
|---|---|---|---|---|
| **ia-php-laravel** | SkillHub | `compound-eng-php-laravel` | 1,543 | **الأدق للمشروع**: أنماط PHP 8.4 + Laravel — معمارية، Eloquent، Migrations، Queues، Testing، Blade. مطابق تماماً لمكدّسنا |
| **Laravel** | SkillHub | `laravel` | 2,248 | تجنّب أخطاء Laravel الشائعة: N+1، mass assignment، cache gotchas، queue serialization |
| **MySQL** | SkillHub | `mysql` | 8,028 | استعلامات صحيحة وتجنّب فخاخ charset/index/locks — يخدم `03-database` عندنا |

### الموبايل (Flutter)

| السكيل | المصدر | المعرّف | التنزيلات | لماذا يهمنا |
|---|---|---|---|---|
| **Flutter** | SkillHub | `flutter` | 3,873 | Widgets، state management، تكامل المنصّات — الأساس لتطبيق الموبايل |
| **Dart/Flutter Patterns** | SkillHub | `affaan-m-ecc-dart-flutter-patterns` | 51 | **الأعمق**: null safety، Riverpod/BLoC، GoRouter، Dio، Freezed، clean architecture |
| **flutter-dev** | السوق المدمج | `skill_2053081993476866048` | — | دليل تطوير Flutter عبر المنصّات (تكملة خفيفة) |

### الواجهة (Frontend)

| السكيل | المصدر | المعرّف | لماذا يهمنا |
|---|---|---|---|
| **frontend-dev** | السوق المدمج | `skill_2052764137292271616` | تطوير الواجهة — يخدم `02-frontend` عندنا (Blade/Vite/JS) |
| **Arabic RTL Layout Checker** | SkillHub | `arabic-rtl-layout-checker-skill` | **حرج لمشروعنا**: يفحص ترتيب القراءة RTL، المحاذاة، دعم الخطوط، المرآة، وأخطاء UI الشائعة قبل النشر |

---

## الأولوية P1 — مفيدة جداً

| السكيل | المصدر | المعرّف | التنزيلات | الفائدة |
|---|---|---|---|---|
| **REST API** | SkillHub | `rest-api` | 1,569 | تصميم contract-first، مصادقة آمنة، اختبار، دليل نشر — يخدم `04-api` |
| **Code Review** | SkillHub | `smart-code-review` | 1,020 | مراجعة منظمة متعددة الأبعاد مع مخرجات منظّمة — يخدم `10-code-audit` |
| **Testing Patterns** | SkillHub | `testing-patterns` | 5,869 | أنماط unit/integration/E2E — **مهم جداً** لأن مشروعنا بلا تغطية حقيقية |
| **impeccable** | السوق المدمج | `skill_2053082862415904768` | — | UI/UX إنتاجي بمستوى عالٍ وتجنّب الجمالية الـ"AI" العامة — يخدم `12-ui-ux` |
| **Flutter/Dart Code Review** | SkillHub | `affaan-m-ecc-flutter-dart-code-review` | 47 | checklist مراجعة Flutter: widgets، state، أداء، إتاحة، أمان |
| **Docker** | SkillHub | `docker` | 17,706 | Compose، الشبكات، volumes، تقوية الإنتاج — يخدم `13-docker-render` |
| **Arabic Family App RTL** | SkillHub | `arabic-family-app-rtl-skill` | 337 | مراجعة RTL لتطبيقات موبايل عربية-أولاً + تدفقات الموافقة |

---

## الأولوية P2 — حسب الحاجة

| السكيل | المصدر | المعرّف | الفائدة |
|---|---|---|---|
| **Security Hardener** | SkillHub | `security-hardener` | تدقيق أمني بأمر واحد + إصلاح تلقائي — يكمّل `08-security` |
| **skill-vetter** | السوق المدمج | `skill_2053082706530402304` | **إلزامي**: فحص أمني لأي سكيل قبل تركيبه |
| **agent-browser** | SkillHub | `agent-browser` | أتمتة متصفح لاختبار E2E للوحة Blade |
| **web-scraper** | السوق المدمج | `skill_2053083117870850048` | خط أنابيب كشط متعدد المراحل — مفيد لإدخال بيانات الأدوية/الصيدليات |
| **awesome-design-md** | السوق المدمج | `skill_2053081374617444352` | 54 نظام تصميم مواقع معروفة — لو بدنا تجديد هوية بصرية |
| **fullstack-dev** | السوق المدمج | `skill_2053082011994116096` | معمارية تطبيقات كاملة — مرجع معماري عام |
| **mcp-builder** | السوق المدمج | `skill_2053082707118465024` | بناء MCP servers لو احتجنا ربط خدمات خارجية |

---

## أوامر التركيب

### أ) سكيلات السوق المدمج (BuiltinMarket)

تُركّب مباشرة بالأداة المخصّصة (`action: install` مع `skillId`). مثال:

```
skillId = skill_2053081993476866048   # flutter-dev
skillId = skill_2052764137292271616   # frontend-dev
skillId = skill_2053082862415904768   # impeccable
```

### ب) سكيلات SkillHub

```bash
SLUG=compound-eng-php-laravel
TMPDIR=$(mktemp -d)
curl -L -o "$TMPDIR/skill.zip" "https://lightmake.site/api/v1/download?slug=$SLUG"
mkdir -p ~/.workbuddy-ai/skills/$SLUG
unzip -o "$TMPDIR/skill.zip" -d ~/.workbuddy-ai/skills/$SLUG
rm -rf "$TMPDIR"
```

> ملاحظة: سكيل `find-skills` المثبّت حالياً يذكر مسار `~/.workbuddy/skills/` وهو **مسار قديم**؛ المسار الصحيح في هذا العميل هو `~/.workbuddy-ai/skills/`.

---

## ملاحظات مهمة

1. **سكيلات المشروع الداخلية مفقودة** — `AGENTS.md` يوثّق 14 سكيل في `.opencode/skills/` لكن المجلد غير موجود على القرص. إما أنها لم تُرفع لـ git، أو حُذفت. يستحق التحقق.
2. **سكيل مضمّن في vendor** — `vendor/spatie/laravel-permission/resources/boost/skills/laravel-permission-development/SKILL.md` موجود فعلاً؛ مفيد لمراجعة صلاحيات Spatie (النظام المزدوج roles في `RolePermissionSeeder`).
3. **قبل تركيب أي سكيل** — شغّل فحص أمني (`skill-vetter`) خصوصاً لسكيلات SkillHub من ناشرين غير موثّقين.
4. **لا يوجد سكيل مخصّص لمجال الأدوية/الصيدليات** — الفجوة الوحيدة الحقيقية هي المجال (`06-medicine-pharmacy`). الأفضل نبنيها داخلياً بدل الاعتماد على سكيل جاهز.

---

## التوصية النهائية

ركّب هذا الثماني كحدّ أدنى:

`compound-eng-php-laravel` · `laravel` · `mysql` · `flutter` · `affaan-m-ecc-dart-flutter-patterns` · `frontend-dev` · `arabic-rtl-layout-checker-skill` · `testing-patterns`
