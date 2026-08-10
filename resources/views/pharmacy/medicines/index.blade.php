@extends('layouts.app')

@section('title', 'إدارة أدوية الصيدلية')

@section('content')
<div class="container-fluid">
    <div class="d-sm-flex align-items-center justify-content-between mb-4">
        <h1 class="h3 mb-0 text-gray-800">إدارة أدوية صيدلية {{ $pharmacy->pharmacy_name }}</h1>
        <a href="{{ route('pharmacy.medicines.create') }}" class="d-none d-sm-inline-block btn btn-sm btn-primary shadow-sm">
            <i class="fas fa-plus fa-sm text-white-50"></i> إضافة دواء جديد
        </a>
    </div>

    @if (session('success'))
        <div class="alert alert-success">{{ session('success') }}</div>
    @endif
    @if (session('error'))
        <div class="alert alert-danger">{{ session('error') }}</div>
    @endif

    <div class="card shadow mb-4">
        <div class="card-header py-3">
            <h6 class="m-0 font-weight-bold text-primary">قائمة الأدوية</h6>
        </div>
        <div class="card-body">
            <div class="table-responsive">
                <table class="table table-bordered" id="dataTable" width="100%" cellspacing="0">
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>اسم الدواء</th>
                            <th>السعر</th>
                            <th>المخزون</th>
                            <th>الحالة</th>
                            <th>إجراءات</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($pharmacyMedicines as $pharmacyMedicine)
                        <tr>
                            <td>{{ $loop->iteration }}</td>
                            <td>{{ $pharmacyMedicine->medicine->trade_name }}</td>
                            <td>{{ $pharmacyMedicine->price }}</td>
                            <td>{{ $pharmacyMedicine->stock }}</td>
                            <td>
                                @if ($pharmacyMedicine->is_available)
                                    <span class="badge badge-success">متوفر</span>
                                @else
                                    <span class="badge badge-danger">غير متوفر</span>
                                @endif
                            </td>
                            <td>
                                <a href="{{ route('pharmacy.medicines.edit', $pharmacyMedicine->id) }}" class="btn btn-info btn-sm">تعديل</a>
                                <form action="{{ route('pharmacy.medicines.destroy', $pharmacyMedicine->id) }}" method="POST" style="display:inline;">
                                    @csrf
                                    @method('DELETE')
                                    <button type="submit" class="btn btn-danger btn-sm" onclick="return confirm('هل أنت متأكد من حذف هذا الدواء؟')">حذف</button>
                                </form>
                            </td>
                        </tr>
                        @empty
                        <tr>
                            <td colspan="6" class="text-center">لا توجد أدوية في صيدليتك حالياً.</td>
                        </tr>
                        @endforelse
                    </tbody>
                </table>
                {{ $pharmacyMedicines->links() }}
            </div>
        </div>
    </div>
</div>
@endsection
