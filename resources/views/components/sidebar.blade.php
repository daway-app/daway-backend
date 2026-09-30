<aside class="sidebar-pro">
    <div class="sidebar-content-wrapper">
        <!-- Logo Header -->
        <div class="sidebar-logo-header">
            <div class="logo-icon-box">
                <img src="{{ asset('images/dawak-logo-256.jpg') }}" alt="شعار دواك" class="sidebar-logo-img" width="64" height="64" loading="lazy" decoding="async">
            </div>
            <div class="logo-text-group">
                <h2 class="logo-title">{{ __('layout.app_title') }}</h2>
                <span class="logo-subtitle">{{ __('layout.app_subtitle') }}</span>
            </div>
        </div>

        @auth
            @if(auth()->user()->role === 'admin')
                <!-- Section: Main for Admin -->
                <div class="nav-section">
                    <div class="section-label">@lang('layout.main_section')</div>
                    @php $isDashboard = request()->routeIs('dashboard*'); @endphp
                    <a href="{{ route('dashboard') }}" class="nav-item {{ $isDashboard ? 'active' : '' }}">
                        <span class="nav-icon"><svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="7" height="7"></rect><rect x="14" y="3" width="7" height="7"></rect><rect x="14" y="14" width="7" height="7"></rect><rect x="3" y="14" width="7" height="7"></rect></svg></span>
                        <span class="nav-text">@lang('layout.dashboard')</span>
                    </a>
                </div>

                <!-- Section: Management for Admin -->
                <div class="nav-section">
                    <div class="section-label">@lang('layout.management_section')</div>

                    @php $isPharmacies = request()->routeIs('pharmacies.*'); @endphp
                    <a href="{{ route('pharmacies.index') }}" class="nav-item {{ $isPharmacies ? 'active' : '' }}">
                        <span class="nav-icon"><svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"></path><polyline points="22 4 12 14.01 9 11.01"></polyline></svg></span>
                        <span class="nav-text">@lang('layout.pharmacies')</span>
                    </a>

                    @php $isMedicines = request()->routeIs('medicines.*'); @endphp
                    <a href="{{ route('medicines.index') }}" class="nav-item {{ $isMedicines ? 'active' : '' }}">
                        <span class="nav-icon"><svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21.44 11.05l-9.19 9.19a6 6 0 0 1-8.49-8.49l9.19-9.19a4 4 0 0 1 5.66 5.66l-9.2 9.19a2 2 0 0 1-2.83-2.83l8.49-8.48"></path></svg></span>
                        <span class="nav-text">@lang('layout.medicines')</span>
                    </a>

                    @php $isCategories = request()->routeIs('categories.*'); @endphp
                    <a href="{{ route('categories.index') }}" class="nav-item {{ $isCategories ? 'active' : '' }}">
                        <span class="nav-icon"><svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="7" height="7"></rect><rect x="14" y="3" width="7" height="7"></rect><rect x="14" y="14" width="7" height="7"></rect><rect x="3" y="14" width="7" height="7"></rect></svg></span>
                        <span class="nav-text">@lang('layout.categories')</span>
                    </a>

                    @php $isInventory = request()->routeIs('inventory.*'); @endphp
                    <a href="{{ route('inventory.index') }}" class="nav-item {{ $isInventory ? 'active' : '' }}">
                        <span class="nav-icon"><svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"></path><polyline points="3.27 6.96 12 12.01 20.73 6.96"></polyline><line x1="12" y1="22.08" x2="12" y2="12"></line></svg></span>
                        <span class="nav-text">@lang('layout.inventory')</span>
                    </a>

                    @php $isPatients = request()->routeIs('patients.*'); @endphp
                    <a href="{{ route('patients.index') }}" class="nav-item {{ $isPatients ? 'active' : '' }}">
                        <span class="nav-icon"><svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"></path><circle cx="9" cy="7" r="4"></circle><path d="M23 21v-2a4 4 0 0 0-3-3.87"></path><path d="M16 3.13a4 4 0 0 1 0 7.75"></path></svg></span>
                        <span class="nav-text">@lang('layout.patients')</span>
                    </a>

                    @php $isUsers = request()->routeIs('users.*'); @endphp
                    <a href="{{ route('users.index') }}" class="nav-item {{ $isUsers ? 'active' : '' }}">
                        <span class="nav-icon"><svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"></path></svg></span>
                        <span class="nav-text">@lang('layout.users')</span>
                        <span class="nav-badge">{{ \Illuminate\Support\Facades\Cache::remember('nav_users_count', 60, fn () => \App\Models\User::count()) }}</span>
                    </a>
                </div>

                <!-- Section: Settings for Admin -->
                <div class="nav-section">
                    <div class="section-label">@lang('layout.settings_section')</div>

                    @php $isSettings = request()->routeIs('settings.*'); @endphp
                    <a href="{{ route('settings.index') }}" class="nav-item {{ $isSettings ? 'active' : '' }}">
                        <span class="nav-icon"><svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="3"></circle><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1 0 2.83 2 2 0 0 1-2.83 0l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-2 2 2 2 0 0 1-2-2v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 0 1-2.83 0 2 2 0 0 1 0-2.83l.06-.06a1.65 1.65 0 0 0 .33-1.82 1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1-2-2 2 2 0 0 1 2-2h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 0 1 0-2.83 2 2 0 0 1 2.83 0l.06.06a1.65 1.65 0 0 0 1.82.33H9a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 2-2 2 2 0 0 1 2 2v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 0 1 2.83 0 2 2 0 0 1 0 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82V9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 2 2 2 2 0 0 1-2 2h-.09a1.65 1.65 0 0 0-1.51 1z"></path></svg></span>
                        <span class="nav-text">@lang('layout.system_settings')</span>
                    </a>

                    @php $isLogs = request()->routeIs('logs.*'); @endphp
                    <a href="{{ route('logs.index') }}" class="nav-item {{ $isLogs ? 'active' : '' }}">
                        <span class="nav-icon"><svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M13 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V9z"></path><polyline points="13 2 13 9 20 9"></polyline></svg></span>
                        <span class="nav-text">@lang('layout.activity_log')</span>
                    </a>

                    @php $isProfile = request()->routeIs('profile.*'); @endphp
                    <a href="{{ route('profile.edit') }}" class="nav-item {{ $isProfile ? 'active' : '' }}">
                        <span class="nav-icon"><svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"></path><circle cx="12" cy="7" r="4"></circle></svg></span>
                        <span class="nav-text">@lang('layout.my_profile')</span>
                    </a>
                </div>
            @elseif(auth()->user()->role === 'pharmacy')
                <!-- Section: Pharmacy Dashboard -->
                <div class="nav-section">
                    <div class="section-label">@lang('pharmacy.sidebar.section_title')</div>
                    @php $isPharmacyDashboard = request()->routeIs('pharmacy.dashboard.*'); @endphp
                    <a href="{{ route('pharmacy.dashboard.index') }}" class="nav-item {{ $isPharmacyDashboard ? 'active' : '' }}">
                        <span class="nav-icon"><svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="7" height="7"></rect><rect x="14" y="3" width="7" height="7"></rect><rect x="14" y="14" width="7" height="7"></rect><rect x="3" y="14" width="7" height="7"></rect></svg></span>
                        <span class="nav-text">@lang('pharmacy.sidebar.dashboard')</span>
                    </a>
                    @php $isPharmacyMedicines = request()->routeIs('pharmacy.medicines.index', 'pharmacy.medicines.edit'); @endphp
                    <a href="{{ route('pharmacy.medicines.index') }}" class="nav-item {{ $isPharmacyMedicines ? 'active' : '' }}">
                        <span class="nav-icon"><svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21.44 11.05l-9.19 9.19a6 6 0 0 1-8.49-8.49l9.19-9.19a4 4 0 0 1 5.66 5.66l-9.2 9.19a2 2 0 0 1-2.83-2.83l8.49-8.48"></path></svg></span>
                        <span class="nav-text">@lang('pharmacy.sidebar.manage_medicines')</span>
                    </a>
                    @php $isPharmacyInventory = request()->routeIs('pharmacy.inventory.index') || request()->routeIs('pharmacy.inventory.update'); @endphp
                    <a href='{{ route("pharmacy.inventory.index") }}' class='nav-item {{ $isPharmacyInventory ? "active" : "" }}'>
                        <span class='nav-icon'><svg xmlns='http://www.w3.org/2000/svg' width='20' height='20' viewBox='0 0 24 24' fill='none' stroke='currentColor' stroke-width='2' stroke-linecap='round' stroke-linejoin='round'><path d='M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z'/><polyline points='3.27 6.96 12 12.01 20.73 6.96'/><line x1='12' y1='22.08' x2='12' y2='12'/></svg></span>
                        <span class='nav-text'>@lang('pharmacy.sidebar.inventory')</span>
                    </a>
                    @php $isPharmacyBulkImport = request()->routeIs('pharmacy.inventory.import.*'); @endphp
                    <a href='{{ route("pharmacy.inventory.import.index") }}' class='nav-item {{ $isPharmacyBulkImport ? "active" : "" }}'>
                        <span class='nav-icon'><svg xmlns='http://www.w3.org/2000/svg' width='20' height='20' viewBox='0 0 24 24' fill='none' stroke='currentColor' stroke-width='2' stroke-linecap='round' stroke-linejoin='round'><path d='M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4'/><polyline points='17 8 12 3 7 8'/><line x1='12' y1='3' x2='12' y2='15'/></svg></span>
                        <span class='nav-text'>@lang('pharmacy.sidebar.bulk_import')</span>
                    </a>
                    @php $isPharmacyInquiries = request()->routeIs('pharmacy.inquiries.*'); @endphp
                    <a href='{{ route("pharmacy.inquiries.index") }}' class='nav-item {{ $isPharmacyInquiries ? "active" : "" }}'>
                        <span class='nav-icon'><svg xmlns='http://www.w3.org/2000/svg' width='20' height='20' viewBox='0 0 24 24' fill='none' stroke='currentColor' stroke-width='2' stroke-linecap='round' stroke-linejoin='round'><path d='M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z'/></svg></span>
                        <span class='nav-text'>@lang('pharmacy.sidebar.inquiries')</span>
                    </a>
                    @php $isPharmacyAlternatives = request()->routeIs('pharmacy.alternatives.*'); @endphp
                    <a href="{{ route('pharmacy.alternatives.index') }}" class="nav-item {{ $isPharmacyAlternatives ? 'active' : '' }}">
                        <span class="nav-icon"><svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"></path><line x1="12" y1="9" x2="12" y2="13"></line><line x1="12" y1="17" x2="12.01" y2="17"></line></svg></span>
                        <span class="nav-text">@lang('pharmacy.sidebar.manage_alternatives')</span>
                    </a>
                    @php $isPharmacyProfile = request()->routeIs('pharmacy.profile.*'); @endphp
                    <a href="{{ route('pharmacy.profile.edit') }}" class="nav-item {{ $isPharmacyProfile ? 'active' : '' }}">
                        <span class="nav-icon"><svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"></path><circle cx="12" cy="7" r="4"></circle></svg></span>
                        <span class="nav-text">@lang('pharmacy.sidebar.pharmacy_profile')</span>
                    </a>
                    @php $isPharmacyRatings = request()->routeIs('pharmacy.ratings.*'); @endphp
                    <a href="{{ route('pharmacy.ratings.index') }}" class="nav-item {{ $isPharmacyRatings ? 'active' : '' }}">
                        <span class="nav-icon"><svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 17.75l-6.172 3.245 1.179-6.873-5-4.867 6.908-1.004 3.082-6.25 3.082 6.25 6.908 1.004-5 4.867 1.179 6.873z"></path></svg></span>
                        <span class="nav-text">@lang('pharmacy.sidebar.ratings')</span>
                    </a>
                </div>

                <!-- Section: Add Medicine Widget (only for pharmacy role) -->
                @if(auth()->user()->role === 'pharmacy')
                    <div class="sidebar-widget-section">
                        <div class="sidebar-widget-card" id="addMedicineWidget">
                            <div class="widget-header">
                                <h3 class="widget-title">@lang('pharmacy.sidebar_widget.add_medicine_card')</h3>
                            </div>
                            <form id="addMedicineForm" method="POST" action="{{ route('pharmacy.medicines.store') }}" enctype="multipart/form-data" data-offline-form="medicine-create" style="display:none;">
                                @csrf
                                <input type="hidden" name="medicine_id" id="widget_medicine_id">
                                <input type="hidden" name="moh_medicine_id" id="widget_moh_medicine_id">
                                <div class="widget-form">
                                    <div class="form-group">
                                        <label for="widget_search" class="form-label">@lang('pharmacy.sidebar_widget.search_label')</label>
                                        <input type="text" id="widget_search" class="form-input" placeholder="@lang('pharmacy.sidebar_widget.search_placeholder')" autocomplete="off">
                                        <div id="widget_search_results" class="search-results" style="display:none;"></div>
                                        <small class="form-hint">@lang('pharmacy.sidebar_widget.search_hint')</small>
                                    </div>
                                    <div class="form-row">
                                        <div class="form-group">
                                            <label for="widget_price" class="form-label">@lang('pharmacy.sidebar_widget.price_label')</label>
                                            <input type="number" id="widget_price" name="price" class="form-input" step="0.01" min="0" required>
                                        </div>
                                        <div class="form-group">
                                            <label for="widget_quantity" class="form-label">@lang('pharmacy.sidebar_widget.quantity_label')</label>
                                            <input type="number" id="widget_quantity" name="quantity" class="form-input" min="0" required>
                                        </div>
                                    </div>
                                    <div class="form-group">
                                        <label class="form-check">
                                            <input type="checkbox" name="is_available" value="1" checked>
                                            <span>@lang('pharmacy.sidebar_widget.available_now')</span>
                                        </label>
                                    </div>
                                    <div class="form-group">
                                        <label for="widget_barcode" class="form-label">@lang('pharmacy.sidebar_widget.barcode_label')</label>
                                        <input type="text" id="widget_barcode" class="form-input" placeholder="@lang('pharmacy.sidebar_widget.barcode_placeholder')" autocomplete="off">
                                        <div id="barcode_status" class="barcode-status" style="display:none;"></div>
                                    </div>
                                    <div class="widget-actions">
                                        <button type="button" id="widget_add_btn" class="btn-primary" style="display:none;">@lang('pharmacy.sidebar_widget.add_button')</button>
                                        <button type="button" id="widget_reset_btn" class="btn-secondary" style="display:none;">@lang('pharmacy.sidebar_widget.reset_button')</button>
                                    </div>
                                </div>
                            </form>
                            <div id="widget_trigger" class="widget-trigger">
                                <button type="button" class="btn-primary btn-block">@lang('pharmacy.sidebar_widget.add_medicine_card')</button>
                            </div>
                        </div>
                    </div>
                @endif

                <!-- Section: Accounting (قسم قابل للطيّ) -->
                {{-- مُخفى حاليًا عن الصيدلية عبر flag قابل للرجوع (config/features.php).
                     المكوّنات لم تُحذف، والأدمن لا يصل لهذا الفرع أصلًا. --}}
                @if(config('features.pharmacy_accounting_ui'))
                    @include('components.sidebar-accounting')
                @endif
            @endif
        @endauth
    </div>

    <!-- User Profile Footer -->
    <div class="user-profile-footer">
        <div class="user-info-group" onclick="openProfileModal()" title="@lang('layout.edit_profile_modal_title')">
            <div class="avatar-box" id="sidebarDisplayUserAvatar">
                @if(auth()->user()->avatar)
                    <img src="{{ \App\Support\Image::thumbUrl(auth()->user()->avatar, 84, 84) }}" alt="User Avatar" width="42" height="42" loading="lazy" decoding="async" style="width: 100%; height: 100%; border-radius: 50%; object-fit: cover;">
                @else
                    {{ mb_substr(auth()->user()->name, 0, 1) }}
                @endif
            </div>
            <div>
                <div class="user-name" id="sidebarDisplayUserName">{{ auth()->user()->name }}</div>
                <div class="user-role" id="sidebarDisplayUserRole">{{ auth()->user()->role ?? 'User' }}</div>
            </div>
        </div>
        <div class="more-options-btn">
            <form method="POST" action="{{ route('logout') }}" id="logoutForm">
                @csrf
                <button type="submit" id="logoutBtn" style="background: none; border: none; color: inherit; cursor: pointer; font-size: inherit;" title="@lang('layout.logout_tooltip')">
                    <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="feather feather-log-out"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"></path><polyline points="16 17 21 12 16 7"></polyline><line x1="21" y1="12" x2="9" y2="12"></line></svg>
                </button>
            </form>
        </div>
    </div>
</aside>

<div class="confirm-modal-overlay" id="logoutConfirmModal" role="dialog" aria-modal="true" aria-label="@lang('layout.logout_confirm_title')">
    <div class="confirm-modal-card">
        <div class="confirm-modal-icon">!</div>
        <h3>@lang('layout.logout_confirm_title')</h3>
        <p>@lang('layout.logout_confirm_message')</p>
        <div class="confirm-modal-actions">
            <button type="button" class="modal-btn" onclick="closeLogoutConfirm()">@lang('layout.cancel_button')</button>
            <button type="button" class="modal-btn primary" onclick="confirmLogout()">@lang('layout.logout_confirm_yes')</button>
        </div>
    </div>
</div>


<script>
    let pendingLogoutForm = null;

    document.addEventListener('DOMContentLoaded', function () {
        const logoutForm = document.getElementById('logoutForm');
        if (logoutForm) {
            logoutForm.addEventListener('submit', function (e) {
                e.preventDefault();
                pendingLogoutForm = logoutForm;
                document.getElementById('logoutConfirmModal').style.display = 'flex';
            });
        }
    });

    function closeLogoutConfirm() {
        document.getElementById('logoutConfirmModal').style.display = 'none';
        pendingLogoutForm = null;
    }

    function confirmLogout() {
        const form = pendingLogoutForm;
        closeLogoutConfirm();
        if (form) {
            form.submit();
        }
    }
</script>

<script>
(function () {
    var widget = document.getElementById('addMedicineWidget');
    if (!widget) return;

    var form = document.getElementById('addMedicineForm');
    var triggerWrap = document.getElementById('widget_trigger');
    var triggerBtn = triggerWrap ? triggerWrap.querySelector('button') : null;
    var searchInput = document.getElementById('widget_search');
    var resultsBox = document.getElementById('widget_search_results');
    var medId = document.getElementById('widget_medicine_id');
    var mohId = document.getElementById('widget_moh_medicine_id');
    var priceInput = document.getElementById('widget_price');
    var qtyInput = document.getElementById('widget_quantity');
    var barcodeInput = document.getElementById('widget_barcode');
    var barcodeStatus = document.getElementById('barcode_status');
    var addBtn = document.getElementById('widget_add_btn');
    var resetBtn = document.getElementById('widget_reset_btn');
    if (!form || !searchInput) return;

    var searchUrl = @json(route('pharmacy.medicines.catalog-search'));
    var editUrlTpl = @json(route('pharmacy.medicines.edit', '__ROWID__'));

    var debounceTimer = null;
    var barcodeTimer = null;
    var searchSeq = 0;
    var searchAbort = null;
    var barcodeAbort = null;
    var barcodeSeq = 0;
    var lastBarcodeMohId = null;

    function esc(str) {
        var div = document.createElement('div');
        div.textContent = str == null ? '' : String(str);
        return div.innerHTML;
    }

    function hasSelection() {
        return !!((medId && medId.value) || (mohId && mohId.value));
    }

    function showActionButtons() {
        if (addBtn) addBtn.style.display = '';
        if (resetBtn) resetBtn.style.display = '';
    }

    function hideActionButtons() {
        if (addBtn) addBtn.style.display = 'none';
        if (resetBtn) resetBtn.style.display = 'none';
    }

    function setStatus(msg, ok) {
        if (!barcodeStatus) return;
        if (!msg) {
            barcodeStatus.style.display = 'none';
            barcodeStatus.textContent = '';
            return;
        }
        barcodeStatus.style.display = 'block';
        barcodeStatus.textContent = msg;
        barcodeStatus.style.color = ok ? '#15803d' : '#b91c1c';
    }

    // a. Trigger button click → show form, hide trigger, focus search.
    if (triggerBtn && triggerWrap) {
        triggerBtn.addEventListener('click', function () {
            form.style.display = '';
            triggerWrap.style.display = 'none';
            if (searchInput) searchInput.focus();
        });
    }

    // b. Catalog search: debounce, min 2 chars, AbortController + seq-guard.
    searchInput.addEventListener('input', function () {
        clearTimeout(debounceTimer);
        var q = searchInput.value.trim();
        if (q.length < 2) {
            if (resultsBox) resultsBox.style.display = 'none';
            return;
        }
        debounceTimer = setTimeout(function () { runSearch(q); }, 350);
    });

    searchInput.addEventListener('keydown', function (e) {
        if (e.key === 'Escape' && resultsBox) resultsBox.style.display = 'none';
    });

    document.addEventListener('click', function (e) {
        if (!resultsBox || resultsBox.style.display === 'none') return;
        if (resultsBox.contains(e.target) || e.target === searchInput) return;
        resultsBox.style.display = 'none';
    });

    function runSearch(q) {
        if (searchAbort) searchAbort.abort();
        var controller = new AbortController();
        searchAbort = controller;
        var seq = ++searchSeq;

        fetch(searchUrl + '?q=' + encodeURIComponent(q), {
            headers: { 'X-Requested-With': 'XMLHttpRequest' },
            signal: controller.signal
        })
            .then(function (r) { return r.json(); })
            .then(function (res) {
                if (seq !== searchSeq) return;
                renderResults(res.items || []);
            })
            .catch(function (err) {
                if (err && err.name === 'AbortError') return;
                if (seq !== searchSeq || !resultsBox) return;
                resultsBox.innerHTML = '<div class="widget-search-empty">\u062d\u062f\u062b \u062e\u0637\u0623 \u0623\u062b\u0646\u0627\u0621 \u0627\u0644\u0628\u062d\u062b\u060c \u062d\u0627\u0648\u0644 \u0645\u0631\u0629 \u0623\u062e\u0631\u0649</div>';
                resultsBox.style.display = 'block';
            });
    }

    function renderResults(items) {
        if (!resultsBox) return;
        if (!items.length) {
            resultsBox.innerHTML = '<div class="widget-search-empty">\u0644\u0627 \u062a\u0648\u062c\u062f \u0646\u062a\u0627\u0626\u062c \u0645\u0637\u0627\u0628\u0642\u0629</div>';
            resultsBox.style.display = 'block';
            return;
        }
        var html = items.map(function (item) {
            var priceBadge = item.official_price != null
                ? '<span class="widget-result-price">' + esc(item.official_price) + '</span>'
                : '';
            var mohBadge = (item.moh_product_id || item.moh_drug_id)
                ? '<span class="widget-result-badge">MOH #' + esc(item.moh_product_id || item.moh_drug_id) + '</span>'
                : '';
            var addedBadge = item.already_added
                ? '<span class="widget-result-badge" style="color:#c2611c;">\u0645\u0636\u0627\u0641 \u0645\u0633\u0628\u0642\u064b\u0627</span>'
                : '';
            return '<div class="widget-search-item" data-id="' + esc(item.id) + '"' +
                ' data-name="' + esc(item.name) + '"' +
                ' data-price="' + (item.official_price != null ? esc(item.official_price) : '') + '"' +
                ' data-added="' + (item.already_added ? '1' : '') + '"' +
                ' data-row="' + esc(item.existing_row_id || '') + '">' +
                '<div><strong>' + esc(item.name) + '</strong>' +
                (item.sub ? '<small>' + esc(item.sub) + '</small>' : '') + '</div>' +
                '<div class="widget-result-meta">' + mohBadge + priceBadge + addedBadge + '</div>' +
                '</div>';
        }).join('');
        resultsBox.innerHTML = html;
        resultsBox.style.display = 'block';

        resultsBox.querySelectorAll('.widget-search-item').forEach(function (el) {
            el.addEventListener('click', function () { selectItem(el); });
        });
    }

    function selectItem(el) {
        var id = el.getAttribute('data-id');
        var name = el.getAttribute('data-name');
        var price = el.getAttribute('data-price');
        var alreadyAdded = el.getAttribute('data-added') === '1';
        var rowId = el.getAttribute('data-row');

        // Already-added items: show a notice, keep the add button disabled.
        if (alreadyAdded) {
            var editLink = rowId
                ? '<a href="' + esc(editUrlTpl.replace('__ROWID__', rowId)) + '">\u062a\u0639\u062f\u064a\u0644 \u0627\u0644\u0635\u0646\u0641 \u0645\u0646 \u0627\u0644\u0635\u0641\u062d\u0629 \u0627\u0644\u0643\u0627\u0645\u0644\u0629</a>'
                : '<a href="{{ route('pharmacy.medicines.index') }}">\u0639\u0631\u0636 \u0623\u062f\u0648\u064a\u062a\u064a</a>';
            if (resultsBox) {
                resultsBox.innerHTML = '<div class="widget-search-empty">\u0647\u0630\u0627 \u0627\u0644\u062f\u0648\u0627\u0621 \u0645\u0636\u0627\u0641 \u0645\u0633\u0628\u0642\u064b\u0627 \u0644\u0635\u064a\u062f\u0644\u064a\u062a\u0643 \u2014 ' + editLink + '</div>';
                resultsBox.style.display = 'block';
            }
            if (medId) medId.value = '';
            if (mohId) mohId.value = '';
            lastBarcodeMohId = null;
            hideActionButtons();
            if (resetBtn) resetBtn.style.display = '';
            return;
        }

        if (mohId) mohId.value = id || '';
        if (medId) medId.value = '';
        lastBarcodeMohId = null;
        searchInput.value = name || '';
        if (priceInput && price !== '') priceInput.value = price;
        if (resultsBox) resultsBox.style.display = 'none';
        showActionButtons();
    }

    // c. Barcode lookup (lookup only — the field has no name attribute, never submitted).
    if (barcodeInput) {
        barcodeInput.addEventListener('input', function () {
            clearTimeout(barcodeTimer);
            var code = barcodeInput.value.trim();
            if (!code) {
                setStatus('');
                return;
            }
            barcodeTimer = setTimeout(function () { runBarcodeLookup(code); }, 350);
        });
    }

    function runBarcodeLookup(code) {
        if (barcodeAbort) barcodeAbort.abort();
        var controller = new AbortController();
        barcodeAbort = controller;
        var seq = ++barcodeSeq;

        fetch('/api/medicines/barcode/' + encodeURIComponent(code), {
            headers: { 'X-Requested-With': 'XMLHttpRequest' },
            signal: controller.signal
        })
            .then(function (r) {
                return r.json().then(function (body) {
                    return { status: r.status, ok: r.ok, body: body };
                });
            })
            .then(function (res) {
                if (seq !== barcodeSeq) return;
                var med = res.body && res.body.data && res.body.data.medicine;
                if (res.ok && res.body && res.body.success && med) {
                    if (mohId) mohId.value = med.id != null ? med.id : '';
                    if (medId) medId.value = '';
                    lastBarcodeMohId = med.id != null ? String(med.id) : null;
                    if (searchInput && med.name_en) searchInput.value = med.name_en;
                    if (priceInput && med.official_price != null) priceInput.value = med.official_price;
                    setStatus('\u062a\u0645 \u0627\u0644\u0639\u062b\u0648\u0631 \u0639\u0644\u0649: ' + (med.name_en || ''), true);
                    showActionButtons();
                } else {
                    // 404/422 — not found: clear any barcode-derived selection only.
                    if (mohId && lastBarcodeMohId !== null && mohId.value === lastBarcodeMohId) {
                        mohId.value = '';
                    }
                    lastBarcodeMohId = null;
                    var msg = (res.body && res.body.message) || '\u0644\u0645 \u064a\u062a\u0645 \u0627\u0644\u0639\u062b\u0648\u0631 \u0639\u0644\u0649 \u062f\u0648\u0627\u0621 \u0628\u0647\u0630\u0627 \u0627\u0644\u0628\u0627\u0631\u0643\u0648\u062f';
                    setStatus(msg, false);
                    if (!hasSelection()) hideActionButtons();
                    else if (resetBtn) resetBtn.style.display = '';
                }
            })
            .catch(function (err) {
                if (err && err.name === 'AbortError') return;
                if (seq !== barcodeSeq) return;
                setStatus('\u062d\u062f\u062b \u062e\u0637\u0623 \u0623\u062b\u0646\u0627\u0621 \u0627\u0644\u0628\u062d\u062b \u0628\u0627\u0644\u0628\u0627\u0631\u0643\u0648\u062f', false);
            });
    }

    // d. Add → submit only when a medicine is selected; Reset → clear + collapse.
    if (addBtn) {
        addBtn.addEventListener('click', function () {
            if (!hasSelection()) return;
            form.submit();
        });
    }

    if (resetBtn) {
        resetBtn.addEventListener('click', function () {
            searchInput.value = '';
            if (priceInput) priceInput.value = '';
            if (qtyInput) qtyInput.value = '';
            if (barcodeInput) barcodeInput.value = '';
            if (medId) medId.value = '';
            if (mohId) mohId.value = '';
            lastBarcodeMohId = null;
            setStatus('');
            if (resultsBox) {
                resultsBox.innerHTML = '';
                resultsBox.style.display = 'none';
            }
            hideActionButtons();
            form.style.display = 'none';
            if (triggerWrap) triggerWrap.style.display = '';
        });
    }
})();
</script>
