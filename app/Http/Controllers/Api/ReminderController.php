<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Reminder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ReminderController extends Controller
{
    public function index(Request $request)
    {
        $reminders = $request->user()
            ->reminders()
            ->latest('reminder_date')
            ->latest('reminder_time')
            ->get();

        return response()->json([
            'success' => true,
            'message' => 'تم جلب التذكيرات بنجاح',
            'data' => $reminders,
        ]);
    }

    public function store(Request $request)
    {
        $data = $this->validatedData($request);
        $data['user_id'] = $request->user()->id;

        $reminder = Reminder::create($data);

        return response()->json([
            'success' => true,
            'message' => 'تم إنشاء التذكير بنجاح',
            'data' => $reminder,
        ], 201);
    }

    public function show(Request $request, Reminder $reminder)
    {
        $this->authorizeOwner($request, $reminder);

        return response()->json([
            'success' => true,
            'message' => 'تم جلب التذكير بنجاح',
            'data' => $reminder,
        ]);
    }

    public function update(Request $request, Reminder $reminder)
    {
        $this->authorizeOwner($request, $reminder);

        $reminder->update($this->validatedData($request, true));

        return response()->json([
            'success' => true,
            'message' => 'تم تحديث التذكير بنجاح',
            'data' => $reminder->fresh(),
        ]);
    }

    public function destroy(Request $request, Reminder $reminder)
    {
        $this->authorizeOwner($request, $reminder);

        $reminder->delete();

        return response()->json([
            'success' => true,
            'message' => 'تم حذف التذكير بنجاح',
        ]);
    }

    public function markTaken(Request $request, Reminder $reminder)
    {
        $this->authorizeOwner($request, $reminder);

        // H7: تحديث ذري — يمنع السباق عند طلبين متزامنين (قراءة قديمة ثم كتابة).
        // يخفض quantity_remaining بشرط > 0، ويحدّث is_active بناءً على القيمة الجديدة.
        $affected = Reminder::where('id', $reminder->id)
            ->where('quantity_remaining', '>', 0)
            ->update([
                'quantity_remaining' => DB::raw('quantity_remaining - 1'),
                // الحالة الجديدة: هل بقيت جرعات بعد الخصم؟ (القيمة القديمة - 1 > 0)
                'is_active' => DB::raw('CASE WHEN quantity_remaining - 1 > 0 THEN 1 ELSE 0 END'),
            ]);

        if ($affected === 0) {
            // لم يُخصم شيء: إما quantity_remaining = 0 أصلاً
            Reminder::where('id', $reminder->id)->where('is_active', true)->update(['is_active' => false]);
        }

        $reminder->refresh();

        return response()->json([
            'success' => true,
            'message' => 'تم تسجيل الجرعة كتذكير مأخوذ',
            'data' => $reminder,
        ]);
    }

    private function validatedData(Request $request, bool $partial = false): array
    {
        $this->normalizeRemainingDoses($request);

        $prefix = $partial ? 'sometimes' : 'required';

        $data = $request->validate([
            'medicine_name' => [$prefix, 'string', 'max:150'],
            'dosage' => [$prefix, 'string', 'max:100'],
            'reminder_date' => [$prefix, 'date'],
            'reminder_time' => [$prefix, 'date_format:H:i'],
            'frequency' => ['sometimes', 'nullable', 'string', 'max:50'],
            'quantity_remaining' => [$prefix, 'integer', 'min:0'],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        if (! $partial) {
            $data['frequency'] = $data['frequency'] ?? 'once';
            $data['is_active'] = $data['is_active'] ?? true;
        }

        return $data;
    }

    private function normalizeRemainingDoses(Request $request): void
    {
        if (
            ! $request->has('quantity_remaining')
            && ($request->has('remaining_doses') || $request->has('dose_count'))
        ) {
            $request->merge([
                'quantity_remaining' => $request->input('remaining_doses', $request->input('dose_count')),
            ]);
        }
    }

    private function authorizeOwner(Request $request, Reminder $reminder): void
    {
        abort_unless($reminder->user_id === $request->user()->id, 404);
    }
}
