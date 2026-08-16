@extends('layouts.app')

@section('title', __('layout.all_notifications_title'))

@section('content')
<div class="notifications-page-container">
    <h1>@lang('layout.all_notifications_title')</h1>

    <div class="notifications-actions">
        <button class="btn btn-primary" onclick="markAllNotificationsAsRead()">@lang('layout.mark_all_as_read')</button>
    </div>

    <div class="notifications-list-full">
        @forelse($notifications as $notification)
            @if ($notification->link)
                <a href="{{ $notification->link }}" class="notification-item-full {{ $notification->is_read ? '' : 'unread' }}" data-notification-id="{{ $notification->id }}">
            @else
                <div class="notification-item-full {{ $notification->is_read ? '' : 'unread' }}" data-notification-id="{{ $notification->id }}">
            @endif
                <div class="notification-icon-full">
                    {{-- Dynamic icon based on notification type --}}
                    @php
                        $iconSvg = '';
                        $iconClass = '';
                        switch ($notification->type) {
                            case 'low_stock':
                                $iconSvg = '<svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"></path><line x1="12" y1="9" x2="12" y2="13"></line><line x1="12" y1="17" x2="12.01" y2="17"></line></svg>';
                                $iconClass = 'bg-red';
                                break;
                            case 'reminder':
                                $iconSvg = '<svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"></path><path d="M13.73 21a2 2 0 0 1-3.46 0"></path></svg>';
                                $iconClass = 'bg-blue';
                                break;
                            default:
                                $iconSvg = '<svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"></path><path d="M13.73 21a2 2 0 0 1-3.46 0"></path></svg>';
                                $iconClass = 'bg-gray';
                        }
                    @endphp
                    <div class="icon-wrapper {{ $iconClass }}">
                        {!! $iconSvg !!}
                    </div>
                </div>
                <div class="notification-content-full">
                    <p class="notification-message-full">{{ $notification->message }}</p>
                    <span class="notification-time-full">{{ $notification->created_at->diffForHumans() }}</span>
                </div>
                @if (!$notification->is_read)
                    <span class="notification-status-full">@lang('layout.unread')</span>
                @endif
            @if ($notification->link)
                </a>
            @else
                </div>
            @endif
        @empty
            <p class="no-notifications-full">@lang('layout.no_notifications_yet')</p>
        @endforelse
    </div>
</div>

<script>
    // This function is already defined in topbar.blade.php, but we need it here as well
    // or ensure topbar.blade.php's script is loaded before this page's script.
    // For simplicity, I'll include a basic version here.
    async function markAllNotificationsAsRead() {
        try {
            const response = await fetch('/api/notifications/mark-all-as-read', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').getAttribute('content')
                }
            });
            if (response.ok) {
                // Update UI: mark all as read and refresh
                document.querySelectorAll('.notification-item-full.unread').forEach(item => {
                    item.classList.remove('unread');
                    const statusSpan = item.querySelector('.notification-status-full');
                    if (statusSpan) statusSpan.remove();
                });
                // Optionally, update the badge count in the topbar if it's still visible
                const topbarBadge = window.parent.document.getElementById('notificationBadge');
                if (topbarBadge) {
                    topbarBadge.style.display = 'none';
                    topbarBadge.innerText = '0';
                }
            }
        } catch (error) {
            console.error("@lang('layout.error_marking_all_as_read')", error);
        }
    }

    // Add event listener to mark individual notification as read when clicked
    document.addEventListener('DOMContentLoaded', () => {
        document.querySelectorAll('.notification-item-full').forEach(item => {
            item.addEventListener('click', async (e) => {
                const notificationId = item.dataset.notificationId;
                if (notificationId && item.classList.contains('unread')) {
                    try {
                        const response = await fetch(`/api/notifications/${notificationId}/read`, {
                            method: 'POST',
                            headers: {
                                'Content-Type': 'application/json',
                                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').getAttribute('content')
                            }
                        });
                        if (response.ok) {
                            item.classList.remove('unread');
                            const statusSpan = item.querySelector('.notification-status-full');
                            if (statusSpan) statusSpan.remove();
                            // Update topbar badge count
                            const topbarBadge = window.parent.document.getElementById('notificationBadge');
                            if (topbarBadge && parseInt(topbarBadge.innerText) > 0) {
                                topbarBadge.innerText = parseInt(topbarBadge.innerText) - 1;
                                if (topbarBadge.innerText === '0') {
                                    topbarBadge.style.display = 'none';
                                }
                            }
                        }
                    } catch (error) {
                        console.error("@lang('layout.error_marking_as_read')", error);
                    }
                }
            });
        });
    });
</script>
@endsection
