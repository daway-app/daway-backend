<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Sync\SyncService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class SyncController extends Controller
{
    public function __construct(private SyncService $syncService)
    {
    }

    /**
     * إصدار Sanctum token لتفعيل المزامنة.
     *
     * ── مصادر التفويض المقبولة (الاثنان يعملان) ──────────────────────
     * 1. **Bearer** (`auth:sanctum` على المسار): تطبيق React أو أي عميل
     *    يحمل توكن مصادقة عاديًّا — وهذا ما يجعل الإصدار ممكنًا بعد
     *    انتقال الواجهة من Blade إلى React (لا جلسة ويب بعد الحذف).
     * 2. **جلسة ويب** عبر Sanctum stateful (كوكي): يبقى مقبولًا للعملاء
     *    الذين يرسلون كوكي الجلسة (`EnsureFrontendRequestsAreStateful`).
     *    بعد حذف واجهة Blade لم يبقَ مستهلك داخلي يستخدمه، لكن إبقاؤه
     *    لا يكلّف شيئًا ويمنع كسر أي عميل يعتمد الجلسة.
     *
     * ── لماذا `$request->user()` لا `Auth::guard('web')`؟ ────────────
     * `Auth::guard('web')` كان **يقتل** المسار Bearer عمدًا (يقرأ الجلسة
     * فقط)، فيصبح الإصدار مستحيلًا لأي عميل بلا جلسة. `$request->user()`
     * يعيد المستخدم من المصادقة الفعلية التي أجراها middleware المسار
     * (`auth:sanctum` — Bearer أو جلسة)، فلا نثق بمعرّف يرسله العميل
     * ولا نتحقق مرتين من شيئين مختلفين.
     */
    public function issueToken(Request $request): JsonResponse
    {
        $user = $request->user();

        if (! $user || $user->role !== 'pharmacy') {
            return response()->json(['success' => false, 'message' => 'غير مصرح'], 403);
        }

        // M-30: توكن واحد نشط لكل مستخدم — الإصدار الجديد يبطل القديم (بلا نمو بلا حدود)
        $user->tokens()->where('name', 'sync')->delete();

        $token = $user->createToken('sync')->plainTextToken;

        return response()->json([
            'success' => true,
            'message' => 'تم إنشاء رمز المزامنة بنجاح',
            'data' => ['token' => $token],
        ]);
    }

    /**
     * دفع دفعة عمليات مزامنة من قائمة الانتظار المحلية (offline queue).
     */
    public function push(Request $request): JsonResponse
    {
        $user = $request->user();

        abort_unless($user->role === 'pharmacy', 403);

        $validated = $request->validate([
            'operations' => 'required|array|max:200',
            'operations.*.uuid' => 'required|uuid',
            'operations.*.op_type' => 'required|in:inventory.update,medicine.store,medicine.update,inquiry.status',
            'operations.*.payload' => 'required|array',
            'operations.*.client_updated_at' => 'nullable|date',
        ]);

        $results = $this->syncService->push($validated['operations'], $user);

        return response()->json([
            'success' => true,
            'message' => 'تمت معالجة عمليات المزامنة',
            'data' => ['results' => $results],
        ]);
    }

    /**
     * سحب آخر التغييرات منذ آخر مزامنة (incremental pull).
     */
    public function pull(Request $request): JsonResponse
    {
        $user = $request->user();

        abort_unless($user->role === 'pharmacy', 403);

        $validated = $request->validate([
            'since' => 'nullable|date',
        ]);

        $payload = $this->syncService->pull($user, $validated['since'] ?? null);

        return response()->json($payload);
    }
}
