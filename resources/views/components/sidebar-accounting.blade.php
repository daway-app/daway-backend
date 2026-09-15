@php
    /**
     * قسم «المحاسبة» في الشريط الجانبي (صيدلية).
     *
     * ملاحظة تصميمية: الصفحات التي لم تُبنَ بعد تظهر كعناصر **معطّلة** بوسم
     * «قريبًا» بدل روابط ميتة تُرجع 404 — فلا نوعد المستخدم بصفحة غير موجودة.
     * عند بناء كل صفحة: انقل عنصرها من $acSoon إلى $acLinks.
     */
    $isAccounting = request()->routeIs('pharmacy.accounting.*');

    // الصفحات المبنية فعلًا (لها مسار حقيقي)
    $acLinks = [
        [
            'route' => 'pharmacy.accounting.overview',
            'label' => __('accounting.sidebar.overview'),
            'active' => request()->routeIs('pharmacy.accounting.overview'),
        ],
        [
            'route' => 'pharmacy.accounting.sales.index',
            'label' => __('accounting.sidebar.sales'),
            'active' => request()->routeIs('pharmacy.accounting.sales.*'),
        ],
    ];

    // الصفحات القادمة (بلا مسار بعد)
    $acSoon = [
        __('accounting.sidebar.purchases'),
        __('accounting.sidebar.expenses'),
        __('accounting.sidebar.suppliers'),
        __('accounting.sidebar.customers'),
        __('accounting.sidebar.cash_register'),
        __('accounting.sidebar.payments'),
        __('accounting.sidebar.profit_loss'),
        __('accounting.sidebar.reports'),
    ];

    $acSectionId = 'ac-sidebar-menu';
@endphp

<div class="nav-section nav-group">
    <div class="section-label">@lang('accounting.sidebar.section_title')</div>

    <button type="button"
            class="nav-item nav-toggle {{ $isAccounting ? 'is-open' : '' }}"
            data-nav-toggle="{{ $acSectionId }}"
            aria-expanded="{{ $isAccounting ? 'true' : 'false' }}"
            aria-controls="{{ $acSectionId }}">
        <span class="nav-icon">
            <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <rect x="2" y="5" width="20" height="14" rx="2"></rect>
                <line x1="2" y1="10" x2="22" y2="10"></line>
                <line x1="6" y1="15" x2="10" y2="15"></line>
            </svg>
        </span>
        <span class="nav-text">@lang('accounting.sidebar.section_title')</span>
        <i class="fas fa-chevron-down nav-caret" aria-hidden="true"></i>
    </button>

    <ul class="nav-submenu" id="{{ $acSectionId }}" @unless($isAccounting) hidden @endunless>
        @foreach($acLinks as $link)
            <li>
                <a href="{{ route($link['route']) }}" class="nav-subitem {{ $link['active'] ? 'active' : '' }}"
                   @if($link['active']) aria-current="page" @endif>
                    <span class="sub-dot" aria-hidden="true"></span>
                    <span>{{ $link['label'] }}</span>
                </a>
            </li>
        @endforeach

        @foreach($acSoon as $label)
            <li>
                {{-- عنصر غير فعّال: بلا href — لا رابط ميت --}}
                <span class="nav-subitem" aria-disabled="true">
                    <span class="sub-dot" aria-hidden="true"></span>
                    <span>{{ $label }}</span>
                    <span class="nav-soon">@lang('accounting.sidebar.soon')</span>
                </span>
            </li>
        @endforeach
    </ul>
</div>

@once
    @push('scripts')
        <script>
            // فتح/طيّ قسم المحاسبة — حالة محفوظة محليًا حتى لا ينطوي كل تنقّل
            (function () {
                var KEY = 'daway.accounting.nav.open';

                function init() {
                    document.querySelectorAll('[data-nav-toggle]').forEach(function (btn) {
                        var id = btn.getAttribute('data-nav-toggle');
                        var menu = document.getElementById(id);
                        if (!menu) return;

                        // استرجاع الحالة المحفوظة (إن لم تكن الصفحة الحالية داخل القسم)
                        try {
                            if (localStorage.getItem(KEY) === '1' && btn.getAttribute('aria-expanded') !== 'true') {
                                btn.setAttribute('aria-expanded', 'true');
                                menu.hidden = false;
                            }
                        } catch (e) {}

                        btn.addEventListener('click', function () {
                            var open = btn.getAttribute('aria-expanded') === 'true';
                            btn.setAttribute('aria-expanded', open ? 'false' : 'true');
                            menu.hidden = open;
                            try {
                                localStorage.setItem(KEY, open ? '0' : '1');
                            } catch (e) {}
                        });
                    });
                }

                document.addEventListener('DOMContentLoaded', init);
            })();
        </script>
    @endpush
@endonce
