<?php

namespace App\Services\Ai;

/**
 * تحويل رسالة المريض الحرة إلى نية + اسم دواء — بلا أي وصول لقاعدة البيانات.
 *
 * هذا هو الحدّ الوحيد الذي يُسمح للـ LLM بالعمل فيه. مخرجات الـ LLM **غير موثوقة**
 * وتُعامَل كمدخل مستخدم: تُفحص، تُطبَّع، وتُقصّ قبل الاستخدام. لا يُقبل منها إلا
 * `intent` من قائمة بيضاء + `drug_name` نظيف — وكل ما عدا ذلك يُرفض.
 *
 * عند غياب الخدمة أو فشلها أو إرجاعها مخرجات غير صالحة، نرجع إلى مستخرج محلي
 * حتمي (rule-based) يجرّد كلمات السؤال/الحشو. النتيجة تبقى **اقتراحاً** يُتحقق منه
 * لاحقاً عبر MedicineResolver مقابل بيانات حقيقية — فلا يُخترع اسم دواء أبداً.
 *
 * ما لا تفعله هذه الخدمة عن قصد: لا سعر، لا مسافة، لا توفر، لا ترتيب صيدليات.
 */
final class MedicineIntentService
{
    public const INTENT_MEDICINE_SEARCH = 'medicine_search';

    public const INTENT_UNKNOWN = 'unknown';

    /** أقصى طول مقبول لاسم الدواء القادم من الـ LLM — يمنع تضخيم المدخلات. */
    private const MAX_DRUG_NAME_LENGTH = 120;

    private const MIN_DRUG_NAME_LENGTH = 2;

    /** النوايا المسموح بها فقط — أي قيمة أخرى تُرفض. */
    private const ALLOWED_INTENTS = [
        self::INTENT_MEDICINE_SEARCH,
        self::INTENT_UNKNOWN,
    ];

    /**
     * كلمات سؤال/حشو تُجرَّد في المسار المحلي.
     *
     * ملاحظة مقصودة: لا تُدرَج أوصاف الأشكال/التراكيز (extra, plus, forte,
     * advance, cold, flu, اكسترا, بلص ...) حتى لا يتشوّه اسم الدواء الحقيقي.
     */
    private const STOP_WORDS = [
        // عربي — أدوات استفهام وطلب
        'وين', 'وينه', 'وينها', 'وينو', 'اين', 'فين', 'شو', 'ايش', 'إيش', 'ماذا', 'هل',
        'بدي', 'اريد', 'أريد', 'عايز', 'ابغى', 'أبغى', 'بحاجه', 'بحاجة', 'محتاج', 'محتاجه',
        // عربي — أفعال البحث
        'بلاقي', 'بلاقيه', 'بلاقيها', 'الاقي', 'ألاقي', 'القى', 'ألقى', 'اجد', 'أجد', 'لاقي', 'ادور', 'أدور',
        // عربي — حشو وموقع
        'في', 'فيه', 'فيه؟', 'عند', 'عندك', 'عندكم', 'يوجد', 'موجود', 'متوفر', 'متواجد',
        'من', 'لي', 'لي؟', 'مني', 'قريب', 'قريبه', 'قريبة', 'اقرب', 'أقرب', 'باقرب', 'بأقرب',
        'ارخص', 'أرخص', 'افضل', 'أفضل', 'احسن', 'أحسن', 'اريد؟',
        'صيدليه', 'صيدلية', 'صيدليات', 'الدواء', 'دواء', 'العلاج', 'علاج', 'حبوب', 'دوا',
        'لو', 'سمحت', 'فضلك', 'بليز', 'رجاء', 'شكرا', 'شكراً', 'مرحبا', 'اهلا', 'أهلا', 'هلا',
        // إنجليزي
        'where', 'can', 'could', 'i', 'find', 'want', 'need', 'is', 'are', 'am', 'the', 'a', 'an',
        'available', 'availability', 'near', 'nearby', 'me', 'my', 'closest', 'nearest', 'cheap',
        'cheapest', 'best', 'pharmacy', 'pharmacies', 'medicine', 'medicines', 'drug', 'drugs',
        'do', 'does', 'you', 'have', 'has', 'please', 'to', 'buy', 'get', 'in', 'at', 'of', 'for',
        'price', 'prices', 'cost', 'stock', 'there', 'any', 'some', 'and', 'or', 'with', 'from',
        'hello', 'hi', 'hey', 'thanks', 'thank',
    ];

    public function __construct(
        private readonly ?MedicineIntentClient $client = null,
    ) {}

    /**
     * @return array{intent:string, drug_name:?string, confidence:?float, source:string}
     */
    public function analyze(string $message): array
    {
        $message = trim($message);

        if ($message === '') {
            return $this->unknown('empty');
        }

        // 1) الـ LLM (إن كان مُهيَّأ) — المخرجات تُفحص قبل الثقة بها
        if ($this->client !== null) {
            $validated = $this->validate($this->client->analyze($message));

            if ($validated !== null) {
                return $validated;
            }
        }

        // 2) مستخرج محلي حتمي — يعمل بلا أي خدمة خارجية
        return $this->heuristic($message);
    }

    /**
     * يفحص مخرجات الـ LLM ويرجّعها مُطبَّعة، أو null إن كانت غير صالحة
     * (في هذه الحالة يسقط المستدعي إلى المسار المحلي).
     *
     * @param  array<string, mixed>  $raw
     * @return array{intent:string, drug_name:?string, confidence:?float, source:string}|null
     */
    private function validate(array $raw): ?array
    {
        $intent = $raw['intent'] ?? null;

        if (! is_string($intent) || ! in_array($intent, self::ALLOWED_INTENTS, true)) {
            return null;
        }

        $drugName = $this->sanitizeDrugName($raw['drug_name'] ?? null);

        // نية بحث بلا اسم دواء صالح = نية غير مفيدة → نجرّب المسار المحلي بدلاً منها
        if ($intent === self::INTENT_MEDICINE_SEARCH && $drugName === null) {
            return null;
        }

        if ($intent === self::INTENT_UNKNOWN) {
            return $this->unknown('llm');
        }

        return [
            'intent' => $intent,
            'drug_name' => $drugName,
            'confidence' => $this->sanitizeConfidence($raw['confidence'] ?? null),
            'source' => 'llm',
        ];
    }

    /**
     * تنقية اسم الدواء القادم من الـ LLM.
     *
     * يرفض: غير النصوص، الطويل/القصير جداً، محارف التحكّم، وأي محارف بنيوية
     * (أقواس/أقواس معقوفة/علامات اقتباس خلفية) قد تُستخدم في حقن التعليمات
     * أو تسريب بنية JSON.
     */
    private function sanitizeDrugName(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        // إزالة محارف التحكّم والمسافات المتكررة.
        // ملاحظة: عند إرسال UTF-8 تالف، تُعيد preg_replace بـ/u قيمة null
        // فيسقط الاسم ونتعامل مع الرسالة كغير محدّدة — سلوك آمن مقصود.
        $name = preg_replace('/[\x00-\x1F\x7F]/u', ' ', $value) ?? '';
        $name = preg_replace('/\s+/u', ' ', $name) ?? '';

        // قصّ علامات الترقيم من الطرفين عبر regex وليس trim().
        // السبب: charlist في trim() تعمل على **البايتات** لا المحارف، فتمرير
        // محارف عربية متعددة البايتات (؟ ، ؛) يقصّ بايتاً واحداً منها فيُنتج
        // نصاً UTF-8 تالفاً يكسر json_encode في الرد.
        $name = preg_replace('/^[\s.,;:!?،؛؟]+|[\s.,;:!?،؛؟]+$/u', '', $name) ?? '';

        if (mb_strlen($name) < self::MIN_DRUG_NAME_LENGTH || mb_strlen($name) > self::MAX_DRUG_NAME_LENGTH) {
            return null;
        }

        // رفض محارف بنيوية — أسماء الأدوية الحقيقية لا تحتاجها
        if (preg_match('/[<>{}`\\\\|]/u', $name) === 1) {
            return null;
        }

        return $name;
    }

    private function sanitizeConfidence(mixed $value): ?float
    {
        if (! is_numeric($value)) {
            return null;
        }

        $confidence = (float) $value;

        if (! is_finite($confidence)) {
            return null;
        }

        return round(max(0.0, min(1.0, $confidence)), 2);
    }

    /**
     * مستخرج محلي: يجرّد كلمات السؤال/الحشو ويُبقي ما يُرجَّح أنه اسم الدواء.
     * النتيجة اقتراح فقط — MedicineResolver هو من يقرّر إن كان دواءً حقيقياً.
     */
    private function heuristic(string $message): array
    {
        $cleaned = mb_strtolower($message);
        $cleaned = preg_replace('/[؟?،,\.!؛;:"\'\(\)\[\]]/u', ' ', $cleaned) ?? '';

        $words = preg_split('/\s+/u', $cleaned, -1, PREG_SPLIT_NO_EMPTY) ?: [];

        $kept = [];
        foreach ($words as $word) {
            if (in_array($word, self::STOP_WORDS, true)) {
                continue;
            }

            // كلمة من حرف واحد لا تحمل معنى دواء
            if (mb_strlen($word) < 2) {
                continue;
            }

            $kept[] = $word;
        }

        // نفس تنقية مخرجات الـ LLM — الرسالة مدخل غير موثوق، واسم الدواء
        // المقترح يُعاد إلى العميل داخل `analysis`، فلا يجوز أن يحمل محارف بنيوية.
        $candidate = $this->sanitizeDrugName(implode(' ', $kept));

        if ($candidate === null) {
            return $this->unknown('heuristic');
        }

        return [
            'intent' => self::INTENT_MEDICINE_SEARCH,
            'drug_name' => $candidate,
            // لا درجة ثقة هنا: المستخرج المحلي قواعد حتمية لا تُنتج score.
            // إرجاع رقم ثابت (مثل 0.5) سيكون اختلاقاً يوهم العميل بحساب لم يحدث.
            'confidence' => null,
            'source' => 'heuristic',
        ];
    }

    private function unknown(string $source): array
    {
        return [
            'intent' => self::INTENT_UNKNOWN,
            'drug_name' => null,
            'confidence' => null,
            'source' => $source,
        ];
    }
}
