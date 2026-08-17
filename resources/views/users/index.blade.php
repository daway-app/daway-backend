@extends('layouts.app')

@section('title', __('users.title'))

@section('content')
    @vite(['resources/css/pages/users.css'])

    <div class="animated-page">

        <!-- 1. Top Header -->
        <div class="top-header-bar">
            <div class="header-title-section">
                <h1>@lang('users.main_heading')</h1>
                <p>@lang('users.main_description')</p>
            </div>
            <div class="header-actions">
                <a href="{{ route('users.create') }}" class="btn-add-pharmacy hover-shimmer" style="text-decoration: none;">
                    <svg class="btn-icon" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                        <line x1="12" y1="5" x2="12" y2="19"></line>
                        <line x1="5" y1="12" x2="19" y2="12"></line>
                    </svg>
                    <span>@lang('users.add_user_button')</span>
                </a>
            </div>
        </div>

        <!-- 2. Breadcrumb Trail -->
        <div class="breadcrumb-trail">
            <a href="{{ route('dashboard') }}">@lang('users.breadcrumb_main')</a>
            <span>‹</span>
            <span>@lang('users.breadcrumb_current')</span>
        </div>

        <!-- 3. Main Layout -->
        <div class="dashboard-grid">

            <!-- Right Column: Search Bar & Users Table -->
            <div class="right-column">

                <!-- Filter & Search Bar -->
                <div class="user-filter-bar">
                    <div class="search-input-box">
                        <span class="search-icon"><svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="8"></circle><line x1="21" y1="21" x2="16.65" y2="16.65"></line></svg></span>
                        <input type="text" id="userSearchInput" placeholder="@lang('users.search_placeholder')" value="{{ $q }}" oninput="debouncedSearch()">
                    </div>

                    <div class="filter-pills">
                        <button class="pill-btn {{ $role === 'all' ? 'active' : '' }}" data-role="all" onclick="setRoleFilter('all')">@lang('users.filter_all')</button>
                        <button class="pill-btn {{ $role === 'admin' ? 'active' : '' }}" data-role="admin" onclick="setRoleFilter('admin')">@lang('users.filter_admins')</button>
                        <button class="pill-btn {{ $role === 'pharmacy' ? 'active' : '' }}" data-role="pharmacy" onclick="setRoleFilter('pharmacy')">@lang('users.filter_pharmacies')</button>
                        <button class="pill-btn {{ $role === 'patient' ? 'active' : '' }}" data-role="patient" onclick="setRoleFilter('patient')">@lang('users.filter_patients')</button>
                    </div>
                </div>

                <!-- Main Users Table Card -->
                <div class="card-box">
                    <div class="user-table-header">
                        <h3>@lang('users.users_table_heading')</h3>
                        <span id="usersTotalCount">@lang('users.users_count', ['count' => $users->total()])</span>
                    </div>

                    @if($users->isEmpty())
                        <div id="noUsersFound" class="no-results">
                            @lang('users.no_users_found')
                        </div>
                    @else
                    <div class="table-responsive">
                        <table class="custom-table" id="usersTable">
                            <thead>
                            <tr>
                                <th>@lang('users.col_user')</th>
                                <th>@lang('users.col_email')</th>
                                <th>@lang('users.col_role')</th>
                                <th>@lang('users.col_status')</th>
                                <th>@lang('users.col_last_login')</th>
                                <th style="text-align: center;">@lang('users.col_action')</th>
                            </tr>
                            </thead>
                            <tbody id="usersTableBody">
                                @foreach($users as $user)
                                <tr data-role="{{ $user->role }}" data-search="{{ $user->name }} {{ $user->phone }} {{ $user->email }}" data-user-id="{{ $user->id }}">
                                    <td>
                                        <div class="user-profile-cell">
                                            <div class="user-avatar avatar-blue">
                                                @if($user->avatar)
                                                    <img src="{{ asset('uploads/' . $user->avatar) }}" alt="{{ $user->name }}" style="width: 100%; height: 100%; border-radius: 50%; object-fit: cover;">
                                                @else
                                                    {{ mb_substr($user->name, 0, 2) }}
                                                @endif
                                            </div>
                                            <div class="user-info">
                                                <strong>{{ $user->name }}</strong>
                                                <small>{{ $user->phone }}</small>
                                            </div>
                                        </div>
                                    </td>
                                    <td>{{ $user->email }}</td>
                                    <td>
                                        @if($user->role === 'admin')
                                            <span class="role-badge role-admin">@lang('users.role_admin')</span>
                                        @elseif($user->role === 'pharmacy')
                                            <span class="role-badge role-pharmacy">@lang('users.role_pharmacy')</span>
                                        @else
                                            <span class="role-badge role-patient">@lang('users.role_patient')</span>
                                        @endif
                                    </td>
                                    <td>
                                        <div class="status-toggle">
                                            <span class="status-label {{ $user->is_active ? 'active' : 'inactive' }}">{{ $user->is_active ? __('users.status_active') : __('users.status_inactive') }}</span>
                                            <label class="switch">
                                                <input type="checkbox" {{ $user->is_active ? 'checked' : '' }} onchange="toggleUserStatus(this)">
                                                <span class="slider"></span>
                                            </label>
                                        </div>
                                    </td>
                                    <td>{{ $user->updated_at->diffForHumans() }}</td>
                                    <td style="text-align: center;">
                                        <div class="action-btn-group">
                                            <a href="{{ route('users.edit', $user->id) }}" class="action-btn edit-btn" title="@lang('users.tooltip_edit')">
                                                <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"></path><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"></path></svg>
                                            </a>

                                            <form action="{{ route('users.destroy', $user->id) }}" method="POST" onsubmit="return confirm('{{ __('users.delete_confirm') }}');" style="display: inline-block; margin: 0;">
                                                @csrf
                                                @method('DELETE')
                                                <button type="submit" class="action-btn delete-btn" title="@lang('users.tooltip_delete')">
                                                    <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="3 6 5 6 21 6"></polyline><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"></path><line x1="10" y1="11" x2="10" y2="17"></line><line x1="14" y1="11" x2="14" y2="17"></line></svg>
                                                </button>
                                            </form>
                                        </div>
                                    </td>                                </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>

                    <div class="pagination-container" id="usersPagination">
                        {{ $users->links() }}
                    </div>
                    @endif
                </div>

            </div>

            <div class="left-column">

                <div class="card-box">
                    <div class="card-title" style="justify-content: flex-start;">@lang('users.role_distribution_title')</div>
                    <div class="role-dist-list">
                        @php $roleTotal = array_sum($roleCounts); @endphp

                        <div class="role-dist-item">
                            <span class="role-dist-count">{{ $roleCounts['admin'] }}</span>
                            <div class="role-dist-bar-bg">
                                <div class="role-dist-bar-fill" style="width: {{ $roleTotal ? ($roleCounts['admin'] / $roleTotal) * 100 : 0 }}%; background-color: #3b82f6;"></div>
                            </div>
                            <span class="role-dist-label">@lang('users.filter_admins')</span>
                        </div>

                        <div class="role-dist-item">
                            <span class="role-dist-count">{{ $roleCounts['pharmacy'] }}</span>
                            <div class="role-dist-bar-bg">
                                <div class="role-dist-bar-fill" style="width: {{ $roleTotal ? ($roleCounts['pharmacy'] / $roleTotal) * 100 : 0 }}%; background-color: #06b6d4;"></div>
                            </div>
                            <span class="role-dist-label">@lang('users.filter_pharmacies')</span>
                        </div>

                        <div class="role-dist-item">
                            <span class="role-dist-count">{{ $roleCounts['patient'] }}</span>
                            <div class="role-dist-bar-bg">
                                <div class="role-dist-bar-fill" style="width: {{ $roleTotal ? ($roleCounts['patient'] / $roleTotal) * 100 : 0 }}%; background-color: #a855f7;"></div>
                            </div>
                            <span class="role-dist-label">@lang('users.filter_patients')</span>
                        </div>

                    </div>
                </div>

            </div>

        </div>

    </div>

    <div class="confirm-modal-overlay" id="statusConfirmModal" role="dialog" aria-modal="true" aria-label="@lang('users.confirm_title')">
        <div class="confirm-modal-card">
            <div class="confirm-modal-icon">!</div>
            <h3>@lang('users.confirm_title')</h3>
            <p id="statusConfirmMessage"></p>
            <div class="confirm-modal-actions">
                <button type="button" class="modal-btn" onclick="closeStatusConfirm()">@lang('users.confirm_cancel')</button>
                <button type="button" class="modal-btn primary" onclick="confirmStatusChange()">@lang('users.confirm_ok')</button>
            </div>
        </div>
    </div>

    <script>
        let currentRoleFilter = '{{ $role }}';
        let pendingToggleCheckbox = null;

        function debouncedSearch() {
            clearTimeout(window._searchTimer);
            window._searchTimer = setTimeout(function () {
                const query = document.getElementById('userSearchInput').value.trim();
                window.location.href = '{{ route('users.index') }}?role=' + encodeURIComponent(currentRoleFilter) + '&q=' + encodeURIComponent(query);
            }, 500);
        }

        function setRoleFilter(role) {
            const query = document.getElementById('userSearchInput').value.trim();
            window.location.href = '{{ route('users.index') }}?role=' + encodeURIComponent(role) + '&q=' + encodeURIComponent(query);
        }

        function toggleUserStatus(checkbox) {
            openStatusConfirm(checkbox);
        }

        function openStatusConfirm(checkbox) {
            const tr = checkbox.closest('tr');
            const userName = tr.querySelector('.user-info strong').textContent;
            const willActivate = checkbox.checked;
            const actionName = willActivate ? "{{ __('users.confirm_activate') }}" : "{{ __('users.confirm_deactivate') }}";

            pendingToggleCheckbox = checkbox;
            document.getElementById('statusConfirmMessage').textContent = actionName + ' «' + userName + '»؟';
            document.getElementById('statusConfirmModal').style.display = 'flex';
        }

        function closeStatusConfirm() {
            if (pendingToggleCheckbox) {
                pendingToggleCheckbox.checked = !pendingToggleCheckbox.checked;
            }
            document.getElementById('statusConfirmModal').style.display = 'none';
            pendingToggleCheckbox = null;
        }

        async function confirmStatusChange() {
            const checkbox = pendingToggleCheckbox;
            closeStatusConfirm();
            if (!checkbox) {
                return;
            }

            const label = checkbox.parentElement.previousElementSibling;
            const userId = checkbox.closest('tr').dataset.userId;
            const csrf = document.querySelector('meta[name="csrf-token"]').getAttribute('content');

            try {
                const response = await fetch('/users/' + userId + '/toggle-status', {
                    method: 'PATCH',
                    headers: {
                        'Content-Type': 'application/json',
                        'Accept': 'application/json',
                        'X-CSRF-TOKEN': csrf
                    },
                    body: JSON.stringify({})
                });

                const data = await response.json();
                if (!response.ok) {
                    throw new Error(data.message || 'Failed to toggle status');
                }

                checkbox.checked = data.is_active;
                label.textContent = data.is_active
                    ? "{{ __('users.status_active') }}"
                    : "{{ __('users.status_inactive') }}";
                label.className = 'status-label ' + (data.is_active ? 'active' : 'inactive');
            } catch (error) {
                checkbox.checked = !checkbox.checked;
                alert(error.message);
            }
        }
    </script>
@endsection
