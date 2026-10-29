<?php

namespace Tests\Feature;

use App\Models\Pharmacy;
use App\Services\Accounting\AccountingReports;
use Illuminate\Routing\RouteCollection;
use Tests\TestCase;

/**
 * R-A: الاستقلال عن جدول مسارات الويب.
 *
 * `AccountingReports` خدمة **مشتركة** بين الـAPI والويب. كانت تبني حقل `href`
 * بـ`route('pharmacy.accounting.*')` — أي **أسماء مسارات ويب**. و`route()`
 * تُقيَّم أثناء بناء المصفوفة لا عند قراءة القيمة، فحذف واجهة الويب كان
 * يُسقط `GET /api/pharmacy/accounting/overview` بـRouteNotFoundException
 * حتى لو لم يقرأ أحد الحقل.
 *
 * حارسان يمنعان عودة هذا الاعتماد:
 *   1) حارس ثابت على المصدر: لا استدعاء لدالة المسارات في أي خدمة.
 *   2) حارس سلوكي: طرق التقارير تعمل بعد إزالة مسارات الويب المحاسبية فعليًا.
 */
class AccountingReportsRouteIndependenceTest extends TestCase
{
    public function test_no_service_depends_on_the_route_helper(): void
    {
        $offenders = [];

        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(app_path('Services'), \FilesystemIterator::SKIP_DOTS)
        );

        foreach ($files as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }

            $source = (string) file_get_contents($file->getPathname());

            if (preg_match('/\broute\s*\(/', $source) === 1) {
                $offenders[] = str_replace(base_path().DIRECTORY_SEPARATOR, '', $file->getPathname());
            }
        }

        $this->assertSame(
            [],
            $offenders,
            'خدمة مشتركة تعتمد على أسماء مسارات الويب — حذف الويب سيُسقط الـAPI.'
        );
    }

    public function test_accounting_reports_work_without_the_web_accounting_routes(): void
    {
        $pharmacy = Pharmacy::factory()->create();

        $router = app('router');

        // أزِل كل مسارات `pharmacy.accounting.*` من مجموعة المسارات، كما لو أن
        // واجهة الويب حُذفت فعلًا.
        $kept = new RouteCollection;

        foreach ($router->getRoutes()->getRoutes() as $route) {
            if (! str_starts_with((string) $route->getName(), 'pharmacy.accounting.')) {
                $kept->add($route);
            }
        }

        $router->setRoutes($kept);

        $this->assertNull(
            $router->getRoutes()->getByName('pharmacy.accounting.sales.index'),
            'شرط مسبق: مسارات ويب المحاسبة يجب أن تكون مُزالة'
        );

        // لو بقي أي استدعاء لدالة المسارات لرمت RouteNotFoundException هنا.
        $this->assertNotEmpty(AccountingReports::kpis($pharmacy->id));
        $this->assertIsArray(AccountingReports::alerts($pharmacy->id));
        $this->assertIsArray(AccountingReports::recentTransactions($pharmacy->id, 8));
    }
}
