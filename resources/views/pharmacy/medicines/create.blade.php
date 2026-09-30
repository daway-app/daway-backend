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

        <form action="{{ route('pharmacy.medicines.store') }}" method="POST" enctype="multipart/form-data" data-offline-form="medicine-create">
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

            {{-- ── 1) الدواء: بحث في الكتالوج المحلي أو إضافة يدوية عند غياب النتيجة ── --}}
            <div class="premium-card">
                <div class="card-head">
                    <div class="card-head-content">
                        <div class="card-icon teal">
                            <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="8"></circle><line x1="21" y1="21" x2="16.65" y2="16.65"></line></svg>
                        </div>
                        <div>
                            <h2>@lang('pharmacy.medicines.create.choose_medicine')</h2>
                        </div>
                    </div>
                </div>
                <div class="card-body">
                    <div class="fg" id="search_zone">
                        <label class="fl" for="medicine_search">@lang('pharmacy.medicines.create.search_label') <span class="req">*</span></label>
                        <input class="fc" type="text" id="medicine_search" autocomplete="off" placeholder="@lang('pharmacy.medicines.create.search_placeholder')" />
                        <div id="search_results" class="moh-search-results" style="display:none;"></div>
                        <small class="fl-hint">@lang('pharmacy.medicines.create.search_hint')</small>
                    </div>

                    <input type="hidden" name="medicine_id" id="medicine_id" value="{{ old('medicine_id') }}">
                    <input type="hidden" name="moh_medicine_id" id="moh_medicine_id" value="{{ old('moh_medicine_id') }}">

                    {{-- الدواء المختار من الكتالوج --}}
                    <div id="selected_box" class="moh-selected" style="display:none;">
                        <div class="moh-selected-info">
                            <strong id="selected_name"></strong>
                            <span id="selected_sub"></span>
                            <span id="selected_moh" class="moh-result-meta" style="display:none;"></span>
                            <span id="selected_price" class="moh-official-price" style="display:none;"></span>
                        </div>
                        <div style="display:flex;gap:8px;">
                            <a id="selected_edit_link" class="btn-cancel" style="display:none;" href="#" target="_blank">@lang('pharmacy.medicines.create.edit_existing')</a>
                            <button type="button" id="clear_selection" class="btn-cancel">@lang('pharmacy.medicines.create.change_button')</button>
                        </div>
                    </div>

                    {{-- إضافة يدوية — تظهر فقط عند غياب النتائج في الكتالوج --}}
                    <div id="manual_cta" class="fg" style="display:none;margin-top:12px;">
                        <div class="alert alert-error" style="margin-bottom:10px;">
                            <span class="alert-icon">!</span>
                            <div><div class="alert-title">@lang('pharmacy.medicines.create.no_results_title')</div>
                            <div style="font-size:.85rem;">@lang('pharmacy.medicines.create.no_results_desc')</div></div>
                        </div>
                        <button type="button" id="open_manual" class="btn-cancel">@lang('pharmacy.medicines.create.manual_button')</button>
                    </div>

                    @php
                        // الوضع اليدوي يُفتح تلقائيًا عند رجوع النموذج بخطأ (back()->withInput())
                        // حتى لا تختفي حقول الإدخال اليدوي ورسائل الخطأ داخل صندوق مخفي.
                        $manualOpen = old('trade_name') !== null
                            || old('active_ingredient') !== null
                            || $errors->has('trade_name')
                            || $errors->has('active_ingredient')
                            || $errors->has('category_id')
                            || $errors->has('subcategory_id');
                    @endphp
                    <div id="manual_box" style="{{ $manualOpen ? 'margin-top:14px;' : 'display:none;margin-top:14px;' }}">
                        <div class="form-row">
                            <div class="fg">
                                <label class="fl" for="trade_name">@lang('pharmacy.medicines.create.manual_trade_name') <span class="req">*</span></label>
                                <input class="fc" type="text" id="trade_name" name="trade_name" value="{{ old('trade_name') }}" placeholder="@lang('pharmacy.medicines.create.manual_trade_name_placeholder')" dir="ltr">
                                @error('trade_name')<span class="error-text" role="alert">{{ $message }}</span>@enderror
                            </div>
                            <div class="fg">
                                <label class="fl" for="active_ingredient">@lang('pharmacy.medicines.create.manual_ingredient') <span class="req">*</span></label>
                                <input class="fc" type="text" id="active_ingredient" name="active_ingredient" value="{{ old('active_ingredient') }}" placeholder="@lang('pharmacy.medicines.create.manual_ingredient_placeholder')">
                                @error('active_ingredient')<span class="error-text" role="alert">{{ $message }}</span>@enderror
                            </div>
                        </div>
                        <div class="fg">
                            <label class="fl" for="trade_name_ar">@lang('pharmacy.medicines.create.arabic_name_label')</label>
                            <input class="fc" type="text" id="trade_name_ar" name="trade_name_ar" value="{{ old('trade_name_ar') }}" placeholder="@lang('pharmacy.medicines.create.arabic_name_placeholder')">
                            @error('trade_name_ar')<span class="error-text" role="alert">{{ $message }}</span>@enderror
                        </div>
                        <div class="form-row">
                            <div class="fg">
                                <label class="fl" for="category_id">@lang('pharmacy.medicines.create.category') <span class="req">*</span></label>
                                {{-- category_id مطلوب في الوضع اليدوي فقط. وجود required هنا بينما
                                     #manual_box مخفي (display:none) يجعل المتصفّح يفشل في
                                     constraint validation على عنصر غير قابل للتركيز، فيُلغي
                                     الإرسال بصمت ويبدو زر الحفظ ميتًا. --}}
                                <select class="fc" id="category_id" name="category_id" @if($manualOpen) required @endif>
                                    <option value="">—</option>
                                    @foreach($categories as $cat)
                                        <option value="{{ $cat->id }}" {{ old('category_id') == $cat->id ? 'selected' : '' }}>{{ $cat->name_ar }}</option>
                                    @endforeach
                                </select>
                                @error('category_id')<span class="error-text" role="alert">{{ $message }}</span>@enderror
                            </div>
                            <div class="fg">
                                <label class="fl" for="subcategory_id">@lang('pharmacy.medicines.create.subcategory')</label>
                                <select class="fc" id="subcategory_id" name="subcategory_id">
                                    <option value="">—</option>
                                </select>
                                @error('subcategory_id')<span class="error-text" role="alert">{{ $message }}</span>@enderror
                            </div>
                        </div>
                    </div>

                    @error('medicine_id')
                        <div class="fg" style="margin-top:12px;">
                            <span class="error-text" role="alert"><strong>{{ $message }}</strong></span>
                        </div>
                    @enderror
                </div>
            </div>

            {{-- ── 2) السعر والمخزون (موحد للطريقتين) ── --}}
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
                    </div>
                    <div class="fg" style="margin-top:14px;">
                        <label class="fl-check">
                            <input type="checkbox" name="is_available" id="is_available" value="1" {{ old('is_available', true) ? 'checked' : '' }}>
                            @lang('pharmacy.medicines.create.available_now')
                        </label>
                    </div>

                    <div class="fg" style="margin-top:14px;">
                        <label class="fl" for="image">@lang('pharmacy.medicines.create.image_label') <span style="color:#64748b;font-weight:400;">@lang('pharmacy.medicines.create.optional')</span></label>
                        <input class="fc" type="file" id="image" name="image" accept="image/*" style="height:auto;padding:10px;">
                        <img id="medicine_image_preview" alt="" style="display:none;width:72px;height:72px;object-fit:cover;border-radius:10px;margin-top:8px;">
                        @error('image')
                            <span class="error-text" role="alert"><strong>{{ $message }}</strong></span>
                        @enderror
                    </div>
                </div>
            </div>

            <div class="form-actions">
                <button type="submit" class="btn-submit">@lang('pharmacy.medicines.create.add_button')</button>
                <a href="{{ route('pharmacy.medicines.index') }}" class="btn-cancel">@lang('pharmacy.medicines.create.cancel_button')</a>
                <a href="{{ route('pharmacy.medicines.request.create') }}" class="btn-cancel" style="color:#1C72A6;">@lang('pharmacy.medicines.create.request_new_button')</a>
            </div>
        </form>
    </div>
@endsection

@section('scripts')
@php
    $pharmacyI18n = [
        'search_error' => __('pharmacy.medicines.create.search_error'),
        'no_results_title' => __('pharmacy.medicines.create.no_results_title'),
        'badge_moh' => __('pharmacy.medicines.create.badge_moh'),
        'official_price' => __('pharmacy.medicines.create.official_price', ['price' => ':price']),
        'already_added' => __('pharmacy.medicines.create.already_added_badge'),
        'edit_existing' => __('pharmacy.medicines.create.edit_existing'),
    ];
    $subByCat = $subcategories->map(function ($subs) {
        return $subs->pluck('name_ar', 'id')->all();
    })->all();
@endphp
<script>
(function () {
    const searchInput = document.getElementById('medicine_search');
    const resultsBox = document.getElementById('search_results');
    const selectedBox = document.getElementById('selected_box');
    const selectedName = document.getElementById('selected_name');
    const selectedSub = document.getElementById('selected_sub');
    const selectedMoh = document.getElementById('selected_moh');
    const selectedPrice = document.getElementById('selected_price');
    const selectedEditLink = document.getElementById('selected_edit_link');
    const manualCta = document.getElementById('manual_cta');
    const manualBox = document.getElementById('manual_box');
    const openManualBtn = document.getElementById('open_manual');
    const clearSelectionBtn = document.getElementById('clear_selection');
    const medId = document.getElementById('medicine_id');
    const mohId = document.getElementById('moh_medicine_id');
    const searchUrl = @json(route('pharmacy.medicines.catalog-search'));
    const editUrl = @json(route('pharmacy.medicines.edit', 'ROWIDPH'));
    const i18n = @json($pharmacyI18n);
    const subByCat = @json($subByCat);

    const catSel = document.getElementById('category_id');
    const subSel = document.getElementById('subcategory_id');
    function fillSubs(catId) {
        subSel.innerHTML = '<option value="">—</option>';
        (subByCat[catId] || []).forEach(function (name, id) {
            const opt = document.createElement('option');
            opt.value = id; opt.textContent = name;
            subSel.appendChild(opt);
        });
    }
    if (catSel) {
        catSel.addEventListener('change', function () { fillSubs(this.value); });
        fillSubs(catSel.value);
    }

    let debounceTimer = null;
    // عدّاد التسلسل محلي ومُهيّئ بـ 0: استخدام ++ على خاصية window غير مُعرّفة
    // ينتج NaN، فيصبح الشرط (seq !== window._x) حقيقيًا دائمًا ولا تُعرض أي نتائج.
    let searchSeq = 0;
    let searchAbort = null;

    // category_id مطلوب فقط داخل الوضع اليدوي. أي required على حقل مخفي
    // (display:none) يجعل المتصفّح يُلغي إرسال النموذج بصمت.
    function setManualRequired(on) {
        if (!catSel) return;
        if (on) {
            catSel.setAttribute('required', 'required');
        } else {
            catSel.removeAttribute('required');
        }
    }
    // حالة البداية تُشتق من الحالة المرسومة من السيرفر (قد يكون مفتوحًا بعد خطأ)
    setManualRequired(manualBox.style.display !== 'none');

    function clearSelection() {
        medId.value = '';
        mohId.value = '';
        selectedBox.style.display = 'none';
        selectedEditLink.style.display = 'none';
        manualCta.style.display = 'none';
        manualBox.style.display = 'none';
        setManualRequired(false);
        searchInput.value = '';
        searchInput.disabled = false;
        resultsBox.style.display = 'none';
        document.getElementById('search_zone').style.display = '';
    }

    clearSelectionBtn.addEventListener('click', clearSelection);

    openManualBtn.addEventListener('click', function () {
        // وضع اليدوي: يخبّأ البحث ويُظهر نموذج البيانات — لا طريقتين متداخلتين
        manualBox.style.display = 'block';
        setManualRequired(true);
        searchInput.value = '';
        resultsBox.style.display = 'none';
        manualCta.style.display = 'none';
    });

    searchInput.addEventListener('input', function () {
        clearTimeout(debounceTimer);
        const q = searchInput.value.trim();
        if (q.length < 2) {
            resultsBox.style.display = 'none';
            manualCta.style.display = 'none';
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

    const imageInput = document.getElementById('image');
    const imagePreview = document.getElementById('medicine_image_preview');
    if (imageInput && imagePreview) {
        imageInput.addEventListener('change', function () {
            const file = this.files && this.files[0];
            if (!file) {
                imagePreview.src = '';
                imagePreview.style.display = 'none';
                return;
            }
            imagePreview.src = URL.createObjectURL(file);
            imagePreview.style.display = 'block';
        });
    }

    function runSearch(q) {
        const url = searchUrl + '?q=' + encodeURIComponent(q);
        // إلغاء الطلب السابق (إن وُجد) حتى لا تتغلب استجابة قديمة على الأحدث
        if (searchAbort) searchAbort.abort();
        const controller = new AbortController();
        searchAbort = controller;
        const seq = ++searchSeq;

        fetch(url, {
            headers: { 'X-Requested-With': 'XMLHttpRequest' },
            signal: controller.signal,
        })
            .then(function (r) { return r.json(); })
            .then(function (res) {
                if (seq !== searchSeq) return; // وصلت متأخرة — تجاهُل
                renderResults(res.items || []);
            })
            .catch(function (err) {
                if (err && err.name === 'AbortError') return;
                if (seq !== searchSeq) return;
                resultsBox.innerHTML = '<div class="moh-search-empty">' + i18n.search_error + '</div>';
                resultsBox.style.display = 'block';
            });
    }

    function renderResults(items) {
        if (!items.length) {
            // لا نتائج في الكتالوج → يظهر خيار الإضافة اليدوية هنا فقط
            resultsBox.innerHTML = '';
            resultsBox.style.display = 'none';
            manualCta.style.display = 'block';
            return;
        }
        manualCta.style.display = 'none';
        const html = items.map(function (item) {
            const priceBadge = item.official_price != null
                ? '<span class="moh-result-price">' + i18n.official_price.replace(':price', item.official_price) + '</span>'
                : '';
            const mohBadge = (item.moh_product_id || item.moh_drug_id)
                ? '<span class="moh-result-badge">' + i18n.badge_moh + ' #' + (item.moh_product_id || item.moh_drug_id) + '</span>'
                : '';
            const added = item.already_added
                ? '<span class="moh-result-badge" style="color:#c2611c;">' + i18n.already_added + '</span>'
                : '';
            return '<div class="moh-search-item" data-id="' + item.id + '" data-name="' + esc(item.name) + '" data-sub="' + esc(item.sub || '') + '" data-price="' + (item.official_price != null ? item.official_price : '') + '" data-moh="' + (item.moh_product_id || item.moh_drug_id || '') + '" data-row="' + (item.existing_row_id || '') + '">' +
                '<div><strong>' + esc(item.name) + '</strong>' + (item.sub ? '<small>' + esc(item.sub) + '</small>' : '') + '</div>' +
                '<div class="moh-result-meta">' + mohBadge + priceBadge + added + '</div>' +
                '</div>';
        }).join('');
        resultsBox.innerHTML = html;
        resultsBox.style.display = 'block';

        resultsBox.querySelectorAll('.moh-search-item').forEach(function (el) {
            el.addEventListener('click', function () { selectItem(el); });
        });
    }

    function selectItem(el) {
        const id = el.getAttribute('data-id');
        const name = el.getAttribute('data-name');
        const sub = el.getAttribute('data-sub');
        const price = el.getAttribute('data-price');
        const mohKey = el.getAttribute('data-moh');
        const rowId = el.getAttribute('data-row');

        mohId.value = id;
        medId.value = '';

        selectedName.textContent = name;
        selectedSub.textContent = sub || '';
        selectedMoh.textContent = mohKey ? 'MOH #' + mohKey : '';
        selectedMoh.style.display = mohKey ? '' : 'none';
        if (price !== '') {
            selectedPrice.textContent = i18n.official_price.replace(':price', price);
            selectedPrice.style.display = '';
        } else {
            selectedPrice.style.display = 'none';
        }

        if (rowId) {
            // دواء مضاف مسبقًا لصيدليتك → رابط التعديل بدل الإضافة المكررة
            selectedEditLink.href = editUrl.replace('ROWIDPH', rowId);
            selectedEditLink.style.display = '';
        } else {
            selectedEditLink.style.display = 'none';
        }

        resultsBox.style.display = 'none';
        manualCta.style.display = 'none';
        manualBox.style.display = 'none';
        setManualRequired(false);
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
