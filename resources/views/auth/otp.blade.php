@extends('layouts.auth')

@section('title', 'رمز التحقق — دوائي')

@section('content')
    <div class="lfb ani">
        <a href="{{ route('login') }}" class="back-link">← العودة</a>
        <div class="form-logo">
            <div class="lm">🔑</div>
            <h1>تحقق من هويتك</h1>
            <p class="subt">أدخل الرمز المُرسَل إلى <strong>+970 59 *** 1234</strong></p>
        </div>

        <x-alert />

        <form action="{{ route('otp.verify') }}" method="POST">
            @csrf
            <div class="otp-inputs">
                <input maxlength="1" name="otp[]" required autofocus>
                <input maxlength="1" name="otp[]" required>
                <input maxlength="1" name="otp[]" required>
                <input maxlength="1" name="otp[]" required>
                <input maxlength="1" name="otp[]" required>
                <input maxlength="1" name="otp[]" required>
            </div>
            <button type="submit" class="btn btn-p btn-full">تأكيد الرمز</button>
        </form>
        <div style="text-align:center;margin-top:16px;font-size:.78rem;color:var(--gray-400)">
            لم يصلك الرمز؟ <a href="#" style="color:#0B8FAC;font-weight:600;text-decoration:none;">إعادة الإرسال</a>
        </div>
    </div>
@endsection
