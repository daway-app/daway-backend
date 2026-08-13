<aside class="sidebar-pro">
    @vite(['resources/css/layout/sidebar.css'])

    <div class="sidebar-content-wrapper">
        <!-- Logo Header -->
        <div class="sidebar-logo-header">
            <div class="logo-icon-box">
                <img src="{{ asset('images/dawaei-logo.jpg') }}" alt="Logo" class="sidebar-logo-img">
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
                        <span class="nav-badge">{{ \App\Models\User::count() }}</span>
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
                    @php $isPharmacyMedicineCreate = request()->routeIs('pharmacy.medicines.create'); @endphp
                    <a href="{{ route('pharmacy.medicines.create') }}" class="nav-item {{ $isPharmacyMedicineCreate ? 'active' : '' }}">
                        <span class="nav-icon"><svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="12" y1="5" x2="12" y2="19"></line><line x1="5" y1="12" x2="19" y2="12"></line></svg></span>
                        <span class="nav-text">@lang('pharmacy.sidebar.add_medicine')</span>
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
            @endif
        @endauth
    </div>

    <!-- User Profile Footer -->
    <div class="user-profile-footer">
        <div class="more-options-btn">
            <form method="POST" action="{{ route('logout') }}" id="logoutForm">
                @csrf
                <button type="submit" id="logoutBtn" style="background: none; border: none; color: inherit; cursor: pointer; font-size: inherit;" title="@lang('layout.logout_tooltip')">
                    <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="feather feather-log-out"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"></path><polyline points="16 17 21 12 16 7"></polyline><line x1="21" y1="12" x2="9" y2="12"></line></svg>
                </button>
            </form>
        </div>
        <div class="user-info-group" onclick="openProfileModal()" title="@lang('layout.edit_profile_modal_title')">
            <div class="avatar-box" id="sidebarDisplayUserAvatar">
                @if(auth()->user()->avatar)
                    <img src="{{ asset('storage/' . auth()->user()->avatar) }}" alt="User Avatar" style="width: 100%; height: 100%; border-radius: 50%; object-fit: cover;">
                @else
                    {{ mb_substr(auth()->user()->name, 0, 1) }}
                @endif
            </div>
            <div>
                <div class="user-name" id="sidebarDisplayUserName">{{ auth()->user()->name }}</div>
                <div class="user-role" id="sidebarDisplayUserRole">{{ auth()->user()->role ?? 'User' }}</div>
            </div>
        </div>
    </div>
</aside>

<div class="confirm-modal-overlay" id="logoutConfirmModal">
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
