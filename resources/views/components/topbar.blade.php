@vite(['resources/css/layout/topbar.css'])

<div class="topbar">
    <div class="topbar-title-group">
        <h1>@lang('topbar.dashboard_title')</h1>
        <p>@lang('topbar.dashboard_subtitle')</p>
    </div>

    <div class="topbar-actions">
        <a href="{{ route('locale.change', app()->getLocale() === 'ar' ? 'en' : 'ar') }}"
           class="icon-btn lang-switch-btn"
           title="@lang('layout.switch_language_tooltip')">
            <span class="lang-label">{{ app()->getLocale() === 'ar' ? 'EN' : 'ع' }}</span>
        </a>

        <div class="notifications-wrapper">
            <button class="icon-btn" id="notificationBtn" title="@lang('layout.notifications_tooltip')" onclick="toggleNotificationsDropdown()">
                <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"></path><path d="M13.73 21a2 2 0 0 1-3.46 0"></path></svg>
                <span class="notification-badge" id="notificationBadge"></span>
            </button>
            <div class="notifications-dropdown" id="notificationsDropdown">
                <div class="notifications-header">
                    <h3>@lang('layout.notifications_title')</h3>
                    <button class="clear-all-btn" onclick="markAllNotificationsAsRead()">@lang('layout.mark_all_as_read')</button>
                </div>
                <div class="notifications-list" id="notificationsList">
                    {{-- Notifications will be loaded here --}}
                    <p class="no-notifications">@lang('layout.no_new_notifications')</p>
                </div>
                <div class="notifications-footer">
                    <a href="{{ route('notifications.index') }}">@lang('layout.view_all_notifications')</a>
                </div>
            </div>
        </div>

        <button class="icon-btn theme-toggle-btn" onclick="toggleDarkMode()" title="@lang('layout.dark_mode_tooltip')">
            <span class="icon-3d sun-icon"><svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="5"></circle><line x1="12" y1="1" x2="12" y2="3"></line><line x1="12" y1="21" x2="12" y2="23"></line><line x1="4.22" y1="4.22" x2="5.64" y2="5.64"></line><line x1="18.36" y1="18.36" x2="19.78" y2="19.78"></line><line x1="1" y1="12" x2="3" y2="12"></line><line x1="21" y1="12" x2="23" y2="12"></line><line x1="4.22" y1="19.78" x2="5.64" y2="18.36"></line><line x1="18.36" y1="5.64" x2="19.78" y2="4.22"></line></svg></span>
            <span class="icon-3d moon-icon"><svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 12.79A9 9 0 1 1 11.21 3 7 7 0 0 0 21 12.79z"></path></svg></span>
        </button>


        <div class="user-profile-btn" onclick="openProfileModal()" title="@lang('layout.edit_profile_modal_title')">
            <div class="avatar" id="displayUserAvatar"
                @if(auth()->user()->avatar)
                    style="background-image: url('{{ asset('storage/' . auth()->user()->avatar) }}'); background-size: cover; background-position: center;"
                @endif
            >
                @if(!auth()->user()->avatar)
                    {{ mb_substr(auth()->user()->name, 0, 1) }}
                @endif
            </div>
            <div class="user-details">
                <span class="user-name" id="displayUserName">{{ auth()->user()->name }}</span>
                <span class="user-role" id="displayUserRole">{{ auth()->user()->role ?? 'User' }}</span>
            </div>
        </div>
    </div>
</div>

<div class="profile-modal-overlay" id="profileModal">
    <div class="profile-modal-card">
        <div class="profile-modal-header">
            <h3>@lang('layout.edit_profile_modal_title')</h3>
            <button class="icon-btn" style="width: 28px; height: 28px; font-size: 12px;" onclick="closeProfileModal()">&times;</button>
        </div>
        <div class="profile-modal-body">
            <div style="text-align: center; margin-bottom: 16px;">
                <div class="avatar" id="modalPreviewAvatar" style="width: 64px; height: 64px; margin: 0 auto 8px; font-size: 20px;">
                    @if(auth()->user()->avatar)
                        <img src="{{ asset('storage/' . auth()->user()->avatar) }}" alt="User Avatar" style="width: 100%; height: 100%; border-radius: 50%; object-fit: cover;">
                    @else
                        {{ mb_substr(auth()->user()->name, 0, 1) }}
                    @endif
                </div>
                <input type="file" id="avatarInputFile" accept="image/*" style="display: none;" onchange="previewAvatarImage(this)">
                <button type="button" class="modal-btn" style="font-size: 11px; padding: 4px 10px;" onclick="document.getElementById('avatarInputFile').click()">@lang('layout.change_picture_button')</button>
            </div>
            <div class="form-group">
                <label>@lang('layout.full_name_label')</label>
                <input type="text" id="inputName" value="{{ auth()->user()->name }}">
            </div>
            <div class="form-group">
                <label>@lang('layout.job_title_label')</label>
                <input type="text" id="inputRole" value="{{ auth()->user()->role ?? 'User' }}" readonly style="background: #f1f5f9; color: #64748b; cursor: not-allowed;">
            </div>
        </div>
        <div class="profile-modal-footer">
            <button class="modal-btn" onclick="closeProfileModal()">@lang('layout.cancel_button')</button>
            <button class="modal-btn primary" onclick="saveProfileChanges()">@lang('layout.save_changes_button')</button>
        </div>
    </div>
</div>

<div class="avatar-preview-overlay" id="avatarPreviewModal">
    <div class="avatar-preview-card">
        <div class="avatar-preview-head">
            <h3>@lang('layout.picture_preview_title')</h3>
        </div>
        <div class="crop-area">
            <div class="crop-circle" id="cropCircle">
                <img id="cropImage" alt="Crop">
            </div>
            <div class="crop-zoom">
                <button class="modal-btn" onclick="zoomCrop(-0.15)">−</button>
                <span id="cropZoomLabel">100%</span>
                <button class="modal-btn" onclick="zoomCrop(0.15)">+</button>
            </div>
            <p class="crop-hint">@lang('layout.crop_hint')</p>
        </div>
        <div class="avatar-preview-actions">
            <button class="modal-btn" onclick="cancelAvatarPreview()">@lang('layout.cancel_button')</button>
            <button class="modal-btn primary" onclick="confirmAvatarPreview()">@lang('layout.confirm_picture_button')</button>
        </div>
    </div>
</div>

<div class="logout-overlay" id="logoutOverlay">
    <div class="logout-card">
        <div class="spinner"></div>
        <span class="logout-text">@lang('layout.logging_out_message')</span>
    </div>
</div>

<script>
    let temporaryAvatarUrl = "";
    let selectedAvatarFile = null;
    let pendingAvatarUrl = "";

    // تعريف كائن الترجمات الخاصة بالوقت لتعمل داخل الـ JS
    const timeTrans = {
        years: "@lang('time.years_ago')",
        months: "@lang('time.months_ago')",
        days: "@lang('time.days_ago')",
        hours: "@lang('time.hours_ago')",
        minutes: "@lang('time.minutes_ago')",
        seconds: "@lang('time.seconds_ago')"
    };

    function toggleDarkMode() {
        const dark = document.body.classList.toggle('dark-mode');
        document.documentElement.classList.toggle('dark-mode', dark);
        document.documentElement.setAttribute('data-theme', dark ? 'dark' : 'light');
        localStorage.setItem('theme', dark ? 'dark' : 'light');
    }

    window.addEventListener('DOMContentLoaded', () => {
        if (localStorage.getItem('theme') === 'dark') {
            document.body.classList.add('dark-mode');
        }
        // Close notifications dropdown if clicked outside
        document.addEventListener('click', function(event) {
            const notificationBtn = document.getElementById('notificationBtn');
            const notificationsDropdown = document.getElementById('notificationsDropdown');
            if (notificationBtn && notificationsDropdown && !notificationBtn.contains(event.target) && !notificationsDropdown.contains(event.target)) {
                notificationsDropdown.classList.remove('active');
            }
        });
        fetchNotificationCount(); // Fetch notification count on page load
    });

    function openProfileModal() {
        temporaryAvatarUrl = ""; // Clear temporary URL at the start
        selectedAvatarFile = null; // Clear selected file at the start

        const currentName = document.getElementById('displayUserName').innerText;
        const currentRole = document.getElementById('displayUserRole').innerText;
        const currentAvatarElement = document.getElementById('displayUserAvatar'); // The topbar avatar element

        document.getElementById('inputName').value = currentName;
        document.getElementById('inputRole').value = currentRole;

        const modalPreviewAvatar = document.getElementById('modalPreviewAvatar'); // The avatar element inside the modal
        modalPreviewAvatar.innerHTML = ''; // Clear previous content

        let avatarFound = false;

        // 1. Check if it has a background image (set by JS or initial Blade for image avatars)
        const currentAvatarBg = currentAvatarElement.style.backgroundImage;
        if (currentAvatarBg && currentAvatarBg !== 'none' && currentAvatarBg.startsWith('url(')) {
            const imageUrl = currentAvatarBg.slice(5, -2).replace(/['"]/g, ''); // Extract URL and remove quotes
            if (imageUrl) {
                modalPreviewAvatar.innerHTML = `<img src="${imageUrl}" alt="User Avatar" style="width: 100%; height: 100%; border-radius: 50%; object-fit: cover;">`;
                temporaryAvatarUrl = imageUrl; // Store the current image URL
                avatarFound = true;
            }
        }

        // 2. If no background image, check if it has inner text (set by JS or initial Blade for text avatars)
        // This covers cases where the avatar is just the initial letter
        if (!avatarFound && currentAvatarElement.innerText.trim() !== '') {
            modalPreviewAvatar.innerText = currentAvatarElement.innerText.trim();
            avatarFound = true;
        }

        // 3. Fallback if nothing found (should ideally not happen if logic is correct)
        if (!avatarFound) {
            modalPreviewAvatar.innerText = currentName.charAt(0);
        }

        // Reset file input
        document.getElementById('avatarInputFile').value = '';

        document.getElementById('profileModal').classList.add('active');
    }

    function closeProfileModal() {
        document.getElementById('profileModal').classList.remove('active');
        temporaryAvatarUrl = ""; // Clear temporary avatar URL on close
        selectedAvatarFile = null;
        pendingAvatarUrl = "";
        document.getElementById('avatarInputFile').value = '';
    }

    const crop = { scale: 1, ox: 0, oy: 0, imgW: 0, imgH: 0, base: 1, viewport: 220, dragging: false };

    function applyCropTransform() {
        const img = document.getElementById('cropImage');
        const dispW = crop.imgW * crop.base * crop.scale;
        const dispH = crop.imgH * crop.base * crop.scale;
        img.style.width = dispW + 'px';
        img.style.height = dispH + 'px';
        img.style.left = (crop.viewport / 2 - dispW / 2 + crop.ox) + 'px';
        img.style.top = (crop.viewport / 2 - dispH / 2 + crop.oy) + 'px';
        document.getElementById('cropZoomLabel').innerText = Math.round(crop.scale * 100) + '%';
    }

    function clampCrop() {
        const dispW = crop.imgW * crop.base * crop.scale;
        const dispH = crop.imgH * crop.base * crop.scale;
        const maxX = Math.max(0, (dispW - crop.viewport) / 2);
        const maxY = Math.max(0, (dispH - crop.viewport) / 2);
        crop.ox = Math.min(maxX, Math.max(-maxX, crop.ox));
        crop.oy = Math.min(maxY, Math.max(-maxY, crop.oy));
    }

    function zoomCrop(delta) {
        crop.scale = Math.min(4, Math.max(1, crop.scale + delta));
        clampCrop();
        applyCropTransform();
    }

    function initCrop(src) {
        const img = document.getElementById('cropImage');
        img.onload = function () {
            crop.imgW = img.naturalWidth;
            crop.imgH = img.naturalHeight;
            crop.base = crop.viewport / Math.min(crop.imgW, crop.imgH);
            crop.scale = 1;
            crop.ox = 0;
            crop.oy = 0;
            applyCropTransform();
            document.getElementById('avatarPreviewModal').classList.add('active');
        };
        img.src = src;
    }

    function previewAvatarImage(input) {
        if (input.files && input.files[0]) {
            initCrop(URL.createObjectURL(input.files[0]));
        }
    }

    function closeAvatarPreview() {
        document.getElementById('avatarPreviewModal').classList.remove('active');
        URL.revokeObjectURL(document.getElementById('cropImage').src);
    }

    function confirmAvatarPreview() {
        const img = document.getElementById('cropImage');
        if (!crop.imgW) return;
        const OUT = 512;
        const canvas = document.createElement('canvas');
        canvas.width = OUT;
        canvas.height = OUT;
        const ctx = canvas.getContext('2d');
        const dispW = crop.imgW * crop.base * crop.scale;
        const dispH = crop.imgH * crop.base * crop.scale;
        const ratio = OUT / crop.viewport;
        ctx.save();
        ctx.beginPath();
        ctx.arc(OUT / 2, OUT / 2, OUT / 2, 0, Math.PI * 2);
        ctx.clip();
        ctx.drawImage(img, (crop.viewport / 2 - dispW / 2 + crop.ox) * ratio, (crop.viewport / 2 - dispH / 2 + crop.oy) * ratio, dispW * ratio, dispH * ratio);
        ctx.restore();
        canvas.toBlob(function (blob) {
            selectedAvatarFile = new File([blob], 'avatar.jpg', { type: 'image/jpeg' });
            temporaryAvatarUrl = canvas.toDataURL('image/jpeg', 0.92);
            const modalAvatar = document.getElementById('modalPreviewAvatar');
            modalAvatar.innerHTML = `<img src="${temporaryAvatarUrl}" alt="User Avatar" style="width: 100%; height: 100%; border-radius: 50%; object-fit: cover;">`;
            closeAvatarPreview();
        }, 'image/jpeg', 0.92);
    }

    function cancelAvatarPreview() {
        document.getElementById('avatarInputFile').value = '';
        pendingAvatarUrl = "";
        closeAvatarPreview();
    }

    (function () {
        const circle = document.getElementById('cropCircle');
        circle.addEventListener('pointerdown', function (e) {
            crop.dragging = true;
            crop.startX = e.clientX - crop.ox;
            crop.startY = e.clientY - crop.oy;
            circle.setPointerCapture(e.pointerId);
        });
        circle.addEventListener('pointermove', function (e) {
            if (!crop.dragging) return;
            crop.ox = e.clientX - crop.startX;
            crop.oy = e.clientY - crop.startY;
            clampCrop();
            applyCropTransform();
        });
        circle.addEventListener('pointerup', function () { crop.dragging = false; });
        circle.addEventListener('pointercancel', function () { crop.dragging = false; });
        circle.addEventListener('wheel', function (e) {
            e.preventDefault();
            zoomCrop(e.deltaY < 0 ? 0.1 : -0.1);
        }, { passive: false });
    })();

    function saveProfileChanges() {
        const newName = document.getElementById('inputName').value.trim();
        if (!newName) {
            alert("@lang('layout.full_name_label')");
            return;
        }

        const formData = new FormData();
        formData.append('name', newName);
        if (selectedAvatarFile) {
            formData.append('avatar', selectedAvatarFile);
        }

        fetch('{{ route('profile.update.ajax') }}', {
            method: 'POST',
            headers: {
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').getAttribute('content'),
                'Accept': 'application/json'
            },
            body: formData
        })
        .then(async res => {
            if (!res.ok) throw new Error('save failed');
            return res.json();
        })
        .then(data => {
            if (!data.success) throw new Error('save failed');

            const displayAvatar = document.getElementById('displayUserAvatar');
            if (data.avatar) {
                displayAvatar.style.backgroundImage = `url('${data.avatar}')`;
                displayAvatar.innerText = "";
            } else {
                displayAvatar.style.backgroundImage = "";
                displayAvatar.innerText = data.name.charAt(0);
            }

            document.getElementById('displayUserName').innerText = data.name;

            const sidebarDisplayUserName = document.getElementById('sidebarDisplayUserName');
            const sidebarDisplayUserRole = document.getElementById('sidebarDisplayUserRole');
            const sidebarDisplayUserAvatar = document.getElementById('sidebarDisplayUserAvatar');

            if (sidebarDisplayUserName) {
                sidebarDisplayUserName.innerText = data.name;
            }
            if (sidebarDisplayUserRole) {
                sidebarDisplayUserRole.innerText = data.name ? document.getElementById('displayUserRole').innerText : '';
            }
            if (sidebarDisplayUserAvatar) {
                if (data.avatar) {
                    sidebarDisplayUserAvatar.innerHTML = `<img src="${data.avatar}" alt="User Avatar" style="width: 100%; height: 100%; border-radius: 50%; object-fit: cover;">`;
                } else {
                    sidebarDisplayUserAvatar.innerText = data.name.charAt(0);
                }
            }

            alert("@lang('layout.profile_saved')");
            closeProfileModal();
        })
        .catch(() => {
            alert("@lang('layout.profile_save_error')");
        });
    }

    // Notification functions
    function toggleNotificationsDropdown() {
        const dropdown = document.getElementById('notificationsDropdown');
        dropdown.classList.toggle('active');
        if (dropdown.classList.contains('active')) {
            const r = dropdown.getBoundingClientRect();
            const vw = window.innerWidth;
            if (r.right > vw - 8 && r.left > 8) {
                dropdown.style.width = Math.max(180, Math.min(380, vw - 8 - r.left)) + 'px';
            } else {
                dropdown.style.width = '';
            }
            fetchNotifications(); // Fetch notifications when dropdown is opened
        } else {
            dropdown.style.width = '';
        }
    }

    async function fetchNotificationCount() {
        try {
            const response = await fetch('/api/notifications/count');
            const data = await response.json();
            const badge = document.getElementById('notificationBadge');
            if (data.count > 0) {
                badge.innerText = data.count;
                badge.style.display = 'flex'; // Use flex to center text if needed
            } else {
                badge.style.display = 'none';
            }
        } catch (error) {
            console.error('Error fetching notification count:', error);
        }
    }

    async function fetchNotifications() {
        const notificationsList = document.getElementById('notificationsList');
        notificationsList.innerHTML = `<p class="loading-notifications">@lang('layout.loading_notifications')</p>`;

        try {
            const response = await fetch('/api/notifications');
            const data = await response.json();
            renderNotifications(data.notifications);
        } catch (error) {
            console.error('Error fetching notifications:', error);
            notificationsList.innerHTML = `<p class="error-notifications">@lang('layout.error_loading_notifications')</p>`;
        }
    }

    function renderNotifications(notifications) {
        const notificationsList = document.getElementById('notificationsList');
        notificationsList.innerHTML = ''; // Clear loading/previous notifications

        if (notifications.length === 0) {
            notificationsList.innerHTML = `<p class="no-notifications">@lang('layout.no_new_notifications')</p>`;
            return;
        }

        notifications.forEach(notification => {
            const notificationItem = document.createElement('a');
            // Assuming 'link' is a property in your notification object for redirection
            notificationItem.href = notification.link || '#';
            notificationItem.classList.add('notification-item');
            if (!notification.is_read) { // Use is_read from backend
                notificationItem.classList.add('unread');
            }

            // Example of dynamic icon based on notification type
            let iconSvg = '';
            let iconClass = '';
            switch (notification.type) {
                case 'low_stock':
                    iconSvg = '<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"></path><line x1="12" y1="9" x2="12" y2="13"></line><line x1="12" y1="17" x2="12.01" y2="17"></line></svg>';
                    iconClass = 'bg-red';
                    break;
                case 'reminder':
                    iconSvg = '<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"></path><path d="M13.73 21a2 2 0 0 1-3.46 0"></path></svg>';
                    iconClass = 'bg-blue';
                    break;
                default:
                    iconSvg = '<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"></path><path d="M13.73 21a2 2 0 0 1-3.46 0"></path></svg>';
                    iconClass = 'bg-gray';
            }


            notificationItem.innerHTML = `
                <div class="notification-icon ${iconClass}">${iconSvg}</div>
                <div class="notification-content">
                    <p class="notification-title">${notification.message}</p>
                    <p class="notification-time">${formatTimeAgo(notification.created_at)}</p>
                </div>
            `;
            // Mark as read when clicked, then redirect
            notificationItem.addEventListener('click', async (e) => {
                e.preventDefault(); // Prevent default link behavior for now
                await markNotificationAsRead(notification.id);
                // After marking as read, you can redirect or update UI
                if (notification.link) {
                    window.location.href = notification.link;
                } else {
                    // Just refresh notifications or remove the item
                    fetchNotifications();
                }
            });
            notificationsList.appendChild(notificationItem);
        });
    }

    async function markNotificationAsRead(notificationId) {
        try {
            const response = await fetch(`/api/notifications/${notificationId}/read`, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').getAttribute('content')
                }
            });
            if (response.ok) {
                fetchNotificationCount(); // Update count after reading
                fetchNotifications(); // Refresh the list
            }
        } catch (error) {
            console.error('Error marking notification as read:', error);
        }
    }

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
                fetchNotificationCount(); // Update count
                fetchNotifications(); // Refresh the list
            }
        } catch (error) {
            console.error('Error marking all notifications as read:', error);
        }
    }

    // Utility function to format time (Updated to use JS translation object)
    function formatTimeAgo(timestamp) {
        const now = new Date();
        const past = new Date(timestamp);
        const seconds = Math.floor((now - past) / 1000);

        let interval = seconds / 31536000; // years
        if (interval > 1) return Math.floor(interval) + " " + timeTrans.years;
        interval = seconds / 2592000; // months
        if (interval > 1) return Math.floor(interval) + " " + timeTrans.months;
        interval = seconds / 86400; // days
        if (interval > 1) return Math.floor(interval) + " " + timeTrans.days;
        interval = seconds / 3600; // hours
        if (interval > 1) return Math.floor(interval) + " " + timeTrans.hours;
        interval = seconds / 60; // minutes
        if (interval > 1) return Math.floor(interval) + " " + timeTrans.minutes;
        return Math.floor(seconds) + " " + timeTrans.seconds;
    }
</script>
