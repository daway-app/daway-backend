@extends('layouts.app')

@section('title', __('pharmacy.medicines.create.title'))

@section('content')
    @vite(['resources/css/pages/medicines_edit.css', 'resources/css/pages/pharmacy_medicine_create.css'])
    <div class="edit-medicine-page-wrapper">
        <div class="page-heading">
            <div class="page-heading-icon">
                <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="12" y1="5" x2="12" y2="19"></line><line x1="5" y1="12" x2="19" y2="12"></line></svg>
            </div>
            <div>
                <h1>@lang('pharmacy.medicines.create.heading', ['pharmacy' => $pharmacy->pharmacy_name])</h1>
                <p>@lang('pharmacy.medicines.create.subtitle')</p>
            </div>
        </div>

        <form action="{{ route('pharmacy.medicines.store') }}" method="POST">
            @csrf

            @if(isset($catalogEmpty) && $catalogEmpty)
                <div class="alert alert-error" style="margin-bottom:18px;">
                    <span class="alert-icon">!</span>
                    <div>
                        <div class="alert-title">@lang('pharmacy.medicines.create.catalog_empty_title')</div>
                        <div style="font-size:.85rem;">@lang('pharmacy.medicines.create.catalog_empty_hint')</div>
                    </div>
                </div>
            @endif

            <div class="premium-card">
                <div class="card-head">
                    <div class="card-head-content">
                        <div class="card-icon teal">
                            <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"></path><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"></path></svg>
                        </div>
                        <div>
                            <h2>@lang('pharmacy.medicines.create.choose_medicine')</h2>
                        </div>
                    </div>
                </div>
                <div class="card-body">
                    <div class="fg">
                        <label class="fl" for="medicine_search">@lang('pharmacy.medicines.create.search_label') <span class="req">*</span></label>
                        <input class="fc" type="text" id="medicine_search" autocomplete="off" placeholder="@lang('pharmacy.medicines.create.search_placeholder')" />
                        <div id="search_results" class="moh-search-results" style="display:none;"></div>
                        <small class="fl-hint">@lang('pharmacy.medicines.create.search_hint')</small>
                    </div>

                    <input type="hidden" name="medicine_id" id="medicine_id" value="{{ old('medicine_id') }}">
                    <input type="hidden" name="moh_medicine_id" id="moh_medicine_id" value="{{ old('moh_medicine_id') }}">

                    <div id="selected_box" class="moh-selected" style="display:none;">
                        <div class="moh-selected-info">
                            <strong id="selected_name"></strong>
                            <span id="selected_sub"></span>
                            <span id="selected_price" class="moh-official-price" style="display:none;"></span>
                        </div>
                        <button type="button" id="clear_selection" class="btn-cancel">@lang('pharmacy.medicines.create.change_button')</button>
                    </div>

                    <div id="manual_box" style="display:none;">
                        <div class="form-row">
                            <div class="fg">
                                <label class="fl" for="trade_name">@lang('pharmacy.medicines.create.manual_trade_name') <span class="req">*</span></label>
                                <input class="fc" type="text" id="trade_name" name="trade_name" value="{{ old('trade_name') }}" placeholder="@lang('pharmacy.medicines.create.manual_trade_name_placeholder')">
                            </div>
                            <div class="fg">
                                <label class="fl" for="active_ingredient">@lang('pharmacy.medicines.create.manual_ingredient') <span class="req">*</span></label>
                                <input class="fc" type="text" id="active_ingredient" name="active_ingredient" value="{{ old('active_ingredient') }}" placeholder="@lang('pharmacy.medicines.create.manual_ingredient_placeholder')">
                            </div>
                        </div>
                        <div class="fg" style="margin-top:14px;">
                            <label class="fl" for="image">@lang('pharmacy.medicines.create.image_label')</label>
                            <input class="fc" type="file" id="image" name="image" accept="image/*" style="height:auto;padding:10px;">
                        </div>
                    </div>

                    <div class="fg" style="margin-top:12px;">
                        <button type="button" id="toggle_manual" class="btn-cancel">@lang('pharmacy.medicines.create.not_found_button')</button>
                    </div>

                    @error('medicine_id')
                        <span class="error-text" role="alert"><strong>{{ $message }}</strong></span>
                    @enderror
                </div>
            </div>

            <div class="premium-card">
                <div class="card-head">
                    <div class="card-head-content">
                        <div class="card-icon purple">
                            <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"></circle><polyline points="12 6 12 12 16 14"></polyline></svg>
                        </div>
                        <div>
                            <h2>@lang('pharmacy.medicines.create.price_and_stock')</h2>
                        </div>
                    </div>
                </div>
                <div class="card-body">
                    <div class="form-row">
                        <div class="fg">
                            <label class="fl" for="price">@lang('pharmacy.medicines.create.price_label') <span class="req">*</span></label>
                            <input class="fc" type="number" id="price" name="price" step="0.01" min="0" value="{{ old('price') }}" required>
                            @error('price')
                                <span class="error-text" role="alert"><strong>{{ $message }}</strong></span>
                            @enderror
                        </div>
                        <div class="fg">
                            <label class="fl" for="quantity">@lang('pharmacy.medicines.create.quantity_label') <span class="req">*</span></label>
                            <input class="fc" type="number" id="quantity" name="quantity" min="0" value="{{ old('quantity') }}" required>
                            @error('quantity')
                                <span class="error-text" role="alert"><strong>{{ $message }}</strong></span>
                            @enderror
                        </div>
                        <div class="fg">
                            <label class="fl" for="min_stock">@lang('pharmacy.medicines.create.min_stock_label')</label>
                            <input class="fc" type="number" id="min_stock" name="min_stock" min="0" value="{{ old('min_stock', 10) }}">
                            <small class="fl-hint">@lang('pharmacy.medicines.create.min_stock_hint')</small>
                        </div>
                    </div>
                    <div class="fg" style="margin-top:14px;">
                        <label class="fl-check">
                            <input type="checkbox" name="is_available" id="is_available" value="1" {{ old('is_available', true) ? 'checked' : '' }}>
                            @lang('pharmacy.medicines.create.available_now')
                        </label>
                    </div>
                </div>
            </div>

            <div class="form-actions">
                <button type="submit" class="btn-submit">@lang('pharmacy.medicines.create.add_button')</button>
                <a href="{{ route('pharmacy.medicines.index') }}" class="btn-cancel">@lang('pharmacy.medicines.create.cancel_button')</a>
            </div>
        </form>
    </div>
@endsection

@section('scripts')
@php
    $pharmacyI18n = [
        'search_error' => __('pharmacy.medicines.create.search_error'),
        'no_results' => __('pharmacy.medicines.create.no_results'),
        'badge_moh' => __('pharmacy.medicines.create.badge_moh'),
        'badge_local' => __('pharmacy.medicines.create.badge_local'),
        'official_price' => __('pharmacy.medicines.create.official_price', ['price' => ':price']),
    ];
@endphp
<script>
(function () {
    const searchInput = document.getElementById('medicine_search');
    const resultsBox = document.getElementById('search_results');
    const selectedBox = document.getElementById('selected_box');
    const selectedName = document.getElementById('selected_name');
    const selectedSub = document.getElementById('selected_sub');
    const selectedPrice = document.getElementById('selected_price');
    const manualBox = document.getElementById('manual_box');
    const toggleManualBtn = document.getElementById('toggle_manual');
    const clearSelectionBtn = document.getElementById('clear_selection');
    const medId = document.getElementById('medicine_id');
    const mohId = document.getElementById('moh_medicine_id');
    const searchUrl = @json(route('pharmacy.medicines.search'));
    const i18n = @json($pharmacyI18n);

    let debounceTimer = null;
    let selected = null;

    function clearSelection() {
        selected = null;
        medId.value = '';
        mohId.value = '';
        selectedBox.style.display = 'none';
        searchInput.value = '';
        searchInput.disabled = false;
    }

    clearSelectionBtn.addEventListener('click', clearSelection);

    toggleManualBtn.addEventListener('click', function () {
        const show = manualBox.style.display === 'none';
        manualBox.style.display = show ? 'block' : 'none';
        clearSelection();
        if (show) {
            searchInput.disabled = true;
            resultsBox.style.display = 'none';
        } else {
            searchInput.disabled = false;
        }
    });

    searchInput.addEventListener('input', function () {
        clearTimeout(debounceTimer);
        const q = searchInput.value.trim();
        if (q.length < 2) {
            resultsBox.style.display = 'none';
            return;
        }
        debounceTimer = setTimeout(function () { runSearch(q); }, 350);
    });

    searchInput.addEventListener('keydown', function (e) {
        if (e.key === 'Escape') { resultsBox.style.display = 'none'; }
    });

    document.addEventListener('click', function (e) {
        if (!resultsBox.contains(e.target) && e.target !== searchInput) {
            resultsBox.style.display = 'none';
        }
    });

    function runSearch(q) {
        const url = searchUrl + '?q=' + encodeURIComponent(q);
        fetch(url, { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
            .then(function (r) { return r.json(); })
            .then(function (items) {
                renderResults(items);
            })
            .catch(function () {
                resultsBox.innerHTML = '<div class="moh-search-empty">' + i18n.search_error + '</div>';
                resultsBox.style.display = 'block';
            });
    }

    function renderResults(items) {
        if (!items.length) {
            resultsBox.innerHTML = '<div class="moh-search-empty">' + i18n.no_results + '</div>';
            resultsBox.style.display = 'block';
            return;
        }
        const html = items.map(function (item) {
            const priceBadge = item.official_price != null
                ? '<span class="moh-result-price">' + i18n.official_price.replace(':price', item.official_price) + '</span>'
                : '';
            const badge = item.type === 'moh' ? '<span class="moh-result-badge">' + i18n.badge_moh + '</span>' : '<span class="moh-result-badge moh-result-badge-local">' + i18n.badge_local + '</span>';
            return '<div class="moh-search-item" data-type="' + item.type + '" data-id="' + item.id + '" data-name="' + esc(item.name) + '" data-sub="' + esc(item.sub || '') + '" data-price="' + (item.official_price != null ? item.official_price : '') + '">' +
                '<div><strong>' + esc(item.name) + '</strong>' + (item.sub ? '<small>' + esc(item.sub) + '</small>' : '') + '</div>' +
                '<div class="moh-result-meta">' + badge + priceBadge + '</div>' +
                '</div>';
        }).join('');
        resultsBox.innerHTML = html;
        resultsBox.style.display = 'block';

        resultsBox.querySelectorAll('.moh-search-item').forEach(function (el) {
            el.addEventListener('click', function () { selectItem(el); });
        });
    }

    function selectItem(el) {
        const type = el.getAttribute('data-type');
        const id = el.getAttribute('data-id');
        const name = el.getAttribute('data-name');
        const sub = el.getAttribute('data-sub');
        const price = el.getAttribute('data-price');

        selected = { type: type, id: id, name: name };
        medId.value = type === 'medicine' ? id : '';
        mohId.value = type === 'moh' ? id : '';

        selectedName.textContent = name;
        selectedSub.textContent = sub || '';
        if (price !== '') {
            selectedPrice.textContent = i18n.official_price.replace(':price', price);
            selectedPrice.style.display = '';
        } else {
            selectedPrice.style.display = 'none';
        }

        resultsBox.style.display = 'none';
        selectedBox.style.display = 'flex';
        searchInput.disabled = true;
        searchInput.value = name;
    }

    function esc(str) {
        const div = document.createElement('div');
        div.textContent = str;
        return div.innerHTML;
    }
})();
</script>
@endsection
