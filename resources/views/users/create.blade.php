@extends('layouts.app')

@section('title', 'إضافة حساب جديد')

@section('content')
    @vite(['resources/css/pages/users_create.css'])

    <div class="page-wrapper">
        <div class="main-card">

            <div class="card-header-modern">
                <div class="header-title-area">
                    <h2>إضافة حساب جديد</h2>
                    <p>أدخل بيانات المستخدم بدقة لتسجيله وتحديد صلاحياته</p>
                </div>
                <div class="header-icon">👥</div>
            </div>

            <form action="{{ route('users.store') }}" method="POST">
                @csrf

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

                    <div class="form-grid">

                        <div class="form-group">
                            <label for="name">الاسم الكامل <span>*</span></label>
                            <input type="text" name="name" id="name" class="form-control" value="{{ old('name') }}" placeholder="أدخل الاسم الكامل" required>
                        </div>

                        <div class="form-group">
                            <label for="phoneInput">رقم الجوال <span>*</span></label>
                            <input type="text" name="phone" id="phoneInput" class="form-control" value="{{ old('phone') }}" placeholder="059-XXX-XXXX" pattern="05[0-9]{8}" title="النمط المطلوب: 059XXXXXXXX — 10 أرقام تبدأ بـ 05" required style="direction: ltr; text-align: right;">
                        </div>

                        <div class="form-group">
                            <label for="email">البريد الإلكتروني</label>
                            <input type="email" name="email" id="email" class="form-control" value="{{ old('email') }}" placeholder="name@example.com">
                        </div>

                        <div class="form-group">
                            <label for="role">الدور / الصلاحية <span>*</span></label>
                            <select name="role" id="role" class="form-control" required>
                                <option value="" disabled {{ old('role') ? '' : 'selected' }}>اختر الصلاحية</option>
                                <option value="admin" {{ old('role') == 'admin' ? 'selected' : '' }}>مسؤول نظام (أدمن)</option>
                                <option value="pharmacy" {{ old('role') == 'pharmacy' ? 'selected' : '' }}>صيدلية</option>
                                <option value="patient" {{ old('role') == 'patient' ? 'selected' : '' }}>مريض</option>
                            </select>
                        </div>

                        <div class="form-group full-width">
                            <label for="password">كلمة المرور <span>*</span></label>
                            <div class="password-container">
                                <input type="password" name="password" id="password" class="form-control" placeholder="••••••••" required>
                                <button type="button" class="toggle-password" id="togglePasswordBtn" title="إظهار/إخفاء كلمة المرور">
                                    <svg id="eyeIcon" xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                        <path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"></path>
                                        <circle cx="12" cy="12" r="3"></circle>
                                    </svg>
                                </button>
                            </div>
                            <!-- مؤشر وفحص قوة كلمة المرور -->
                            <div class="password-strength-meter">
                                <div class="strength-bar-container">
                                    <div class="strength-bar" id="strengthBar"></div>
                                </div>
                                <span class="strength-text" id="strengthText">الرجاء إدخال كلمة مرور قوية (أحرف، أرقام، ورموز)</span>
                            </div>
                        </div>

                    </div>

                </div>

                <div class="card-footer-modern">
                    <a href="{{ route('users.index') }}" class="btn-cancel">إلغاء الأمر</a>
                    <button type="submit" class="btn-submit">حفظ البيانات</button>
                </div>
            </form>

        </div>
    </div>

    <!-- سكريبت إظهار/إخفاء كلمة المرور وفحص قوة الحماية -->
    <script>
        const togglePasswordBtn = document.getElementById('togglePasswordBtn');
        const passwordInput = document.getElementById('password');
        const eyeIcon = document.getElementById('eyeIcon');
        const strengthBar = document.getElementById('strengthBar');
        const strengthText = document.getElementById('strengthText');

        // تبديل إظهار/إخفاء كلمة المرور
        togglePasswordBtn.addEventListener('click', function () {
            const type = passwordInput.getAttribute('type') === 'password' ? 'text' : 'password';
            passwordInput.setAttribute('type', type);

            if (type === 'text') {
                eyeIcon.innerHTML = `
                    <path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19m-6.72-1.07a3 3 0 1 1-4.24-4.24"></path>
                    <line x1="1" y1="1" x2="23" y2="23"></line>
                `;
            } else {
                eyeIcon.innerHTML = `
                    <path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"></path>
                    <circle cx="12" cy="12" r="3"></circle>
                `;
            }
        });

        // تحويل تلقائي: أي رقم يُلصق بصيغة +970 يتحول إلى 059 مباشرة
        const phoneInput = document.getElementById('phoneInput');
        if (phoneInput) {
            phoneInput.addEventListener('input', function () {
                let v = this.value.replace(/[^\d+]/g, '');
                if (v.startsWith('+970')) {
                    v = v.replace('+970', '0');
                } else if (v.startsWith('970') && v.length >= 12) {
                    v = v.replace('970', '0');
                }
                this.value = v;
            });
        }

        // فحص قوة كلمة المرور وتحديث الأنيميشن
        passwordInput.addEventListener('input', function () {
            const val = passwordInput.value;
            let strength = 0;

            if (val.length === 0) {
                strengthBar.style.width = '0%';
                strengthText.textContent = 'الرجاء إدخال كلمة مرور قوية (أحرف، أرقام، ورموز)';
                strengthText.style.color = '#64748b';
                return;
            }

            // معايير القوة
            if (val.length >= 6) strength += 1;
            if (val.length >= 10) strength += 1;
            if (/[A-Z]/.test(val) && /[a-z]/.test(val)) strength += 1;
            if (/[0-9]/.test(val)) strength += 1;
            if (/[^A-Za-z0-9]/.test(val)) strength += 1; // رموز خاصة

            if (strength <= 2) {
                strengthBar.style.width = '33%';
                strengthBar.style.backgroundColor = '#ef4444'; // أحمر (ضعيفة)
                strengthText.textContent = 'كلمة المرور ضعيفة جداً، عرضة للاختراق!';
                strengthText.style.color = '#ef4444';
            } else if (strength <= 4) {
                strengthBar.style.width = '66%';
                strengthBar.style.backgroundColor = '#f59e0b'; // برتقالي (متوسطة)
                strengthText.textContent = 'كلمة المرور متوسطة، أضف رموزاً أو طولاً إضافياً للحماية';
                strengthText.style.color = '#f59e0b';
            } else {
                strengthBar.style.width = '100%';
                strengthBar.style.backgroundColor = '#10b981'; // أخضر (قوية)
                strengthText.textContent = 'كلمة مرور قوية وآمنة للغاية!';
                strengthText.style.color = '#10b981';
            }
        });
    </script>
@endsection
