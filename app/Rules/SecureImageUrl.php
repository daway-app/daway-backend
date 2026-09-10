<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * C4: قاعدة مُحكمة لقبول روابط الصور الخارجية.
 *
 * تستبعد السلوك الخطر لـ Laravel's `url` rule (الذي يقبل javascript: و data:).
 * تقبل فقط:
 *  - https://
 *  - URLs من نطاقات Cloudinary (res.cloudinary.com / cloudinary.com)
 *
 * أي URL آخر (http://، ftp://، file://، data:، javascript:) يُرفض بـ 422.
 */
class SecureImageUrl implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || $value === '') {
            return;
        }

        // يجب أن يكون https:// (يستبعد http://، ftp://، data:، javascript:).
        if (! preg_match('/^https:\/\//i', $value)) {
            $fail("حقل {$attribute} يجب أن يبدأ بـ https:// حصراً.");

            return;
        }

        $host = parse_url($value, PHP_URL_HOST);
        if (! $host) {
            $fail("حقل {$attribute} غير صالح: المضيف غير موجود.");

            return;
        }

        // M-5: مطابقة تامة للمضيف (بدون suffix match) — evilcloudinary.com / notcloudinary.com
        // يجب أن تُرفض؛ يُسمح فقط بـ res.cloudinary.com و subdomain cloud-name فيه
        $host = strtolower((string) $host);
        $isAllowed = $host === 'res.cloudinary.com'
            || preg_match('#^[a-z0-9-]+\.res\.cloudinary\.com$#', $host) === 1;

        if (! $isAllowed) {
            $fail("حقل {$attribute} يجب أن يكون رابط Cloudinary (https://res.cloudinary.com/...).");
        }
    }
}
