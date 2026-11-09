<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * عزل بيئة الاختبار عن قاعدة البيانات الحقيقية.
 *
 * سبب الوجود: `phpunit.xml` كان يضبط `DB_CONNECTION=sqlite` **بدون**
 * `force="true"`. وPHPUnit لا يتجاوز متغيرات الـshell في هذه الحالة،
 * فتمرير `DB_CONNECTION=mysql` (أو وجوده في بيئة المطوّر) يجعل
 * `RefreshDatabase` يهاجر ويحذف على قاعدة **حقيقية** — وقد يكون Aiven.
 *
 * أُثبت التسرب عمليًا قبل الإصلاح:
 *   DB_CONNECTION=mysql php vendor/bin/phpunit …
 *   ⇒ SQLSTATE[HY000] No connection could be made
 *
 * هذا الاختبار يفشل فورًا إن عاد أحدهم لرفع `force="true"`، حتى لو كانت
 * البيئة المحيطة معادية.
 */
class TestEnvironmentIsolationTest extends TestCase
{
    public function test_the_suite_always_runs_on_in_memory_sqlite(): void
    {
        $this->assertSame('sqlite', config('database.default'));
        $this->assertSame(':memory:', config('database.connections.sqlite.database'));
    }

    public function test_the_suite_never_points_at_a_remote_database(): void
    {
        $this->assertNotSame('mysql', config('database.default'));
        $this->assertNotSame('pgsql', config('database.default'));
    }

    public function test_receipt_storage_is_pinned_to_disk_in_tests(): void
    {
        // الاختبارات لا تتصل بـCloudinary ولا تعتمد على قيم البيئة.
        $this->assertSame('disk', config('services.cloudinary.receipts.driver'));
        $this->assertSame('', (string) config('services.cloudinary.cloud'));
        $this->assertSame('', (string) config('services.cloudinary.upload_preset'));
    }
}
