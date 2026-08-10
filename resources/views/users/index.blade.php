@extends('layouts.app')

@section('title', __('users.title'))

@section('content')
    @vite(['resources/css/users.css'])

    <div class="animated-page">

        <!-- 1. Top Header -->
        <div class="top-header-bar">
            <div class="header-title-section">
                <h1>@lang('users.main_heading')</h1>
                <p>@lang('users.main_description')</p>
            </div>
            <div class="header-actions">
                <div class="notification-btn" title="@lang('topbar.notifications_tooltip')"><svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"></path><path d="M13.73 21a2 2 0 0 1-3.46 0"></path></svg></div>
                <a href="{{ route('users.create') }}" class="btn-add-user" style="text-decoration: none;">
                    <span>+</span> @lang('users.add_user_button')
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
                        <input type="text" id="userSearchInput" placeholder="@lang('users.search_placeholder')" oninput="filterUsers()">
                    </div>

                    <div class="filter-pills">
                        <button class="pill-btn active" data-role="all" onclick="setRoleFilter('all', this)">@lang('users.filter_all')</button>
                        <button class="pill-btn" data-role="patient" onclick="setRoleFilter('patient', this)">@lang('users.filter_patients')</button>
                        <button class="pill-btn" data-role="pharmacy" onclick="setRoleFilter('pharmacy', this)">@lang('users.filter_pharmacies')</button>
                        <button class="pill-btn" data-role="admin" onclick="setRoleFilter('admin', this)">@lang('users.filter_admins')</button>
                    </div>
                </div>

                <!-- Main Users Table Card -->
                <div class="card-box">
                    <div class="user-table-header">
                        <h3>@lang('users.users_table_heading')</h3>
                        <span id="usersTotalCount">@lang('users.users_count', ['count' => $users->total()])</span>
                    </div>

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
                                <tr data-role="{{ $user->role }}" data-search="{{ $user->name }} {{ $user->phone }} {{ $user->email }}">
                                    <td>
                                        <div class="user-profile-cell">
                                            <div class="user-avatar avatar-blue">{{ mb_substr($user->name, 0, 2) }}</div>
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

                    <div id="noUsersFound" class="no-results" style="display: none;">
                        @lang('users.no_users_found')
                    </div>

                    <div class="pagination-container" id="usersPagination">
                        {{ $users->links() }}
                    </div>
                </div>

            </div>

            <div class="left-column">

                <div class="card-box">
                    <div class="rbac-title-badge">
                        <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"></path></svg>
                        @lang('users.rbac_matrix_title')
                    </div>
                    <span class="rbac-subtitle">@lang('users.rbac_matrix_subtitle')</span>

                    <form id="rbacForm" onsubmit="savePermissions(event)">
                        <div class="rbac-table-wrapper">
                            <table class="rbac-table">
                                <thead>
                                <tr>
                                    <th>@lang('users.col_action')</th>
                                    <th>@lang('users.role_admin')</th>
                                    <th>@lang('users.role_pharmacy')</th>
                                    <th>@lang('users.role_patient')</th>
                                    <th>@lang('users.role_guest')</th>
                                </tr>
                                </thead>
                                <tbody>
                                <tr>
                                    <td>@lang('users.permission_view_medicines')</td>
                                    <td><input type="checkbox" checked></td>
                                    <td><input type="checkbox" checked></td>
                                    <td><input type="checkbox" checked></td>
                                    <td><input type="checkbox" checked></td>
                                </tr>
                                <tr>
                                    <td>@lang('users.permission_manage_medicines')</td>
                                    <td><input type="checkbox" checked></td>
                                    <td><input type="checkbox" checked></td>
                                    <td><input type="checkbox"></td>
                                    <td><input type="checkbox"></td>
                                </tr>
                                <tr>
                                    <td>@lang('users.permission_manage_inventory')</td>
                                    <td><input type="checkbox" checked></td>
                                    <td><input type="checkbox" checked></td>
                                    <td><input type="checkbox"></td>
                                    <td><input type="checkbox"></td>
                                </tr>
                                <tr>
                                    <td>@lang('users.permission_manage_users')</td>
                                    <td><input type="checkbox" checked></td>
                                    <td><input type="checkbox"></td>
                                    <td><input type="checkbox"></td>
                                    <td><input type="checkbox"></td>
                                </tr>
                                <tr>
                                    <td>@lang('users.permission_add_pharmacy')</td>
                                    <td><input type="checkbox" checked></td>
                                    <td><input type="checkbox"></td>
                                    <td><input type="checkbox"></td>
                                    <td><input type="checkbox"></td>
                                </tr>
                                <tr>
                                    <td>@lang('users.permission_view_stats')</td>
                                    <td><input type="checkbox" checked></td>
                                    <td><input type="checkbox"></td>
                                    <td><input type="checkbox"></td>
                                    <td><input type="checkbox"></td>
                                </tr>
                                <tr>
                                    <td>@lang('users.permission_set_reminders')</td>
                                    <td><input type="checkbox" checked></td>
                                    <td><input type="checkbox"></td>
                                    <td><input type="checkbox" checked></td>
                                    <td><input type="checkbox"></td>
                                </tr>
                                <tr>
                                    <td>@lang('users.permission_smart_assistant')</td>
                                    <td><input type="checkbox" checked></td>
                                    <td><input type="checkbox"></td>
                                    <td><input type="checkbox" checked></td>
                                    <td><input type="checkbox"></td>
                                </tr>
                                </tbody>
                            </table>
                        </div>

                        <button type="submit" class="btn-save-permissions">@lang('users.save_permissions_button')</button>
                    </form>
                </div>

                <div class="card-box">
                    <div class="card-title" style="justify-content: flex-start;">@lang('users.role_distribution_title')</div>
                    <div class="role-dist-list">

                        <div class="role-dist-item">
                            <span class="role-dist-count">{{ $users->where('role', 'admin')->count() }}</span>
                            <div class="role-dist-bar-bg" style="background-color: #3b82f6;">
                                <div class="role-dist-bar-fill" style="width: {{ ($users->where('role', 'admin')->count() / $users->total()) * 100 }}%; color: #3b82f6;"></div>
                            </div>
                            <span class="role-dist-label">@lang('users.filter_admins')</span>
                        </div>

                        <div class="role-dist-item">
                            <span class="role-dist-count">{{ $users->where('role', 'pharmacy')->count() }}</span>
                            <div class="role-dist-bar-bg" style="background-color: #06b6d4;">
                                <div class="role-dist-bar-fill" style="width: {{ ($users->where('role', 'pharmacy')->count() / $users->total()) * 100 }}%; color: #06b6d4;"></div>
                            </div>
                            <span class="role-dist-label">@lang('users.filter_pharmacies')</span>
                        </div>

                        <div class="role-dist-item">
                            <span class="role-dist-count">{{ $users->where('role', 'patient')->count() }}</span>
                            <div class="role-dist-bar-bg" style="background-color: #a855f7;">
                                <div class="role-dist-bar-fill" style="width: {{ ($users->where('role', 'patient')->count() / $users->total()) * 100 }}%; color: #a855f7;"></div>
                            </div>
                            <span class="role-dist-label">@lang('users.filter_patients')</span>
                        </div>

                    </div>
                </div>

            </div>

        </div>

    </div>

    <script>
        let currentRoleFilter = 'all';
        let currentPage = 1;

        function filterUsers() {
            const query = document.getElementById('userSearchInput').value.toLowerCase().trim();
            const rows = document.querySelectorAll('#usersTableBody tr');
            let visibleCount = 0;

            rows.forEach(row => {
                const searchData = row.getAttribute('data-search').toLowerCase();
                const roleData = row.getAttribute('data-role');

                const matchesSearch = searchData.includes(query);
                const matchesRole = (currentRoleFilter === 'all' || roleData === currentRoleFilter);

                if (matchesSearch && matchesRole) {
                    row.style.display = '';
                    visibleCount++;
                } else {
                    row.style.display = 'none';
                }
            });

            const noResults = document.getElementById('noUsersFound');
            const table = document.getElementById('usersTable');
            const pagination = document.getElementById('usersPagination');

            if (visibleCount === 0) {
                noResults.style.display = 'block';
                table.style.display = 'none';
                pagination.style.display = 'none';
            } else {
                noResults.style.display = 'none';
                table.style.display = 'table';
                pagination.style.display = 'flex';
            }
        }

        function setRoleFilter(role, element) {
            currentRoleFilter = role;

            document.querySelectorAll('.pill-btn').forEach(btn => btn.classList.remove('active'));
            element.classList.add('active');

            filterUsers();
        }

        function goToPage(page) {
            currentPage = page;
            const buttons = document.querySelectorAll('.pagination-container .page-btn:not(#prevPageBtn):not(#nextPageBtn)');
            buttons.forEach((btn, index) => {
                if (index + 1 === page) {
                    btn.classList.add('active');
                } else {
                    btn.classList.remove('active');
                }
            });

            document.getElementById('prevPageBtn').classList.toggle('disabled', page === 1);
            document.getElementById('nextPageBtn').classList.toggle('disabled', page === buttons.length);
        }

        function changePage(direction) {
            const newPage = currentPage + direction;
            if (newPage >= 1 && newPage <= 2) {
                goToPage(newPage);
            }
        }

        function toggleUserStatus(checkbox) {
            const label = checkbox.parentElement.previousElementSibling;
            if (checkbox.checked) {
                label.textContent = "{{ __('users.status_active') }}";
                label.className = 'status-label active';
            } else {
                label.textContent = "{{ __('users.status_inactive') }}";
                label.className = 'status-label inactive';
            }
        }

        function savePermissions(e) {
            e.preventDefault();
            alert("{{ __('users.permissions_saved_alert') }}");
        }
    </script>
@endsection
