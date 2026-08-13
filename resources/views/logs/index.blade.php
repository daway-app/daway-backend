@extends('layouts.app')

@section('title', __('logs.title'))

@section('content')
    @vite(['resources/css/pages/logs.css'])

    <div class="logs-wrapper">
        <div class="logs-header">
            <div class="logs-title-group">
                <h2>@lang('logs.main_heading')</h2>
                <p>@lang('logs.main_description', ['site_name' => session('app_settings.site_name', 'Daway')])</p>
            </div>

            <a href="{{ route('logs.export.excel') }}" class="btn-export-excel">
                @lang('logs.export_excel_button')
            </a>
        </div>

        <!-- Filter and Search Tools -->
        <div class="search-bar">
            <input type="text" class="form-control" placeholder="@lang('logs.search_placeholder')" style="flex: 1; min-width: 250px;">
            <select class="form-control">
                <option value="">@lang('logs.all_activities_option')</option>
                <option value="create">@lang('logs.create_option')</option>
                <option value="update">@lang('logs.update_option')</option>
                <option value="delete">@lang('logs.delete_option')</option>
                <option value="auth">@lang('logs.auth_option')</option>
            </select>
            <input type="date" class="form-control">
        </div>

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
