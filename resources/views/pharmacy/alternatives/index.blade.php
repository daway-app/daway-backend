@extends('layouts.app')

@section('title', __('pharmacy.alternatives.index.title'))

@section('content')
<div class="container-fluid">
    <div class="d-sm-flex align-items-center justify-content-between mb-4">
        <h1 class="h3 mb-0 text-gray-800">@lang('pharmacy.alternatives.index.heading', ['pharmacy' => $pharmacy->pharmacy_name])</h1>
        <a href="{{ route('pharmacy.alternatives.create') }}" class="d-none d-sm-inline-block btn btn-sm btn-primary shadow-sm">
            <i class="fas fa-plus fa-sm text-white-50"></i> @lang('pharmacy.alternatives.index.add_button')
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
            <h6 class="m-0 font-weight-bold text-primary">@lang('pharmacy.alternatives.index.card_title')</h6>
        </div>
        <div class="card-body">
            <div class="table-responsive">
                <table class="table table-bordered" id="dataTable" width="100%" cellspacing="0">
                    <thead>
                        <tr>
                            <th>@lang('pharmacy.alternatives.index.col_num')</th>
                            <th>@lang('pharmacy.alternatives.index.col_medicine')</th>
                            <th>@lang('pharmacy.alternatives.index.col_ingredient')</th>
                            <th>@lang('pharmacy.alternatives.index.col_alternatives')</th>
                            <th>@lang('pharmacy.alternatives.index.col_actions')</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($pharmacyMedicines as $pharmacyMedicine)
                        <tr>
                            <td>{{ $loop->iteration }}</td>
                            <td>{{ $pharmacyMedicine->medicine->trade_name }}</td>
                            <td>{{ $pharmacyMedicine->medicine->active_ingredient }}</td>
                            <td>
                                @forelse ($pharmacyMedicine->medicine->alternatives as $alternative)
                                    <span class="badge badge-info">{{ $alternative->trade_name }}</span>
                                @empty
                                    @lang('pharmacy.alternatives.index.no_alternatives')
                                @endforelse
                            </td>
                            <td>
                                <a href="{{ route('pharmacy.alternatives.create', ['pharmacyMedicine' => $pharmacyMedicine->id]) }}" class="btn btn-success btn-sm">@lang('pharmacy.alternatives.index.add_alternative')</a>
                            </td>
                        </tr>
                        @empty
                        <tr>
                            <td colspan="5" class="text-center">@lang('pharmacy.alternatives.index.empty')</td>
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
