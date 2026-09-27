@extends('layouts.app')

@section('title', 'Medicine Requests')

@section('content')
    <div class="edit-medicine-page-wrapper">
        <div class="page-heading">
            <div>
                <h1>Medicine Requests</h1>
            </div>
        </div>

        <div style="display:flex;gap:8px;margin:16px 0;flex-wrap:wrap;">
            @foreach(['pending' => 'Pending', 'approved' => 'Approved', 'rejected' => 'Rejected'] as $key => $label)
                <a href="{{ route('medicine_requests.index', ['status' => $key]) }}"
                   class="pill-badge status-badge {{ $status === $key ? 'available' : 'out' }}"
                   style="text-decoration:none;border:none;padding:8px 14px;">
                    {{ $label }} ({{ $stats[$key] ?? 0 }})
                </a>
            @endforeach
        </div>

        <div class="premium-card">
            <div class="card-body">
                <table style="width:100%;border-collapse:collapse;font-size:13px;">
                    <thead>
                        <tr style="text-align:right;border-bottom:1px solid #e2e8f0;color:#64748b;">
                            <th style="padding:8px;">Medicine</th>
                            <th>Category</th>
                            <th>Pharmacy</th>
                            <th>Status</th>
                            <th>Created</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($requests as $req)
                            <tr style="border-bottom:1px solid #f1f5f9;">
                                <td style="padding:10px 8px;">
                                    <strong dir="ltr">{{ $req->trade_name }}</strong><br>
                                    <small style="color:#94a3b8;">{{ $req->generic_name ?: '—' }}</small>
                                </td>
                                <td>{{ $req->category?->name_ar ?? '—' }}{{ $req->subcategory ? ' / '.$req->subcategory->name_ar : '' }}</td>
                                <td>{{ $req->pharmacy?->pharmacy_name ?? '—' }}</td>
                                <td><span class="pill-badge status-badge {{ $req->status === 'approved' ? 'available' : ($req->status === 'rejected' ? 'out' : 'low') }}">{{ $req->status }}</span></td>
                                <td>{{ $req->created_at->format('d/m/Y') }}</td>
                                <td>
                                    <a href="{{ route('medicine_requests.show', $req->id) }}" class="action-btn" style="color:#1C72A6;">Open</a>
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="6" style="text-align:center;padding:24px;color:#94a3b8;">No requests found.</td></tr>
                        @endforelse
                    </tbody>
                </table>

                <div style="margin-top:16px;">{{ $requests->links() }}</div>
            </div>
        </div>
    </div>
@endsection
