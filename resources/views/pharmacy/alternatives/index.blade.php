@extends('layouts.app')

@section('title', 'إدارة بدائل الأدوية')

@section('content')
<div class="container-fluid">
    <div class="d-sm-flex align-items-center justify-content-between mb-4">
        <h1 class="h3 mb-0 text-gray-800">إدارة بدائل الأدوية لصيدلية {{ $pharmacy->pharmacy_name }}</h1>
        <a href="{{ route('pharmacy.alternatives.create') }}" class="d-none d-sm-inline-block btn btn-sm btn-primary shadow-sm">
            <i class="fas fa-plus fa-sm text-white-50"></i> إضافة بديل جديد
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
            <h6 class="m-0 font-weight-bold text-primary">قائمة الأدوية وبدائلها</h6>
        </div>
        <div class="card-body">
            <div class="table-responsive">
                <table class="table table-bordered" id="dataTable" width="100%" cellspacing="0">
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>الدواء الأساسي</th>
                            <th>المادة الفعالة</th>
                            <th>البدائل</th>
                            <th>إجراءات</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($pharmacyMedicines as $pharmacyMedicine)
                        <tr>
                            <td>{{ $loop->iteration }}</td>
                            <td>{{ $pharmacyMedicine->medicine->trade_name }}</td>
                            <td>{{ $pharmacyMedicine->medicine->scientific_name }}</td>
                            <td>
                                @forelse ($pharmacyMedicine->medicine->alternatives as $alternative)
                                    <span class="badge badge-info">{{ $alternative->trade_name }}</span>
                                @empty
                                    لا توجد بدائل
                                @endforelse
                            </td>
                            <td>
                                <a href="{{ route('pharmacy.alternatives.create', ['pharmacyMedicine' => $pharmacyMedicine->id]) }}" class="btn btn-success btn-sm">إضافة بديل</a>
                                {{-- Edit and Delete alternatives might be more complex, perhaps a dedicated modal or page --}}
                                {{-- <a href="{{ route('pharmacy.alternatives.edit', $pharmacyMedicine->id) }}" class="btn btn-info btn-sm">تعديل البدائل</a> --}}
                            </td>
                        </tr>
                        @empty
                        <tr>
                            <td colspan="5" class="text-center">لا توجد أدوية في صيدليتك لإدارة بدائلها.</td>
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
