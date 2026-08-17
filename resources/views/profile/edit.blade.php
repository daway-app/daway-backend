@extends('layouts.app')

@section('title', __('layout.profile_title'))

@section('content')
    @vite(['resources/css/pages/users_create.css'])

    <div class="page-wrapper">
        <div class="main-card">

            <div class="card-header-modern">
                <div class="header-title-area">
                    <h2>@lang('layout.profile_title')</h2>
                    <p>@lang('layout.profile_subtitle')</p>
                </div>
                <div class="header-icon"><svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"></path><circle cx="12" cy="7" r="4"></circle></svg></div>
            </div>

            <div class="profile-layout">

                <!-- Column 1: Personal info -->
                <div class="profile-col">

                    <div class="profile-hero">
                        <div class="profile-avatar-ring">
                            <div class="profile-avatar">
                                @if($user->avatar)
                                    <img src="{{ \App\Support\Image::url($user->avatar) }}" alt="Avatar" id="avatarPreview">
                                @else
                                    <span id="avatarInitial">{{ mb_substr($user->name, 0, 1) }}</span>
                                @endif
                            </div>
                        </div>
                        <div class="profile-hero-text">
                            <strong>{{ $user->name }}</strong>
                            @if($user->role === 'admin')
                                <span class="profile-role-badge role-admin">@lang('users.role_admin')</span>
                            @elseif($user->role === 'pharmacy')
                                <span class="profile-role-badge role-pharmacy">@lang('users.role_pharmacy')</span>
                            @else
                                <span class="profile-role-badge role-patient">@lang('users.role_patient')</span>
                            @endif
                        </div>
                    </div>

                    @if (session('success'))
                        <div class="success-alert-modern" style="margin-bottom: 16px;">{{ session('success') }}</div>
                    @endif

                    <form action="{{ route('profile.update') }}" method="POST" enctype="multipart/form-data">
                        @csrf
                        @method('PUT')

                        @if ($errors->any())
                            <div class="alert-danger-modern" style="margin-bottom: 16px;">
                                <ul style="margin: 0; padding-right: 18px;">
                                    @foreach ($errors->all() as $error)
                                        <li>{{ $error }}</li>
                                    @endforeach
                                </ul>
                            </div>
                        @endif

                        <input type="file" name="avatar" id="avatarInput" accept="image/*" style="display: none;" onchange="previewAvatar(this)">

                        <div class="form-group">
                            <label>@lang('layout.full_name_label') <span>*</span></label>
                            <div class="input-with-icon">
                                <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"></path><circle cx="12" cy="7" r="4"></circle></svg>
                                <input type="text" name="name" class="form-control" value="{{ old('name', $user->name) }}" required>
                            </div>
                            @error('name')
                                <span style="color: #dc2626; font-size: 12px;">{{ $message }}</span>
                            @enderror
                        </div>

                        <div class="form-group">
                            <label>@lang('layout.email_label')</label>
                            <div class="readonly-row">
                                <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"></path><polyline points="22,6 12,13 2,6"></polyline></svg>
                                <span>{{ $user->email }}</span>
                                <span class="verified-badge"><svg xmlns="http://www.w3.org/2000/svg" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"></polyline></svg>@lang('layout.email_verified')</span>
                            </div>
                        </div>

                        <div class="form-group">
                            <label>@lang('layout.phone_label')</label>
                            <div class="input-with-icon">
                                <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6 19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72 12.84 12.84 0 0 0 .7 2.81 2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45 12.84 12.84 0 0 0 2.81.7A2 2 0 0 1 22 16.92z"></path></svg>
                                <input type="text" name="phone" class="form-control" value="{{ old('phone', $user->phone ?? '') }}" placeholder="05XXXXXXXX">
                            </div>
                            @error('phone')
                                <span style="color: #dc2626; font-size: 12px;">{{ $message }}</span>
                            @enderror
                        </div>

                        <div class="profile-col-footer">
                            <button type="button" class="btn-submit btn-ghost" onclick="document.getElementById('avatarInput').click()">@lang('layout.change_picture_button')</button>
                            <button type="submit" class="btn-submit">@lang('layout.save_changes_button')</button>
                        </div>
                    </form>
                </div>

                <!-- Column 2: Security -->
                <div class="profile-col">

                    <div class="security-head">
                        <div class="lock-ic"><svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"></rect><path d="M7 11V7a5 5 0 0 1 10 0v4"></path></svg></div>
                        <div>
                            <h3>@lang('layout.password_section_title')</h3>
                            <p>@lang('layout.password_section_subtitle')</p>
                        </div>
                    </div>

                    @if (session('password_success'))
                        <div class="success-alert-modern" style="margin-bottom: 16px;">{{ session('password_success') }}</div>
                    @endif

                    <form action="{{ route('profile.password.update') }}" method="POST">
                        @csrf
                        @method('PUT')

                        <div class="form-group">
                            <label>@lang('layout.current_password_label') <span>*</span></label>
                            <div class="input-with-icon">
                                <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"></rect><path d="M7 11V7a5 5 0 0 1 10 0v4"></path></svg>
                                <input type="password" name="current_password" id="curPass" class="form-control" required autocomplete="current-password">
                                <button type="button" class="eye-toggle" onclick="togglePass('curPass', this)" tabindex="-1">
                                    <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"></path><circle cx="12" cy="12" r="3"></circle></svg>
                                </button>
                            </div>
                            @error('current_password')
                                <span style="color: #dc2626; font-size: 12px;">{{ $message }}</span>
                            @enderror
                        </div>

                        <div class="form-group">
                            <label>@lang('layout.new_password_label') <span>*</span> <small class="hint-inline">@lang('layout.password_min_hint')</small></label>
                            <div class="input-with-icon">
                                <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"></rect><path d="M7 11V7a5 5 0 0 1 10 0v4"></path></svg>
                                <input type="password" name="password" id="newPass" class="form-control" required minlength="8" autocomplete="new-password">
                                <button type="button" class="eye-toggle" onclick="togglePass('newPass', this)" tabindex="-1">
                                    <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"></path><circle cx="12" cy="12" r="3"></circle></svg>
                                </button>
                            </div>
                            @error('password')
                                <span style="color: #dc2626; font-size: 12px;">{{ $message }}</span>
                            @enderror
                        </div>

                        <div class="form-group">
                            <label>@lang('layout.confirm_password_label') <span>*</span></label>
                            <div class="input-with-icon">
                                <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"></rect><path d="M7 11V7a5 5 0 0 1 10 0v4"></path></svg>
                                <input type="password" name="password_confirmation" id="confPass" class="form-control" required autocomplete="new-password">
                                <button type="button" class="eye-toggle" onclick="togglePass('confPass', this)" tabindex="-1">
                                    <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"></path><circle cx="12" cy="12" r="3"></circle></svg>
                                </button>
                            </div>
                        </div>

                        <div class="profile-col-footer">
                            <button type="submit" class="btn-submit">@lang('layout.password_update_button')</button>
                        </div>
                    </form>
                </div>

            </div>

        </div>

    </div>

    <script>
        function togglePass(inputId, btn) {
            const input = document.getElementById(inputId);
            const show = input.type === 'password';
            input.type = show ? 'text' : 'password';
            btn.classList.toggle('eye-active', show);
        }
    </script>

    <style>
        .profile-layout {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 22px;
            padding: 24px;
        }
        @media (max-width: 900px) {
            .profile-layout { grid-template-columns: 1fr; }
        }
        .profile-col {
            background: #f8fafc;
            border: 1px solid #DEE8E7;
            border-radius: 14px;
            padding: 22px;
            display: flex;
            flex-direction: column;
        }
        body.dark-mode .profile-col { background: #18181B; border-color: #333338; }
        .profile-hero {
            display: flex;
            align-items: center;
            gap: 16px;
            height: 88px;
            margin-bottom: 22px;
            border-bottom: 1px solid #E2E8F0;
        }
        body.dark-mode .profile-hero { border-color: #3f3f46; }
        .profile-avatar-ring {
            width: 86px;
            height: 86px;
            border-radius: 50%;
            padding: 3px;
            background: conic-gradient(#0B8FAC, #7BC1B7, #3b82f6, #0B8FAC);
            flex-shrink: 0;
        }
        .profile-avatar {
            width: 100%;
            height: 100%;
            border-radius: 50%;
            background: #36a5a5;
            display: flex;
            align-items: center;
            justify-content: center;
            color: #fff;
            font-size: 32px;
            font-weight: 700;
            overflow: hidden;
            box-shadow: 0 4px 14px rgba(0,0,0,0.18);
        }
        .profile-avatar img { width: 100%; height: 100%; object-fit: cover; display: block; }
        .profile-hero-text { display: flex; flex-direction: column; gap: 6px; min-width: 0; }
        .profile-hero-text strong { font-size: 17px; color: #0f172a; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        body.dark-mode .profile-hero-text strong { color: #f4f4f5; }
        .profile-role-badge {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            font-size: 12px;
            font-weight: 700;
            padding: 4px 12px;
            border-radius: 999px;
            width: fit-content;
        }
        .profile-role-badge.role-admin { background: rgba(59,130,246,0.12); color: #2563eb; }
        .profile-role-badge.role-pharmacy { background: rgba(6,182,212,0.12); color: #0891b2; }
        .profile-role-badge.role-patient { background: rgba(168,85,247,0.12); color: #9333ea; }
        .input-with-icon { position: relative; }
        .input-with-icon > svg:first-child {
            position: absolute;
            inset-inline-start: 12px;
            top: 50%;
            transform: translateY(-50%);
            color: #94a3b8;
            pointer-events: none;
        }
        .input-with-icon .form-control { padding-inline-start: 38px; }
        .input-with-icon .form-control[type="password"] { padding-inline-end: 38px; }
        .readonly-row {
            display: flex;
            align-items: center;
            gap: 10px;
            height: 43px;
            box-sizing: border-box;
            padding: 0 12px;
            background: #f1f5f9;
            border: 1px dashed #cbd5e1;
            border-radius: 10px;
            font-size: 14px;
            color: #334155;
        }
        body.dark-mode .readonly-row { background: #232327; border-color: #3f3f46; color: #e4e4e7; }
        .readonly-row > svg { color: #94a3b8; flex-shrink: 0; }
        .readonly-row > span { overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
        .verified-badge {
            margin-inline-start: auto;
            display: inline-flex;
            align-items: center;
            gap: 4px;
            font-size: 11px;
            font-weight: 700;
            color: #059669;
            background: rgba(16,185,129,0.1);
            padding: 3px 9px;
            border-radius: 999px;
            flex-shrink: 0;
        }
        .eye-toggle {
            position: absolute;
            inset-inline-end: 8px;
            top: 50%;
            transform: translateY(-50%);
            background: none;
            border: none;
            color: #94a3b8;
            cursor: pointer;
            padding: 6px;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: 8px;
            transition: color 0.2s ease, background-color 0.2s ease;
        }
        .eye-toggle:hover { color: #0B8FAC; background: rgba(11,143,172,0.08); }
        .eye-toggle.eye-active { color: #0B8FAC; }
        .profile-col-footer {
            display: flex;
            gap: 10px;
            justify-content: flex-end;
            margin-top: auto;
            padding-top: 18px;
        }
        .btn-ghost {
            background: transparent;
            border: 1px solid #cbd5e1;
            color: #334155;
        }
        body.dark-mode .btn-ghost { border-color: #3f3f46; color: #e4e4e7; }
        .btn-ghost:hover { background: #f1f5f9 !important; }
        .security-head {
            display: flex;
            align-items: center;
            gap: 12px;
            height: 88px;
            margin-bottom: 22px;
            border-bottom: 1px solid #E2E8F0;
        }
        body.dark-mode .security-head { border-color: #3f3f46; }
        .security-head .lock-ic {
            width: 42px;
            height: 42px;
            border-radius: 12px;
            background: rgba(11,143,172,0.1);
            color: #0B8FAC;
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
        }
        .security-head h3 { margin: 0; font-size: 16px; font-weight: 700; color: #0f172a; }
        body.dark-mode .security-head h3 { color: #f4f4f5; }
        .security-head p { margin: 2px 0 0; font-size: 12.5px; color: #64748b; }
        body.dark-mode .security-head p { color: #a1a1aa; }
        .hint-inline { font-weight: 500; font-size: 11px; color: #94a3b8; margin-inline-start: 6px; }
        .avatar-preview-page-overlay {
            position: fixed; top: 0; left: 0; width: 100vw; height: 100vh;
            background: rgba(0, 0, 0, 0.6); backdrop-filter: blur(6px);
            display: flex; align-items: center; justify-content: center;
            z-index: 9999; opacity: 0; visibility: hidden; transition: all 0.3s ease;
        }
        .avatar-preview-page-overlay.active { opacity: 1; visibility: visible; }
        .avatar-preview-page-card {
            background: #fff; width: 100%; max-width: 460px; border-radius: 16px;
            box-shadow: 0 20px 25px -5px rgba(0,0,0,.15); border: 1px solid #DEE8E7;
            overflow: hidden; transform: scale(.9); transition: transform .3s ease;
        }
        body.dark-mode .avatar-preview-page-card { background: #232327; border-color: #333338; }
        .avatar-preview-page-overlay.active .avatar-preview-page-card { transform: scale(1); }
        .avatar-preview-page-head { padding: 14px 20px; border-bottom: 1px solid #DEE8E7; }
        body.dark-mode .avatar-preview-page-head { border-color: #333338; }
        .avatar-preview-page-head h3 { margin: 0; font-size: 15px; }
        .avatar-preview-page-actions {
            padding: 12px 20px; background: #f8fafc; border-top: 1px solid #DEE8E7;
            display: flex; justify-content: space-between; gap: 8px;
        }
        body.dark-mode .avatar-preview-page-actions { background: #18181B; border-color: #333338; }
    </style>

    <div class="avatar-preview-page-overlay" id="avatarPreviewModalPage">
        <div class="avatar-preview-page-card">
            <div class="avatar-preview-page-head">
                <h3>@lang('layout.picture_preview_title')</h3>
            </div>
            <div class="crop-area">
                <div class="crop-circle" id="cropCirclePage" style="width: 240px; height: 240px;">
                    <img id="cropImagePage" alt="Crop">
                </div>
                <div class="crop-zoom">
                    <button type="button" class="modal-btn" onclick="zoomCropPage(-0.15)">âˆ’</button>
                    <span id="cropZoomLabelPage">100%</span>
                    <button type="button" class="modal-btn" onclick="zoomCropPage(0.15)">+</button>
                </div>
                <p class="crop-hint">@lang('layout.crop_hint')</p>
            </div>
            <div class="avatar-preview-page-actions">
                <button type="button" class="btn-cancel" onclick="cancelAvatarPreviewPage()">@lang('layout.cancel_button')</button>
                <button type="button" class="btn-submit" onclick="confirmAvatarPreviewPage()">@lang('layout.confirm_picture_button')</button>
            </div>
        </div>
    </div>

    <script>
        let pendingAvatarUrlPage = "";
        const cropPage = { scale: 1, ox: 0, oy: 0, imgW: 0, imgH: 0, base: 1, viewport: 240, dragging: false };

        function applyCropTransformPage() {
            const img = document.getElementById('cropImagePage');
            const dispW = cropPage.imgW * cropPage.base * cropPage.scale;
            const dispH = cropPage.imgH * cropPage.base * cropPage.scale;
            img.style.width = dispW + 'px';
            img.style.height = dispH + 'px';
            img.style.left = (cropPage.viewport / 2 - dispW / 2 + cropPage.ox) + 'px';
            img.style.top = (cropPage.viewport / 2 - dispH / 2 + cropPage.oy) + 'px';
            document.getElementById('cropZoomLabelPage').innerText = Math.round(cropPage.scale * 100) + '%';
        }

        function clampCropPage() {
            const dispW = cropPage.imgW * cropPage.base * cropPage.scale;
            const dispH = cropPage.imgH * cropPage.base * cropPage.scale;
            const maxX = Math.max(0, (dispW - cropPage.viewport) / 2);
            const maxY = Math.max(0, (dispH - cropPage.viewport) / 2);
            cropPage.ox = Math.min(maxX, Math.max(-maxX, cropPage.ox));
            cropPage.oy = Math.min(maxY, Math.max(-maxY, cropPage.oy));
        }

        function zoomCropPage(delta) {
            cropPage.scale = Math.min(4, Math.max(1, cropPage.scale + delta));
            clampCropPage();
            applyCropTransformPage();
        }

        function previewAvatar(input) {
            if (input.files && input.files[0]) {
                initCropPage(URL.createObjectURL(input.files[0]));
            }
        }

        function initCropPage(src) {
            const img = document.getElementById('cropImagePage');
            img.onload = function () {
                cropPage.imgW = img.naturalWidth;
                cropPage.imgH = img.naturalHeight;
                cropPage.base = cropPage.viewport / Math.min(cropPage.imgW, cropPage.imgH);
                cropPage.scale = 1;
                cropPage.ox = 0;
                cropPage.oy = 0;
                applyCropTransformPage();
                document.getElementById('avatarPreviewModalPage').classList.add('active');
            };
            img.src = src;
        }

        function closeAvatarPreviewPage() {
            document.getElementById('avatarPreviewModalPage').classList.remove('active');
            URL.revokeObjectURL(document.getElementById('cropImagePage').src);
        }

        function confirmAvatarPreviewPage() {
            const img = document.getElementById('cropImagePage');
            if (!cropPage.imgW) return;
            const OUT = 512;
            const canvas = document.createElement('canvas');
            canvas.width = OUT;
            canvas.height = OUT;
            const ctx = canvas.getContext('2d');
            const dispW = cropPage.imgW * cropPage.base * cropPage.scale;
            const dispH = cropPage.imgH * cropPage.base * cropPage.scale;
            const ratio = OUT / cropPage.viewport;
            ctx.save();
            ctx.beginPath();
            ctx.arc(OUT / 2, OUT / 2, OUT / 2, 0, Math.PI * 2);
            ctx.clip();
            ctx.drawImage(img, (cropPage.viewport / 2 - dispW / 2 + cropPage.ox) * ratio, (cropPage.viewport / 2 - dispH / 2 + cropPage.oy) * ratio, dispW * ratio, dispH * ratio);
            ctx.restore();
            canvas.toBlob(function (blob) {
                const file = new File([blob], 'avatar.jpg', { type: 'image/jpeg' });
                const dt = new DataTransfer();
                dt.items.add(file);
                document.getElementById('avatarInput').files = dt.files;
                const preview = document.getElementById('avatarPreview');
                const initial = document.getElementById('avatarInitial');
                const dataUrl = canvas.toDataURL('image/jpeg', 0.92);
                if (preview) {
                    preview.src = dataUrl;
                } else {
                    const circle = initial.closest('div');
                    circle.innerHTML = '<img src="' + dataUrl + '" alt="Avatar" style="width: 100%; height: 100%; object-fit: cover; display: block;">';
                }
                closeAvatarPreviewPage();
            }, 'image/jpeg', 0.92);
        }

        function cancelAvatarPreviewPage() {
            document.getElementById('avatarInput').value = '';
            pendingAvatarUrlPage = "";
            closeAvatarPreviewPage();
        }

        (function () {
            const circle = document.getElementById('cropCirclePage');
            circle.addEventListener('pointerdown', function (e) {
                cropPage.dragging = true;
                cropPage.startX = e.clientX - cropPage.ox;
                cropPage.startY = e.clientY - cropPage.oy;
                circle.setPointerCapture(e.pointerId);
            });
            circle.addEventListener('pointermove', function (e) {
                if (!cropPage.dragging) return;
                cropPage.ox = e.clientX - cropPage.startX;
                cropPage.oy = e.clientY - cropPage.startY;
                clampCropPage();
                applyCropTransformPage();
            });
            circle.addEventListener('pointerup', function () { cropPage.dragging = false; });
            circle.addEventListener('pointercancel', function () { cropPage.dragging = false; });
            circle.addEventListener('wheel', function (e) {
                e.preventDefault();
                zoomCropPage(e.deltaY < 0 ? 0.1 : -0.1);
            }, { passive: false });
        })();
    </script>
@endsection
