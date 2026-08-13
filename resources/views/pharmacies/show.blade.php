@extends('layouts.app')

@section('title', __('pharmacies.pharmacy_details_title') . ' — ' . $pharmacy->pharmacy_name)

@section('breadcrumb')
    <div class="breadcrumb-nav">
        <a href="{{ route('pharmacies.index') }}">@lang('pharmacies.breadcrumb_current')</a>
        <span class="sep">›</span>
        <span class="cur">{{ $pharmacy->pharmacy_name }}</span>
    </div>
@endsection

@section('content')
    @vite(['resources/css/pages/statistics.css'])

    <!-- رأس الصفحة ومعلومات الصيدلية -->
    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 24px; flex-wrap: wrap; gap: 16px;">
        <div style="display: flex; align-items: center; gap: 16px;">
            <div class="t-av ph" style="width: 56px; height: 56px; font-size: 1.5rem;">{{ mb_substr($pharmacy->pharmacy_name, 0, 1) }}</div>
            <div>
                <h2 style="font-size: 1.25rem; font-weight: 800; color: #0f172a; margin: 0 0 6px 0;">{{ $pharmacy->pharmacy_name }}</h2>
                <div style="display: flex; gap: 10px; align-items:center;">
                    <span class="pid">{{ $pharmacy->pharmacy_custom_id }} <button onclick="copyId(this,'{{ $pharmacy->pharmacy_custom_id }}')" title="@lang('pharmacies.copy_tooltip')">⎘</button></span>
                    <span class="bdg {{ $pharmacy->is_active ? 'bdg-ok' : 'bdg-err' }}">● {{ $pharmacy->is_active ? __('pharmacies.status_active') : __('pharmacies.status_disabled') }}</span>
                </div>
            </div>
        </div>

        <div class="action-btn-group">
            <a href="{{ route('pharmacies.edit', $pharmacy->id) }}" class="btn-secondary-pro">✏️ @lang('pharmacies.action_edit')</a>
            <form action="{{ route('pharmacies.destroy', $pharmacy->id) }}" method="POST" onsubmit="return confirm('{{ __('pharmacies.delete_confirm') }}');" style="display: inline;">
                @csrf
                @method('DELETE')
                <button type="submit" class="btn-danger-pro">🗑️ @lang('pharmacies.delete_tooltip')</button>
            </form>
        </div>
    </div>

    <!-- المحتوى الرئيسي (شبكة 7 إلى 5) -->
    <div class="col-7-5">
        <!-- الجانب الأيمن: المعلومات والمخزون -->
        <div style="display: flex; flex-direction: column; gap: 20px;">

            <!-- البطاقة الأولى: معلومات التواصل والموقع -->
            <div class="form-card" style="margin-bottom: 0;">
                <div class="form-card-header">
                    <h2>📍 @lang('pharmacies.pharmacy_info')</h2>
                </div>
                <div class="form-card-body" style="padding: 24px;">
                    <div style="display: grid; grid-template-columns: repeat(2, 1fr); gap: 20px;">
                        <div>
                            <div style="font-size: 12px; color: #64748b; margin-bottom: 4px;">@lang('pharmacies.pharmacy_address_display')</div>
                            <div style="font-weight: 700; color: #0f172a;">{{ $pharmacy->address }}</div>
                        </div>
                        <div>
                            <div style="font-size: 12px; color: #64748b; margin-bottom: 4px;">@lang('pharmacies.pharmacy_phone_display')</div>
                            <div style="font-weight: 700; color: #0f172a;">{{ $pharmacy->phone_number }}</div>
                        </div>
                        <div>
                            <div style="font-size: 12px; color: #64748b; margin-bottom: 4px;">@lang('pharmacies.owner_email')</div>
                            <div style="font-weight: 700; color: #0f172a;">{{ $pharmacy->user->email ?? 'N/A' }}</div>
                        </div>
                        <div>
                            <div style="font-size: 12px; color: #64748b; margin-bottom: 4px;">@lang('pharmacies.col_last_activity')</div>
                            <div style="font-weight: 700; color: #0f172a;">{{ $pharmacy->updated_at->diffForHumans() }}</div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- البطاقة الثانية: مخزون الأدوية للصيدلية -->
            <div class="table-card">
                <div style="padding: 20px 24px; border-bottom: 1px solid #e2e8f0; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 12px;">
                    <h3 style="font-size: 16px; font-weight: 800; color: #0f172a; margin: 0;">💊 @lang('pharmacies.medicines_in_stock') ({{ $pharmacy->pharmacyMedicines->count() }})</h3>
                    <div class="search-input-wrapper" style="max-width: 200px; flex: unset;">
                        <input type="text" placeholder="@lang('pharmacies.search_placeholder')" style="padding: 8px 12px; font-size: 12px;">
                    </div>
                </div>

                <table>
                    <thead>
                    <tr>
                        <th style="padding: 12px 20px;">@lang('pharmacies.medicine_name_col')</th>
                        <th style="padding: 12px 20px;">@lang('pharmacies.price_col')</th>
                        <th style="padding: 12px 20px;">@lang('pharmacies.quantity_col')</th>
                        <th style="padding: 12px 20px;">@lang('pharmacies.col_status')</th>
                    </tr>
                    </thead>
                    <tbody>
                    @forelse($pharmacy->pharmacyMedicines as $pharmacyMedicine)
                    <tr>
                        <td style="padding: 14px 20px;">
                            <strong style="color: #0f172a; font-size: 14px; display: block;">{{ $pharmacyMedicine->medicine->trade_name }}</strong>
                            <span style="font-size: 12px; color: #64748b;">{{ $pharmacyMedicine->medicine->active_ingredient }}</span>
                        </td>
                        <td style="padding: 14px 20px; font-weight: 700; color: #0B8FAC;">{{ $pharmacyMedicine->price }} ₪</td>
                        <td style="padding: 14px 20px; font-weight: 600; color: #334155;">{{ $pharmacyMedicine->quantity }} @lang('medicines.unit')</td>
                        <td style="padding: 14px 20px;">
                            @if($pharmacyMedicine->quantity > 10)
                                <span class="bdg bdg-ok">@lang('medicines.available_status')</span>
                            @elseif($pharmacyMedicine->quantity > 0)
                                <span class="bdg bdg-warn">@lang('medicines.low_stock')</span>
                            @else
                                <span class="bdg bdg-err">@lang('medicines.out_of_stock')</span>
                            @endif
                        </td>
                    </tr>
                    @empty
                    <tr>
                        <td colspan="4" style="text-align: center; padding: 20px;">@lang('pharmacies.no_medicines_in_stock')</td>
                    </tr>
                    @endforelse
                    </tbody>
                </table>
            </div>

        </div>

        <!-- الجانب الأيسر: الإحصائيات السريعة -->
        <div>
            <div class="form-card" style="margin-bottom: 0;">
                <div class="form-card-header">
                    <h2>📊 @lang('pharmacies.pharmacy_stats')</h2>
                </div>
                <div class="form-card-body" style="padding: 24px; display: flex; flex-direction: column; gap: 20px;">
                    <div>
                        <div style="font-size: 12px; color: #64748b; margin-bottom: 4px;">@lang('pharmacies.searches_this_month')</div>
                        <div style="font-size: 1.6rem; font-weight: 800; color: #0B8FAC;">1,204</div>
                    </div>
                    <hr style="border: none; border-top: 1px solid #f1f5f9; margin: 0;">
                    <div>
                        <div style="font-size: 12px; color: #64748b; margin-bottom: 4px;">@lang('pharmacies.availability_rate')</div>
                        <div style="font-size: 1.6rem; font-weight: 800; color: #10b981;">94%</div>
                    </div>
                </div>
            </div>
        </div>
    </div>
@endsection
<script>
    function copyId(button, id) {
        navigator.clipboard.writeText(id).then(function() {
            const originalText = button.innerHTML;
            button.innerHTML = '✓';
            setTimeout(() => {
                button.innerHTML = originalText;
            }, 1000);
        }).catch(function(err) {
            console.error('Could not copy text: ', err);
        });
    }
</script>
