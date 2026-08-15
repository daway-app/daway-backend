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

            @if (session('success'))
                <div class="success-alert-modern" style="margin: 16px 24px 0;">{{ session('success') }}</div>
            @endif

            <form action="{{ route('profile.update') }}" method="POST" enctype="multipart/form-data">
                @csrf
                @method('PUT')

                <div class="card-body-modern">

                    @if ($errors->any())
                        <div class="alert-danger-modern">
                            <ul style="margin: 0; padding-right: 18px;">
                                @foreach ($errors->all() as $error)
                                    <li>{{ $error }}</li>
                                @endforeach
                            </ul>
                        </div>
                    @endif

                    <div style="display: flex; flex-direction: column; align-items: center; gap: 10px; margin-bottom: 24px;">
                        <div style="width: 92px; height: 92px; border-radius: 50%; background: #36a5a5; display: flex; align-items: center; justify-content: center; font-size: 30px; font-weight: 700; color: #fff; overflow: hidden; box-shadow: 0 4px 14px rgba(0,0,0,0.18);">
                            @if($user->avatar)
                                <img src="{{ asset('uploads/' . $user->avatar) }}" alt="Avatar" id="avatarPreview" style="width: 100%; height: 100%; object-fit: cover; display: block;">
                            @else
                                <span id="avatarInitial">{{ mb_substr($user->name, 0, 1) }}</span>
                            @endif
                        </div>
                        <input type="file" name="avatar" id="avatarInput" accept="image/*" style="display: none;" onchange="previewAvatar(this)">
                        <button type="button" class="btn-submit" style="padding: 8px 18px; font-size: 13px;" onclick="document.getElementById('avatarInput').click()">@lang('layout.change_picture_button')</button>
                    </div>

                    <div class="form-grid">

                        <div class="form-group">
                            <label>@lang('layout.full_name_label') <span>*</span></label>
                            <input type="text" name="name" class="form-control" value="{{ old('name', $user->name) }}" required>
                            @error('name')
                                <span style="color: #dc2626; font-size: 12px;">{{ $message }}</span>
                            @enderror
                        </div>

                        <div class="form-group">
                            <label>@lang('layout.email_label')</label>
                            <input type="email" class="form-control" value="{{ $user->email }}" disabled style="background: #f1f5f9; color: #64748b; cursor: not-allowed;">
                        </div>

                        <div class="form-group">
                            <label>@lang('layout.job_title_label')</label>
                            <input type="text" class="form-control" value="{{ $user->role ?? 'User' }}" disabled style="background: #f1f5f9; color: #64748b; cursor: not-allowed;">
                        </div>

                        <div class="form-group">
                            <label>@lang('layout.phone_label')</label>
                            <input type="text" class="form-control" value="{{ $user->phone ?? 'â€”' }}" disabled style="background: #f1f5f9; color: #64748b; cursor: not-allowed;">
                        </div>

                    </div>

                </div>

                <div class="card-footer-modern">
                    <a href="{{ route('dashboard') }}" class="btn-cancel">@lang('layout.cancel_button')</a>
                    <button type="submit" class="btn-submit">@lang('layout.save_changes_button')</button>
                </div>
            </form>

        </div>
    </div>

    <style>
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
