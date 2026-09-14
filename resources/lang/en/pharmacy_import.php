<?php

/*
|--------------------------------------------------------------------------
| Bulk inventory import strings — English
|--------------------------------------------------------------------------
*/

return [

    // --- General ---
    'title' => 'Bulk Inventory Import',
    'subtitle' => 'Update your whole pharmacy stock from a single Excel or CSV file, reviewing every row before saving.',
    'back_to_inventory' => 'Back to inventory',

    // --- Steps ---
    'step_download' => '1) Download template',
    'step_upload' => '2) Upload file',
    'step_review' => '3) Review results',
    'step_confirm' => '4) Confirm import',

    // --- Template ---
    'template_title' => 'File template',
    'template_hint' => 'Start from an empty template, or download your current inventory and edit it.',
    'download_empty_template' => 'Download empty template',
    'download_current_inventory' => 'Download current inventory',
    'column_guide_title' => 'Columns',
    'column_guide_hint' => 'Columns marked (*) are required. Any other column is ignored with a notice.',

    // --- Columns ---
    'col_trade_name' => 'Trade name (EN)',
    'col_trade_name_ar' => 'Trade name (AR)',
    'col_active_ingredient' => 'Active ingredient',
    'col_price' => 'Price',
    'col_quantity' => 'Quantity',
    'col_barcode' => 'Barcode',
    'col_min_stock' => 'Low-stock threshold',
    'col_is_available' => 'Available',

    // --- Upload ---
    'upload_title' => 'Upload file',
    'upload_hint' => 'Supported formats: xlsx, xls, csv — up to :max MB and :rows rows.',
    'upload_choose' => 'Choose a file',
    'upload_submit' => 'Analyze file',
    'upload_processing' => 'Analyzing the file… this may take a moment.',

    // --- Summary ---
    'summary_title' => 'Preview summary',
    'summary_total' => 'Total rows',
    'summary_matched' => 'Confirmed matches',
    'summary_review' => 'Need review',
    'summary_unmatched' => 'Unknown',
    'summary_duplicate' => 'Duplicate rows',
    'summary_error' => 'Rows with errors',
    'summary_matched_hint' => 'Found directly in the catalog — updated without asking.',
    'summary_review_hint' => 'Unconfirmed suggestions or MOH catalog matches — need your decision.',
    'summary_unmatched_hint' => 'No counterpart found — link to an existing medicine, create a new one, or skip.',
    'summary_duplicate_hint' => 'Several rows point to the same medicine — choose which one wins.',
    'summary_error_hint' => 'Invalid data — will not be imported. Download the error report to fix it.',

    // --- Notices ---
    'unknown_columns_notice' => 'Unknown columns were ignored: :columns',
    'file_name' => 'File',
    'session_expires' => 'This session expires at :at.',

    // --- Table ---
    'table_title' => 'File rows',
    'filter_all' => 'All',
    'filter_needs_decision' => 'Need a decision',
    'filter_errors' => 'Errors',
    'row_number' => 'Row',
    'input_name' => 'As written in the file',
    'matched_medicine' => 'Matched medicine',
    'row_status' => 'Status',
    'row_decision' => 'Decision',
    'no_rows' => 'No rows to display.',

    // --- Row statuses ---
    'status_EXACT_EN' => 'Exact match (EN)',
    'status_EXACT_AR_NORMALIZED' => 'Exact match (AR)',
    'status_ALIAS_MATCH' => 'Saved alias',
    'status_MOH_MATCH' => 'MOH catalog',
    'status_FUZZY_MATCH' => 'Fuzzy match',
    'status_REVIEW_REQUIRED' => 'Needs review',
    'status_UNMATCHED' => 'Unknown',
    'status_INVALID_ROW' => 'Invalid row',
    'status_DUPLICATE' => 'Duplicate',

    // --- Decisions ---
    'decision_link' => 'Link to existing medicine',
    'decision_create' => 'Create a new medicine',
    'decision_skip' => 'Skip this row',
    'decision_pending' => 'Awaiting your decision',
    'decision_search_placeholder' => 'Search the catalog…',
    'decision_no_results' => 'No matching results.',
    'decision_create_hint' => 'The medicine will be created in the shared catalog using the name and active ingredient.',

    // --- Row errors ---
    'err_missing_name' => 'Trade name (EN or AR) is required.',
    'err_invalid_price' => 'Invalid price — negatives and text are not accepted.',
    'err_invalid_quantity' => 'Quantity is required and must be a non-negative whole number.',
    'err_invalid_min_stock' => 'Invalid low-stock threshold — non-negative whole number.',
    'err_invalid_is_available' => 'Unrecognized availability value (use yes/no).',

    // --- Row warnings ---
    'warn_missing_price' => 'Price is empty — it will be recorded as zero.',
    'warn_price_deviation' => 'Price deviates a lot from the official price — make sure that is intended.',

    // --- Merge ---
    'merge_title' => 'Duplicate rows',
    'merge_hint' => 'Quantity is an absolute value, so rows are never summed automatically. Pick the row that reflects reality.',
    'merge_keep_last' => 'Keep the last row',
    'merge_keep_first' => 'Keep the first row',
    'merge_skip_all' => 'Skip all rows',
    'merge_group_label' => 'Medicine: :name',
    'merge_rows_label' => 'Rows: :rows',
    'merge_pending' => 'You have not decided on this group yet.',
    'duplicate_winner' => 'Will be applied — winner of the group',
    'duplicate_loser' => 'Will be skipped — lost the merge decision',

    // --- Confirm ---
    'confirm_title' => 'Confirm import',
    'confirm_hint' => 'Nothing is written to inventory until you press the button below.',
    'confirm_commit' => 'Run import',
    'confirm_pending_notice' => ':count row(s) are still awaiting your decision.',
    'confirm_creating_notice' => ':count new medicine(s) will be created in the shared catalog.',
    'confirm_irreversible' => 'This updates inventory quantities directly.',
    'cancel_import' => 'Cancel session',
    'cancel_confirm' => 'This import will be cancelled and no changes will be saved. Continue?',
    'download_errors' => 'Download error report',

    // --- Result ---
    'done_title' => 'Import complete',
    'done_committed_rows' => 'Rows updated',
    'done_skipped_rows' => 'Rows skipped',
    'done_created_medicines' => 'New medicines',
    'done_learned_aliases' => 'Aliases saved',
    'done_low_stock' => 'Items below threshold',
    'done_back' => 'Back to inventory',

    // --- Notification ---
    'notif_import_finished' => 'Your inventory was imported: :count item(s) updated, :low below the low-stock threshold.',

    // --- Service errors (shown to the user) ---
    'error_not_previewed' => 'Decisions cannot be applied before the file is analyzed. Upload the file first.',
    'error_invalid_merge' => 'Invalid merge decision.',
    'error_invalid_action' => 'Invalid decision on one of the rows.',
    'error_unknown_medicine' => 'The medicine selected on row :row does not exist in the catalog.',
    'error_unknown_moh' => 'The MOH catalog item selected on row :row is not one of this row\'s suggestions.',
    'error_create_needs_ingredient' => 'Row :row cannot create a medicine without a trade name and active ingredient.',
    'error_invalid_row' => 'Row :row is invalid and cannot create a medicine.',
    'error_pending_rows' => ':count row(s) are still awaiting your decision before running.',
    'error_already_committed' => 'This session was already committed.',
    'error_commit_failed' => 'The import failed and no change was saved. Please try again.',
    'error_expired' => 'The import session expired. Please upload the file again.',
    'error_upload_failed' => 'The file could not be uploaded. Please try again.',
    'error_bad_extension' => 'Unsupported file format. Allowed: :allowed.',
    'error_file_too_large' => 'The file exceeds the maximum size (:max MB).',
    'error_bad_mime' => 'The file type does not match its extension. Make sure it is a real Excel or CSV file.',
    'error_empty_file' => 'The file is empty.',
    'error_missing_header' => 'Could not detect the header row. Make sure you used the template.',
    'error_missing_columns' => 'Required columns are missing: :columns.',
    'error_too_many_rows' => 'The row count exceeds the limit (:max rows).',
    'error_no_data_rows' => 'The file contains no data rows.',
    'error_unreadable_file' => 'The file could not be read. Make sure it is not corrupted and is in a supported format.',

    // --- Rate limit (HTTP 429) ---
    // The limit guards actions (upload/decide/commit/cancel), not page loads.
    'error_too_many_requests' => 'You have exceeded the inventory import rate limit. You can try again in :seconds seconds.',
    'error_too_many_requests_generic' => 'You have exceeded the inventory import rate limit. Please try again shortly.',
    'rate_limit_remaining' => ':count of :limit imports left this hour.',
    'rate_limit_exhausted' => 'You have used up your import quota. It resets automatically in :minutes minutes — no need to reload the page.',
    'rate_limit_retrying' => 'Rate limit reached — retrying automatically in :seconds seconds…',

    // --- Error report headings ---
    'export_errors_title' => 'Import error report',
    'export_status' => 'Status',
    'export_errors' => 'Errors',
    'export_warnings' => 'Warnings',
    'export_suggestions' => 'Suggestions',
    'export_matched' => 'Matched',
    'export_template_title' => 'Inventory import template',
    'export_current_title' => 'Current inventory',
    'export_no_value' => '',
    'export_list_separator' => ' | ',
];
