@extends('layouts.app')

@section('title', __('patients.title'))

@section('content')
    @vite(['resources/css/pages/patients.css'])

    <div class="patients-page-wrapper">
        <!-- 1. Header -->
        <div class="top-header-bar">
            <div class="header-title-section">
                <div class="header-title-flex">
                    <span class="header-icon-glow"><svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"></path><circle cx="9" cy="7" r="4"></circle><path d="M23 21v-2a4 4 0 0 0-3-3.87"></path><path d="M16 3.13a4 4 0 0 1 0 7.75"></path></svg></span>
                    <div>
                        <h1>@lang('patients.main_heading')</h1>
                        <p>@lang('patients.main_description')</p>
                    </div>
                </div>
            </div>
        </div>

        <!-- 2. Breadcrumb -->
        <div class="breadcrumb-trail">
            <a href="{{ route('dashboard') }}">@lang('patients.breadcrumb_main')</a>
            <span class="separator">/</span>
            <span class="current">@lang('patients.breadcrumb_current')</span>
        </div>

        <!-- 3. Stats Cards -->
        <div class="stats-grid-patients">
            <div class="stat-card-patient">
                <div class="stat-icon-patient bg-blue">
                    <svg xmlns="http://www.w3.org/2000/svg" width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"></path><circle cx="9" cy="7" r="4"></circle><path d="M23 21v-2a4 4 0 0 0-3-3.87"></path><path d="M16 3.13a4 4 0 0 1 0 7.75"></path></svg>
                </div>
                <div class="stat-content-patient">
                    <span class="stat-title">@lang('patients.total_patients')</span>
                    <h3 class="stat-value">{{ $totalPatients }}</h3>
                </div>
            </div>

            <div class="stat-card-patient">
                <div class="stat-icon-patient bg-green">
                    <svg xmlns="http://www.w3.org/2000/svg" width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="22 12 18 12 15 21 9 3 6 12 2 12"></polyline></svg>
                </div>
                <div class="stat-content-patient">
                    <span class="stat-title">@lang('patients.active_patients_this_month')</span>
                    <h3 class="stat-value">{{ $patients->count() }}</h3>
                </div>
            </div>
        </div>

        <!-- 4. Patients Table -->
        <div class="table-card-wrapper">
            <div class="table-header">
                <h3>@lang('patients.registered_patients_list')</h3>
                <div class="search-input-group search-input-large">
                    <input type="text" id="patientSearchInput" placeholder="@lang('patients.search_placeholder')">
                    <svg class="search-icon" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="8"></circle><line x1="21" y1="21" x2="16.65" y2="16.65"></line></svg>
                </div>
            </div>

            <div class="table-responsive">
                <table class="patients-table">
                    <thead>
                    <tr>
                        <th>@lang('patients.col_patient')</th>
                        <th>@lang('patients.col_email')</th>
                        <th>@lang('patients.col_phone')</th>
                        <th>@lang('patients.col_registration_date')</th>
                        <th style="text-align: center;">@lang('patients.col_actions')</th>
                    </tr>
                    </thead>
                    <tbody id="patientsTableBody">
                    @forelse($patients as $patient)
                        <tr data-search-term="{{ strtolower($patient->name . ' ' . $patient->email . ' ' . $patient->phone) }}">
                            <td>
                                <div class="user-info-cell">
                                    <div class="user-avatar-initials">
                                        {{ mb_substr($patient->name, 0, 2) }}
                                    </div>
                                    <span class="user-name-text">{{ $patient->name }}</span>
                                </div>
                            </td>
                            <td><span class="text-muted">{{ $patient->email }}</span></td>
                            <td><span class="phone-text">{{ $patient->phone }}</span></td>
                            <td>
                                <span class="date-badge" title="{{ $patient->created_at->toRfc850String() }}">
                                    {{ $patient->created_at->diffForHumans() }}
                                </span>
                            </td>
                            <td style="text-align: center;">
                                <div class="action-btn-group">
                                    <a href="{{ route('users.edit', $patient->id) }}" class="modern-action-btn" title="@lang('patients.edit_patient_tooltip')">
                                        <svg xmlns="http://www.w3.org/2000/svg" width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"></path><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"></path></svg>
                                    </a>
                                    <form action="{{ route('users.destroy', $patient->id) }}" method="POST" onsubmit="return confirm('{{ __('patients.delete_confirm') }}');" style="display: inline;">
                                        @csrf
                                        @method('DELETE')
                                        <button type="submit" class="modern-action-btn" title="@lang('patients.delete_patient_tooltip')">
                                            <svg xmlns="http://www.w3.org/2000/svg" width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><polyline points="3 6 5 6 21 6"></polyline><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"></path></svg>
                                        </button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="5">
                                <div class="empty-state">
                                    <div class="empty-state-icon"><svg xmlns="http://www.w3.org/2000/svg" width="40" height="40" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"></path><circle cx="9" cy="7" r="4"></circle><path d="M23 21v-2a4 4 0 0 0-3-3.87"></path><path d="M16 3.13a4 4 0 0 1 0 7.75"></path></svg></div>
                                    <h4>@lang('patients.no_patients_found')</h4>
                                    <p>@lang('patients.empty_state_description')</p>
                                </div>
                            </td>
                        </tr>
                    @endforelse
                    </tbody>
                </table>
            </div>

            <div class="pagination-wrapper">
                {{ $patients->links() }}
            </div>
        </div>
    </div>

    <script>
        document.addEventListener('DOMContentLoaded', function() {
            const searchInput = document.getElementById('patientSearchInput');
            const tableBody = document.getElementById('patientsTableBody');
            const rows = tableBody.querySelectorAll('tr[data-search-term]');

            if (!searchInput || !tableBody) return;

            searchInput.addEventListener('input', function(e) {
                const query = e.target.value.toLowerCase().trim();
                let visibleCount = 0;

                rows.forEach(row => {
                    const searchTerm = row.getAttribute('data-search-term') || '';

                    if (searchTerm.includes(query)) {
                        row.style.display = '';
                        visibleCount++;
                    } else {
                        row.style.display = 'none';
                    }
                });

                let noResultRow = document.getElementById('noSearchMatchRow');

                if (visibleCount === 0 && query !== '') {
                    if (!noResultRow) {
                        noResultRow = document.createElement('tr');
                        noResultRow.id = 'noSearchMatchRow';
                        noResultRow.innerHTML = `
                            <td colspan="5">
                                <div class="empty-state" style="padding: 30px; text-align: center;">
                                    <h4>@lang('patients.no_patients_found')</h4>
                                    <p>@lang('patients.no_search_results') "${e.target.value}"</p>
                                </div>
                            </td>
                        `;
                        tableBody.appendChild(noResultRow);
                    } else {
                        noResultRow.querySelector('p').textContent = `@lang('patients.no_search_results') "${e.target.value}"`;
                        noResultRow.style.display = '';
                    }
                } else if (noResultRow) {
                    noResultRow.style.display = 'none';
                }
            });
        });
    </script>
@endsection
