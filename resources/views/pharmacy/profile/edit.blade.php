@extends('layouts.app')

@section('title', 'تعديل ملف الصيدلية')

@section('content')
<div class="container-fluid">
    <div class="d-sm-flex align-items-center justify-content-between mb-4">
        <h1 class="h3 mb-0 text-gray-800">تعديل ملف صيدلية {{ $pharmacy->pharmacy_name }}</h1>
    </div>

    @if (session('success'))
        <div class="alert alert-success">{{ session('success') }}</div>
    @endif
    @if (session('error'))
        <div class="alert alert-danger">{{ session('error') }}</div>
    @endif

    <div class="card shadow mb-4">
        <div class="card-header py-3">
            <h6 class="m-0 font-weight-bold text-primary">بيانات الصيدلية</h6>
        </div>
        <div class="card-body">
            <form action="{{ route('pharmacy.profile.update') }}" method="POST" enctype="multipart/form-data">
                @csrf
                @method('PUT')

                <div class="form-group">
                    <label for="pharmacy_name">اسم الصيدلية:</label>
                    <input type="text" name="pharmacy_name" id="pharmacy_name" class="form-control @error('pharmacy_name') is-invalid @enderror" value="{{ old('pharmacy_name', $pharmacy->pharmacy_name) }}" required>
                    @error('pharmacy_name')
                        <span class="invalid-feedback" role="alert">
                            <strong>{{ $message }}</strong>
                        </span>
                    @enderror
                </div>

                <div class="form-group">
                    <label for="phone_number">رقم التواصل:</label>
                    <input type="text" name="phone_number" id="phone_number" class="form-control @error('phone_number') is-invalid @enderror" value="{{ old('phone_number', $pharmacy->phone_number) }}" required>
                    @error('phone_number')
                        <span class="invalid-feedback" role="alert">
                            <strong>{{ $message }}</strong>
                        </span>
                    @enderror
                </div>

                <div class="form-group">
                    <label for="address">العنوان:</label>
                    <input type="text" name="address" id="address" class="form-control @error('address') is-invalid @enderror" value="{{ old('address', $pharmacy->address) }}" required>
                    @error('address')
                        <span class="invalid-feedback" role="alert">
                            <strong>{{ $message }}</strong>
                        </span>
                    @enderror
                </div>

                <div class="form-group">
                    <label for="latitude">خط العرض (Latitude):</label>
                    <input type="text" name="latitude" id="latitude" class="form-control @error('latitude') is-invalid @enderror" value="{{ old('latitude', $pharmacy->latitude) }}" required>
                    @error('latitude')
                        <span class="invalid-feedback" role="alert">
                            <strong>{{ $message }}</strong>
                        </span>
                    @enderror
                </div>

                <div class="form-group">
                    <label for="longitude">خط الطول (Longitude):</label>
                    <input type="text" name="longitude" id="longitude" class="form-control @error('longitude') is-invalid @enderror" value="{{ old('longitude', $pharmacy->longitude) }}" required>
                    @error('longitude')
                        <span class="invalid-feedback" role="alert">
                            <strong>{{ $message }}</strong>
                        </span>
                    @enderror
                </div>

                <div class="form-group">
                    <label for="logo">شعار الصيدلية:</label>
                    <input type="file" name="logo" id="logo" class="form-control-file @error('logo') is-invalid @enderror">
                    @if ($pharmacy->logo)
                        <img src="{{ Storage::url($pharmacy->logo) }}" alt="شعار الصيدلية" class="img-thumbnail mt-2" style="max-width: 150px;">
                    @endif
                    @error('logo')
                        <span class="invalid-feedback" role="alert">
                            <strong>{{ $message }}</strong>
                        </span>
                    @enderror
                </div>

                <h5 class="mt-4">ساعات العمل:</h5>
                @foreach ($daysOfWeek as $dayKey => $dayName)
                    <div class="form-group row align-items-center border-bottom pb-2 mb-2">
                        <label class="col-md-2 col-form-label">{{ $dayName }}:</label>
                        <div class="col-md-3">
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" name="hours[{{ $dayKey }}][is_closed]" id="is_closed_{{ $dayKey }}" value="1" {{ old('hours.' . $dayKey . '.is_closed', $pharmacyHours[$dayKey]->is_closed ?? false) ? 'checked' : '' }} onchange="toggleTimeInputs('{{ $dayKey }}')">
                                <label class="form-check-label" for="is_closed_{{ $dayKey }}">مغلق</label>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <label for="open_time_{{ $dayKey }}">من:</label>
                            <input type="time" name="hours[{{ $dayKey }}][open_time]" id="open_time_{{ $dayKey }}" class="form-control @error('hours.' . $dayKey . '.open_time') is-invalid @enderror" value="{{ old('hours.' . $dayKey . '.open_time', $pharmacyHours[$dayKey]->open_time ?? '') }}" {{ old('hours.' . $dayKey . '.is_closed', $pharmacyHours[$dayKey]->is_closed ?? false) ? 'disabled' : '' }}>
                            @error('hours.' . $dayKey . '.open_time')
                                <span class="invalid-feedback" role="alert">
                                    <strong>{{ $message }}</strong>
                                </span>
                            @enderror
                        </div>
                        <div class="col-md-3">
                            <label for="close_time_{{ $dayKey }}">إلى:</label>
                            <input type="time" name="hours[{{ $dayKey }}][close_time]" id="close_time_{{ $dayKey }}" class="form-control @error('hours.' . $dayKey . '.close_time') is-invalid @enderror" value="{{ old('hours.' . $dayKey . '.close_time', $pharmacyHours[$dayKey]->close_time ?? '') }}" {{ old('hours.' . $dayKey . '.is_closed', $pharmacyHours[$dayKey]->is_closed ?? false) ? 'disabled' : '' }}>
                            @error('hours.' . $dayKey . '.close_time')
                                <span class="invalid-feedback" role="alert">
                                    <strong>{{ $message }}</strong>
                                </span>
                            @enderror
                        </div>
                    </div>
                @endforeach

                <button type="submit" class="btn btn-primary mt-4">تحديث الملف الشخصي</button>
            </form>
        </div>
    </div>
</div>

<script>
    function toggleTimeInputs(dayKey) {
        const isClosedCheckbox = document.getElementById(`is_closed_${dayKey}`);
        const openTimeInput = document.getElementById(`open_time_${dayKey}`);
        const closeTimeInput = document.getElementById(`close_time_${dayKey}`);

        if (isClosedCheckbox.checked) {
            openTimeInput.setAttribute('disabled', 'disabled');
            closeTimeInput.setAttribute('disabled', 'disabled');
            openTimeInput.value = ''; // Clear values when closed
            closeTimeInput.value = '';
        } else {
            openTimeInput.removeAttribute('disabled');
            closeTimeInput.removeAttribute('disabled');
        }
    }

    // Initialize on page load
    document.addEventListener('DOMContentLoaded', function() {
        @foreach ($daysOfWeek as $dayKey => $dayName)
            toggleTimeInputs('{{ $dayKey }}');
        @endforeach
    });
</script>
@endsection
