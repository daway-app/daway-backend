<?php

namespace App\Console\Commands;

use App\Contracts\FcmSender;
use App\Models\Notification;
use App\Models\Reminder;
use Carbon\Carbon;
use Carbon\CarbonPeriod;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class SendDueReminders extends Command
{
    protected $signature = 'reminders:send-due';
    protected $description = 'Send FCM notifications for due active reminders with idempotency';

    public function handle(FcmSender $fcm): int
    {
        $now = now();

        $reminders = Reminder::query()
            ->where('is_active', true)
            ->where(function ($q) use ($now) {
                $q->whereNull('reminder_sent_at')
                    ->orWhere('reminder_sent_at', '<', $now->startOfDay());
            })
            ->get();

        $processed = 0;

        foreach ($reminders as $reminder) {
            if (! $this->isDue($reminder)) {
                continue;
            }

            $user = $reminder->user;
            if (! $user) {
                continue;
            }

            if (! $user->notifications_enabled) {
                continue;
            }

            $notification = Notification::create([
                'user_id' => $user->id,
                'medicine_id' => null,
                'type' => 'reminder',
                'message' => sprintf('حان وقت تناول %s - %s', $reminder->medicine_name, $reminder->dosage),
                'is_read' => false,
                'created_at' => now(),
            ]);

            $fcm->fromNotification($notification);

            DB::table('reminders')
                ->where('id', $reminder->id)
                ->update(['reminder_sent_at' => now()]);

            $processed++;
        }

        $this->info("Processed {$processed} due reminders");
        return Command::SUCCESS;
    }

    private function isDue(Reminder $reminder): bool
    {
        $now = now();

        if ($reminder->reminder_sent_at && Carbon::parse($reminder->reminder_sent_at)->isSameDay($now)) {
            return false;
        }

        $timeStr = $reminder->reminder_time instanceof Carbon
            ? $reminder->reminder_time->format('H:i')
            : (string) $reminder->reminder_time;

        $reminderTime = Carbon::createFromFormat('H:i', $timeStr, config('app.timezone'));
        if (! $reminderTime) {
            return false;
        }

        $reminderTime->setTimezone('UTC');
        $nowUtc = $now->copy()->setTimezone('UTC');

        if ($reminder->frequency === 'daily') {
            return $nowUtc->isSameDay($reminderTime) && (int) $nowUtc->format('Hi') >= (int) $reminderTime->format('Hi');
        }

        if ($reminder->frequency === 'weekly') {
            $startOfWeek = $nowUtc->copy()->startOfWeek();
            $endOfWeek = $nowUtc->copy()->endOfWeek();

            $period = new CarbonPeriod($startOfWeek, $endOfWeek);
            foreach ($period as $day) {
                $scheduled = $reminderTime->copy()->setDate($day->year, $day->month, $day->day);
                if ($nowUtc->isSameDay($scheduled) && (int) $nowUtc->format('Hi') >= (int) $scheduled->format('Hi')) {
                    return true;
                }
            }

            return false;
        }

        return $nowUtc->isSameDay($reminderTime) && (int) $nowUtc->format('Hi') >= (int) $reminderTime->format('Hi');
    }
}
