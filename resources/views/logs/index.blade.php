@extends('layouts.app')

@section('title', __('logs.title'))

@section('content')
    @vite(['resources/css/pages/logs.css', 'resources/css/pages/medicines.css'])

    <div class="logs-wrapper">
        {{-- الترويسة المشتركة نفس نمط صفحات الأدمن الأخرى --}}
        <div class="top-header-bar">
            <div class="header-title-section">
                <h1>@lang('logs.main_heading')</h1>
                <p>@lang('logs.main_description', ['site_name' => session('app_settings.site_name', 'DAWAK')])</p>
            </div>
            <div class="header-actions">
                <a href="{{ route('logs.export.excel') }}" class="btn-export-excel">
                    @lang('logs.export_excel_button')
                </a>
            </div>
        </div>
        <div class="breadcrumb-trail">
            <a href="{{ route('dashboard') }}">@lang('categories.breadcrumb_main')</a>
            <span>‹</span>
            <span>@lang('logs.main_heading')</span>
        </div>

        <!-- Filter and Search Tools -->
        <form method="GET" action="{{ route('logs.index') }}" class="search-bar">
            <input type="text" name="q" value="{{ $q ?? request('q') }}" class="form-control" placeholder="@lang('logs.search_placeholder')" style="flex: 1; min-width: 250px;" autocomplete="off">
            <select name="event" class="form-control">
                <option value="">@lang('logs.all_activities_option')</option>
                <option value="created" @selected($event === 'created')>@lang('logs.create_option')</option>
                <option value="updated" @selected($event === 'updated')>@lang('logs.update_option')</option>
                <option value="deleted" @selected($event === 'deleted')>@lang('logs.delete_option')</option>
                <option value="auth" @selected($event === 'auth')>@lang('logs.auth_option')</option>
            </select>
            <input type="date" name="date" value="{{ $date }}" class="form-control">
        </form>

        <!-- Logs Table -->
        <div class="table-container">
            <table>
                <thead>
                <tr>
                    <th>@lang('logs.col_id')</th>
                    <th>@lang('logs.col_user')</th>
                    <th>@lang('logs.col_event_operation')</th>
                    <th>@lang('logs.col_details')</th>
                    <th>@lang('logs.col_ip_address')</th>
                    <th>@lang('logs.col_date_time')</th>
                </tr>
                </thead>
                <tbody>
                @forelse($logs as $log)
                    <tr>
                        <td>{{ $log->id }}</td>
                        <td><strong>{{ $log->causer->name ?? 'System' }}</strong></td>
                        <td>
                            @php
                                $badgeClass = 'badge-info'; // Default
                                if (str_contains(strtolower($log->description), 'created')) $badgeClass = 'badge-success';
                                if (str_contains(strtolower($log->description), 'updated')) $badgeClass = 'badge-warning';
                                if (str_contains(strtolower($log->description), 'deleted')) $badgeClass = 'badge-danger';
                            @endphp
                            <span class="badge {{ $badgeClass }}">{{ $log->description }}</span>
                        </td>
                        <td>
                            @if($log->subject)
                                {{ class_basename($log->subject_type) }} #{{ $log->subject_id }}
                            @endif
                        </td>
                        <td><code>{{ $log->properties->get('ip') ?? 'N/A' }}</code></td>
                        <td>{{ $log->created_at->diffForHumans() }}</td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="6" style="text-align: center; padding: 20px;">@lang('logs.no_logs_found')</td>
                    </tr>
                @endforelse
                </tbody>
            </table>
        </div>

        <!-- Pagination Links -->
        <div class="pagination-wrapper" style="margin-top: 20px;">
            {{ $logs->links() }}
        </div>
    </div>
@endsection
