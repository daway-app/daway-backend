<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Reminder;
use Illuminate\Http\Request;

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
            'message' => 'Reminder created successfully',
            'data' => $reminder,
        ], 201);
    }

    public function show(Request $request, Reminder $reminder)
    {
        $this->authorizeOwner($request, $reminder);

        return response()->json([
            'success' => true,
            'data' => $reminder,
        ]);
    }

    public function update(Request $request, Reminder $reminder)
    {
        $this->authorizeOwner($request, $reminder);

        $reminder->update($this->validatedData($request, true));

        return response()->json([
            'success' => true,
            'message' => 'Reminder updated successfully',
            'data' => $reminder->fresh(),
        ]);
    }

    public function destroy(Request $request, Reminder $reminder)
    {
        $this->authorizeOwner($request, $reminder);

        $reminder->delete();

        return response()->json([
            'success' => true,
            'message' => 'Reminder deleted successfully',
        ]);
    }

    public function markTaken(Request $request, Reminder $reminder)
    {
        $this->authorizeOwner($request, $reminder);

        $remaining = max(0, ((int) $reminder->quantity_remaining) - 1);
        $reminder->update([
            'quantity_remaining' => $remaining,
            'is_active' => $remaining > 0,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Dose marked as taken',
            'data' => $reminder->fresh(),
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

        if (!$partial) {
            $data['frequency'] = $data['frequency'] ?? 'once';
            $data['is_active'] = $data['is_active'] ?? true;
        }

        return $data;
    }

    private function normalizeRemainingDoses(Request $request): void
    {
        if (
            !$request->has('quantity_remaining')
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
