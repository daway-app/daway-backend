<?php

return [
    'title' => 'System Settings',
    'main_heading' => '⚙️ General System Settings',
    'main_description' => 'Manage operational options and application settings for :site_name.',
    'save_changes' => '💾 Save Changes',
    'general_data_tab' => '🌐 General Data',
    'pharmacies_tab' => '🏥 Pharmacies & Search',
    'notifications_tab' => '🔔 Notifications & Alerts',
    'security_tab' => '🛡️ Security & Backup',

    // General Tab
    'basic_app_info' => 'Basic Application Information',
    'basic_app_info_desc' => 'Data representing the platform\'s identity to users and pharmacies.',
    'platform_name' => 'Platform Name',
    'platform_name_default' => 'Daway - Daway',
    'support_email' => 'Support Email',
    'contact_whatsapp' => 'Contact / WhatsApp Number',
    'default_language' => 'Default System Language',
    'lang_ar' => 'Arabic (Arabic)',
    'lang_en' => 'English (English)',
    'short_description' => 'Short Description of the System',
    'short_description_default' => 'An integrated electronic platform for searching medicines, managing pharmacies, and quick access to treatments.',

    // Pharmacies Tab
    'pharmacy_controls_search_rules' => 'Pharmacy Controls and Search Rules',
    'pharmacy_controls_search_rules_desc' => 'Control the geographical search radius and pharmacy joining rules.',
    'max_search_radius' => 'Maximum Pharmacy Search Radius (km)',
    'max_medicine_results' => 'Maximum Medicine Search Results',
    'auto_approve_pharmacies' => 'Auto-approve New Pharmacies',
    'auto_approve_pharmacies_desc' => 'Activating this option allows pharmacies to start directly after registration without admin review.',
    'show_inactive_pharmacies' => 'Show Inactive Pharmacies in Search',
    'show_inactive_pharmacies_desc' => 'Display closed pharmacies or those with insufficient stock in search results.',

    // Notifications Tab
    'alerts_messages_settings' => 'Alerts and Messages Settings',
    'alerts_messages_settings_desc' => 'Control notifications sent to pharmacies and users.',
    'low_stock_alert' => 'Low Stock Alert for Pharmacies',
    'low_stock_alert_desc' => 'Send an automatic notification to the pharmacy when a specific medicine\'s stock falls below 5 packs.',
    'email_notifications_new_operations' => 'Email Notifications for New Operations',
    'email_notifications_new_operations_desc' => 'Receive an email when a new pharmacy registers or a support request is made.',

    // Security Tab
    'maintenance_security' => 'Maintenance Mode and System Protection',
    'maintenance_security_desc' => 'Manage access permissions and system availability for users.',
    'enable_maintenance_mode' => 'Enable Maintenance Mode',
    'enable_maintenance_mode_desc' => 'Temporarily close the application to all users and pharmacies except system administrators.',
    'session_timeout' => 'Session Timeout (minutes)',
    'next_backup' => 'Next Backup',
    'next_backup_value' => 'Daily at 12:00 AM',
    'cancel_changes' => 'Cancel Changes',
];
