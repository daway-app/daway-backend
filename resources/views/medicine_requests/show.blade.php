@extends('layouts.app')

@section('title', 'Medicine Request')

@section('content')
    <div class="edit-medicine-page-wrapper">
        <div class="page-heading">
            <div>
                <h1>Request #{{ $medicineRequest->id }}</h1>
                <p>Status: <strong>{{ $medicineRequest->status }}</strong></p>
            </div>
        </div>

        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(300px,1fr));gap:16px;margin:16px 0;">
            <div class="premium-card">
                <div class="card-head"><div class="card-head-content"><h2>Medicine</h2></div></div>
                <div class="card-body" style="font-size:13px;line-height:2;">
                    <div><strong>Name:</strong> <span dir="ltr">{{ $medicineRequest->trade_name }}</span></div>
                    @if($medicineRequest->trade_name_ar)<div><strong>Name AR:</strong> {{ $medicineRequest->trade_name_ar }}</div>@endif
                    <div><strong>Generic:</strong> {{ $medicineRequest->generic_name ?: '—' }}</div>
                    <div><strong>Manufacturer:</strong> {{ $medicineRequest->manufacturer ?: '—' }}</div>
                    <div><strong>Active Ingredient:</strong> {{ $medicineRequest->active_ingredient ?: '—' }}</div>
                    <div><strong>Dosage Form:</strong> {{ $medicineRequest->dosage_form ?: '—' }}</div>
                    <div><strong>Barcode:</strong> <span dir="ltr">{{ $medicineRequest->barcode ?: '—' }}</span></div>
                </div>
            </div>

            <div class="premium-card">
                <div class="card-head"><div class="card-head-content"><h2>Requester</h2></div></div>
                <div class="card-body" style="font-size:13px;line-height:2;">
                    <div><strong>Pharmacy:</strong> {{ $medicineRequest->pharmacy?->pharmacy_name ?? '—' }}</div>
                    <div><strong>Category:</strong> {{ $medicineRequest->category?->name_ar ?? '—' }}</div>
                    <div><strong>Subcategory:</strong> {{ $medicineRequest->subcategory?->name_ar ?? '—' }}</div>
                    <div><strong>Created:</strong> {{ $medicineRequest->created_at?->format('d/m/Y H:i') ?? '—' }}</div>
                    @if($medicineRequest->reviewed_at)<div><strong>Reviewed:</strong> {{ $medicineRequest->reviewed_at->format('d/m/Y H:i') }}</div>@endif
                </div>
            </div>
        </div>

        @if($medicineRequest->status === 'pending')
            <div class="premium-card" style="margin-top:16px;">
                <div class="card-head"><div class="card-head-content"><h2>Review</h2></div></div>
                <div class="card-body">
                    <form action="{{ route('medicine_requests.approve', $medicineRequest->id) }}" method="POST" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:12px;">
                        @csrf
                        <div><label style="font-size:12px;color:#64748b;">Name (edit)</label><input type="text" name="trade_name" value="{{ old('trade_name', $medicineRequest->trade_name) }}" dir="ltr" style="width:100%;padding:8px;border:1px solid #cbd5e1;border-radius:8px;margin-top:4px;"></div>
                        <div><label style="font-size:12px;color:#64748b;">Ingredient (edit)</label><input type="text" name="active_ingredient" value="{{ old('active_ingredient', $medicineRequest->active_ingredient) }}" style="width:100%;padding:8px;border:1px solid #cbd5e1;border-radius:8px;margin-top:4px;"></div>
                        <div style="display:flex;align-items:flex-end;gap:8px;">
                            <button type="submit" class="btn-submit" style="background:#10b981;border-color:#10b981;">Approve</button>
                        </div>
                    </form>

                    <hr style="margin:20px 0;border:none;border-top:1px solid #e2e8f0;">

                    <form action="{{ route('medicine_requests.reject', $medicineRequest->id) }}" method="POST">
                        @csrf
                        <div style="margin-bottom:12px;">
                            <label style="font-size:12px;color:#64748b;">Rejection reason</label>
                            <textarea name="admin_notes" rows="3" required style="width:100%;padding:8px;border:1px solid #cbd5e1;border-radius:8px;margin-top:4px;box-sizing:border-box;">{{ old('admin_notes') }}</textarea>
                        </div>
                        <button type="submit" class="btn-submit" style="background:#ef4444;border-color:#ef4444;">Reject</button>
                    </form>
                </div>
            </div>
        @endif
    </div>
@endsection
