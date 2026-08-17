@extends('layouts.app')

@section('title', 'تعديل بيانات المستخدم — دوائي')

@section('content')
    <div style="width: 100%; display: flex; justify-content: center; padding: 30px 20px;" dir="rtl">
        <div style="width: 100%; max-width: 650px; background: #ffffff; border-radius: 16px; box-shadow: 0 10px 25px rgba(0,0,0,0.08); border: 1px solid #e2e8f0; overflow: hidden; text-align: right;">

            <form action="{{ route('users.update', $user->id) }}" method="POST">
                @csrf
                @method('PUT')

                <!-- الهيدر -->
                <div style="padding: 20px 25px; background: #f8fafc; border-bottom: 1px solid #e2e8f0; display: flex; align-items: center; gap: 14px;">
                    @if($user->avatar)
                        <img src="{{ \App\Support\Image::url($user->avatar) }}" alt="{{ $user->name }}" style="width: 48px; height: 48px; border-radius: 50%; object-fit: cover; border: 2px solid #e2e8f0;">
                    @else
                        <div style="width: 48px; height: 48px; border-radius: 50%; background: #f3e8ff; color: #7e22ce; display: flex; align-items: center; justify-content: center; font-weight: 700; font-size: 16px;">{{ mb_substr($user->name, 0, 2) }}</div>
                    @endif
                    <h2 style="margin: 0; font-size: 18px; color: #1e293b; font-weight: 700;">⚙️ تعديل بيانات المستخدم</h2>
                </div>

                <!-- الجسم -->
                <div style="padding: 25px;">
                    <!-- الاسم -->
                    <div style="margin-bottom: 20px;">
                        <label style="display: block; font-size: 13px; font-weight: 700; color: #475569; margin-bottom: 8px;">الاسم الكامل</label>
                        <input type="text" name="name" value="{{ $user->name }}" required style="width: 100%; padding: 12px 16px; border: 1.5px solid #cbd5e1; border-radius: 10px; font-size: 14px; outline: none; background: #fff; box-sizing: border-box;">
                    </div>

                    <!-- البريد الإلكتروني -->
                    <div style="margin-bottom: 20px;">
                        <label style="display: block; font-size: 13px; font-weight: 700; color: #475569; margin-bottom: 8px;">البريد الإلكتروني</label>
                        <input type="email" name="email" value="{{ $user->email }}" style="width: 100%; padding: 12px 16px; border: 1.5px solid #cbd5e1; border-radius: 10px; font-size: 14px; outline: none; background: #fff; box-sizing: border-box;">
                    </div>

                    <!-- الجوال -->
                    <div style="margin-bottom: 20px;">
                        <label style="display: block; font-size: 13px; font-weight: 700; color: #475569; margin-bottom: 8px;">رقم الجوال</label>
                        <input type="tel" name="phone" value="{{ $user->phone }}" required dir="ltr" style="text-align: right; width: 100%; padding: 12px 16px; border: 1.5px solid #cbd5e1; border-radius: 10px; font-size: 14px; outline: none; background: #fff; box-sizing: border-box;">
                    </div>

                    <!-- الدور والحالة (صف واحد) -->
                    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px; margin-bottom: 10px;">
                        <div>
                            <label style="display: block; font-size: 13px; font-weight: 700; color: #475569; margin-bottom: 8px;">الدور / الصلاحية</label>
                            <select name="role" required style="width: 100%; padding: 12px 16px; border: 1.5px solid #cbd5e1; border-radius: 10px; font-size: 14px; outline: none; background: #fff; box-sizing: border-box;">
                                <option value="admin" {{ $user->role == 'admin' ? 'selected' : '' }}>🛡️ مسؤول</option>
                                <option value="pharmacy" {{ $user->role == 'pharmacy' ? 'selected' : '' }}>🏥 صيدلية</option>
                                <option value="patient" {{ $user->role == 'patient' ? 'selected' : '' }}>👤 مريض</option>
                            </select>
                        </div>

                        <div>
                            <label style="display: block; font-size: 13px; font-weight: 700; color: #475569; margin-bottom: 8px;">حالة الحساب</label>
                            <select name="status" style="width: 100%; padding: 12px 16px; border: 1.5px solid #cbd5e1; border-radius: 10px; font-size: 14px; outline: none; background: #fff; box-sizing: border-box;">
                                <option value="1" {{ $user->is_active ? 'selected' : '' }}>✅ مفعّل</option>
                                <option value="0" {{ !$user->is_active ? 'selected' : '' }}>❌ معطّل</option>
                            </select>
                        </div>
                    </div>
                </div>

                <!-- الفوتر والأزرار -->
                <div style="display: flex; gap: 12px; justify-content: flex-end; padding: 18px 25px; background: #f8fafc; border-top: 1px solid #e2e8f0;">
                    <a href="{{ route('users.index') }}" style="padding: 10px 20px; border-radius: 10px; font-size: 14px; font-weight: 700; text-decoration: none; background: #f1f5f9; color: #475569; border: 1px solid #cbd5e1;">إلغاء</a>
                    <button type="submit" style="padding: 10px 22px; border-radius: 10px; font-size: 14px; font-weight: 700; cursor: pointer; background: #0B8FAC; color: white; border: none;">حفظ التعديلات</button>
                </div>

            </form>
        </div>
    </div>
@endsection
