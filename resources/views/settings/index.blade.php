@extends('layouts.app')

@section('title', __('settings.title'))

@section('content')
    @vite(['resources/css/settings.css'])

    <div class="settings-wrapper">
        @if(session('success'))
            <div style="background: #d1fae5; border: 1px solid #10b981; color: #065f46; padding: 14px 20px; border-radius: 12px; margin-bottom: 20px; font-weight: 600;">
                {{ session('success') }}
            </div>
        @endif

        <div class="settings-header">
            <div class="settings-title-group">
                <h2>@lang('settings.main_heading')</h2>
                <p>@lang('settings.main_description', ['site_name' => $settings['site_name'] ?? 'Daway'])</p>
            </div>
            <button type="submit" form="settingsForm" class="btn-primary">@lang('settings.save_changes')</button>
        </div>

        <div class="tabs-nav">
            <button class="tab-btn active" onclick="switchTab(event, 'general')">@lang('settings.general_data_tab')</button>
            <button class="tab-btn" onclick="switchTab(event, 'pharmacies')">@lang('settings.pharmacies_tab')</button>
            <button class="tab-btn" onclick="switchTab(event, 'notifications')">@lang('settings.notifications_tab')</button>
            <button class="tab-btn" onclick="switchTab(event, 'security')">@lang('settings.security_tab')</button>
        </div>

        <form id="settingsForm" action="{{ route('settings.update') }}" method="POST">
            @csrf

            <!-- 1. General Tab -->
            <div id="general" class="tab-pane active">
                <div class="settings-card">
                    <div class="card-section-title">@lang('settings.basic_app_info')</div>
                    <div class="card-section-desc">@lang('settings.basic_app_info_desc')</div>

                    <div class="form-grid">
                        <div class="form-group">
                            <label class="form-label">@lang('settings.platform_name')</label>
                            <input type="text" name="site_name" class="form-control" value="{{ $settings['site_name'] ?? __('settings.platform_name_default') }}">
                        </div>
                        <div class="form-group">
                            <label class="form-label">@lang('settings.support_email')</label>
                            <input type="email" name="support_email" class="form-control" value="{{ $settings['support_email'] ?? 'support@daway.com' }}">
                        </div>
                        <div class="form-group">
                            <label class="form-label">@lang('settings.contact_whatsapp')</label>
                            <input type="text" name="support_phone" class="form-control" value="{{ $settings['support_phone'] ?? '+970 599 000 000' }}">
                        </div>
                        <div class="form-group">
                            <label class="form-label">@lang('settings.default_language')</label>
                            <select name="default_language" class="form-control">
                                <option value="ar" {{ ($settings['default_language'] ?? 'ar') == 'ar' ? 'selected' : '' }}>@lang('settings.lang_ar')</option>
                                <option value="en" {{ ($settings['default_language'] ?? '') == 'en' ? 'selected' : '' }}>@lang('settings.lang_en')</option>
                            </select>
                        </div>
                        <div class="form-group full-width">
                            <label class="form-label">@lang('settings.short_description')</label>
                            <textarea name="site_description" class="form-control" rows="3">{{ $settings['site_description'] ?? __('settings.short_description_default') }}</textarea>
                        </div>
                    </div>
                </div>
            </div>

            <!-- 2. Pharmacies Tab -->
            <div id="pharmacies" class="tab-pane">
                <div class="settings-card">
                    <div class="card-section-title">@lang('settings.pharmacy_controls_search_rules')</div>
                    <div class="card-section-desc">@lang('settings.pharmacy_controls_search_rules_desc')</div>

                    <div class="form-grid" style="margin-bottom: 20px;">
                        <div class="form-group">
                            <label class="form-label">@lang('settings.max_search_radius')</label>
                            <input type="number" name="max_search_radius" class="form-control" value="{{ $settings['max_search_radius'] ?? 15 }}">
                        </div>
                        <div class="form-group">
                            <label class="form-label">@lang('settings.max_medicine_results')</label>
                            <input type="number" name="search_limit" class="form-control" value="{{ $settings['search_limit'] ?? 50 }}">
                        </div>
                    </div>

                    <div style="display: flex; flex-direction: column; gap: 12px;">
                        <div class="toggle-item">
                            <div class="toggle-info">
                                <strong>@lang('settings.auto_approve_pharmacies')</strong>
                                <span>@lang('settings.auto_approve_pharmacies_desc')</span>
                            </div>
                            <label class="switch">
                                <input type="checkbox" name="auto_approve_pharmacies" {{ !empty($settings['auto_approve_pharmacies']) ? 'checked' : '' }}>
                                <span class="slider"></span>
                            </label>
                        </div>

                        <div class="toggle-item">
                            <div class="toggle-info">
                                <strong>@lang('settings.show_inactive_pharmacies')</strong>
                                <span>@lang('settings.show_inactive_pharmacies_desc')</span>
                            </div>
                            <label class="switch">
                                <input type="checkbox" name="show_inactive_pharmacies" {{ !empty($settings['show_inactive_pharmacies']) ? 'checked' : '' }}>
                                <span class="slider"></span>
                            </label>
                        </div>
                    </div>
                </div>
            </div>

            <!-- 3. Notifications Tab -->
            <div id="notifications" class="tab-pane">
                <div class="settings-card">
                    <div class="card-section-title">@lang('settings.alerts_messages_settings')</div>
                    <div class="card-section-desc">@lang('settings.alerts_messages_settings_desc')</div>

                    <div style="display: flex; flex-direction: column; gap: 12px;">
                        <div class="toggle-item">
                            <div class="toggle-info">
                                <strong>@lang('settings.low_stock_alert')</strong>
                                <span>@lang('settings.low_stock_alert_desc')</span>
                            </div>
                            <label class="switch">
                                <input type="checkbox" name="notify_low_stock" {{ !empty($settings['notify_low_stock']) ? 'checked' : '' }}>
                                <span class="slider"></span>
                            </label>
                        </div>

                        <div class="toggle-item">
                            <div class="toggle-info">
                                <strong>@lang('settings.email_notifications_new_operations')</strong>
                                <span>@lang('settings.email_notifications_new_operations_desc')</span>
                            </div>
                            <label class="switch">
                                <input type="checkbox" name="email_notifications" {{ !empty($settings['email_notifications']) ? 'checked' : '' }}>
                                <span class="slider"></span>
                            </label>
                        </div>
                    </div>
                </div>
            </div>

            <!-- 4. Security Tab -->
            <div id="security" class="tab-pane">
                <div class="settings-card">
                    <div class="card-section-title">@lang('settings.maintenance_security')</div>
                    <div class="card-section-desc">@lang('settings.maintenance_security_desc')</div>

                    <div style="display: flex; flex-direction: column; gap: 12px; margin-bottom: 24px;">
                        <div class="toggle-item" style="border-color: #fecaca; background: #fff5f5;">
                            <div class="toggle-info">
                                <strong style="color: #991b1b;">@lang('settings.enable_maintenance_mode')</strong>
                                <span style="color: #991b1b;">@lang('settings.enable_maintenance_mode_desc')</span>
                            </div>
                            <label class="switch">
                                <input type="checkbox" name="maintenance_mode" {{ !empty($settings['maintenance_mode']) ? 'checked' : '' }}>
                                <span class="slider"></span>
                            </label>
                        </div>
                    </div>

                    <div class="form-grid">
                        <div class="form-group">
                            <label class="form-label">@lang('settings.session_timeout')</label>
                            <input type="number" name="session_timeout" class="form-control" value="{{ $settings['session_timeout'] ?? 120 }}">
                        </div>
                        <div class="form-group">
                            <label class="form-label">@lang('settings.next_backup')</label>
                            <input type="text" class="form-control" value="@lang('settings.next_backup_value')" disabled style="background: #f1f5f9;">
                        </div>
                    </div>
                </div>
            </div>

            <div style="display: flex; justify-content: flex-end; gap: 12px; margin-top: 10px;">
                <button type="reset" class="btn-secondary">@lang('settings.cancel_changes')</button>
                <button type="submit" class="btn-primary">@lang('settings.save_changes')</button>
            </div>
        </form>
    </div>

    <script>
        function switchTab(event, tabId) {
            event.preventDefault();
            document.querySelectorAll('.tab-pane').forEach(pane => pane.classList.remove('active'));
            document.querySelectorAll('.tab-btn').forEach(btn => btn.classList.remove('active'));
            document.getElementById(tabId).classList.add('active');
            event.currentTarget.classList.add('active');
        }
    </script>
@endsection
