<?php

namespace App\Http\Controllers\web\Admin;

use App\Http\Controllers\Controller;
use App\Models\Notification;
use App\Models\User;
use App\Services\NotificationGenerator;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class NotificationController extends Controller
{
    /**
     * Get the count of unread notifications for the authenticated user.
     */
    public function count()
    {
        try {
            if (! Auth::check()) {
                return response()->json([
                    'count' => 0,
                    'message' => 'User not authenticated',
                ], 401);
            }

            /** @var User $user */
            $user = Auth::user();

            $this->syncNotifications($user);

            $count = Cache::remember('notifications_count_' . Auth::id(), 15, function () use ($user) {
                return $user->notifications()
                    ->where('is_read', false)
                    ->count();
            });

            return response()->json(['count' => $count]);

        } catch (\Exception $e) {
            Log::error('Error fetching notification count: '.$e->getMessage());

            return response()->json([
                'count' => 0,
                'message' => 'Failed to fetch notification count',
            ], 500);
        }
    }

    /**
     * Get all notifications for the authenticated user.
     */
    public function index()
    {
        try {
            if (! Auth::check()) {
                return response()->json([
                    'notifications' => [],
                    'message' => 'User not authenticated',
                ], 401);
            }

            /** @var User $user */
            $user = Auth::user();

            $this->syncNotifications($user);

            $notifications = Cache::remember('notifications_feed_' . Auth::id(), 15, function () use ($user) {
                return $user->notifications()
                    ->latest('created_at')
                    ->limit(20)
                    ->get()
                    ->map(fn ($n) => [
                        'id' => $n->id,
                        'type' => $n->type,
                        'message' => $n->message,
                        'is_read' => (bool) $n->is_read,
                        'link' => $this->notificationLink($n),
                        'created_at' => $n->created_at ? $n->created_at->toIso8601String() : null,
                    ])
                    ->values()
                    ->all();
            });

            return response()->json([
                'notifications' => $notifications,
            ]);

        } catch (\Exception $e) {
            Log::error('Error fetching notifications: '.$e->getMessage());

            return response()->json([
                'notifications' => [],
                'message' => 'Failed to fetch notifications',
            ], 500);
        }
    }

    /**
     * Display all notifications for the authenticated user on a dedicated page.
     */
    public function showAll()
    {
        try {
            if (! Auth::check()) {
                return redirect()
                    ->route('login.show')
                    ->with('error', 'You must be logged in to view notifications.');
            }

            /** @var User $user */
            $user = Auth::user();

            $notifications = $user->notifications()
                ->orderBy('created_at', 'desc')
                ->get();

            return view('notifications.index', compact('notifications'));

        } catch (\Exception $e) {
            Log::error(
                'Error displaying all notifications page: '.$e->getMessage()
            );

            return view('notifications.index', [
                'notifications' => collect(),
            ])->with('error', 'Failed to load notifications.');
        }
    }

    /**
     * Mark a specific notification as read.
     */
    public function markAsRead(Notification $notification)
    {
        try {
            if (! Auth::check()) {
                return response()->json([
                    'message' => 'User not authenticated',
                ], 401);
            }

            if ($notification->user_id !== Auth::id()) {
                return response()->json([
                    'message' => 'Unauthorized',
                ], 403);
            }

            $notification->is_read = true;
            $notification->save();

            Cache::forget('notifications_count_' . Auth::id());
            Cache::forget('notifications_feed_' . Auth::id());

            return response()->json([
                'message' => 'Notification marked as read',
            ]);

        } catch (\Exception $e) {
            Log::error(
                'Error marking notification as read: '.$e->getMessage()
            );

            return response()->json([
                'message' => 'Failed to mark notification as read',
            ], 500);
        }
    }

    /**
     * Mark all unread notifications for the authenticated user as read.
     */
    public function markAllAsRead()
    {
        try {
            if (! Auth::check()) {
                return response()->json([
                    'message' => 'User not authenticated',
                ], 401);
            }

            /** @var User $user */
            $user = Auth::user();

            $user->notifications()
                ->where('is_read', false)
                ->update(['is_read' => true]);

            Cache::forget('notifications_count_' . Auth::id());
            Cache::forget('notifications_feed_' . Auth::id());

            return response()->json([
                'message' => 'All notifications marked as read',
            ]);

        } catch (\Exception $e) {
            Log::error(
                'Error marking all notifications as read: '.$e->getMessage()
            );

            return response()->json([
                'message' => 'Failed to mark all notifications as read',
            ], 500);
        }
    }

    /**
     * يولّد إشعارات النظام (نقص مخزون...) ثم يكسر الكاش إذا أُنشئ جديد.
     */
    private function syncNotifications(User $user): void
    {
        try {
            if (NotificationGenerator::syncForUser($user)) {
                Cache::forget('notifications_count_' . Auth::id());
                Cache::forget('notifications_feed_' . Auth::id());
            }
        } catch (\Exception $e) {
            Log::error('Error syncing notifications: '.$e->getMessage());
        }
    }

    /**
     * رابط الانتقال عند الضغط على الإشعار (حسب النوع ودور المستخدم).
     */
    private function notificationLink(Notification $n): ?string
    {
        try {
            if ($n->type === 'low_stock') {
                if (Auth::user()?->pharmacy()->exists()) {
                    return route('pharmacy.medicines.index');
                }

                return route('medicines.index');
            }
        } catch (\Exception $e) {
            Log::error('Error building notification link: '.$e->getMessage());
        }

        return null;
    }
}
