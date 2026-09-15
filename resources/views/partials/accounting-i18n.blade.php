@php
    /**
     * جسر i18n لطبقة JS المحاسبية.
     *
     * ⚠️ المصفوفة تُبنى في @php ثم تُمرَّر كمتغيّر واحد إلى @json.
     * السبب: compileJson() في Blade تقصّ التعبير على أول فاصلة، فـ
     * `@json(['a' => __('k', ['x' => 1])])` تُصرَّف لـ PHP تالف بصمت.
     *
     * المفاتيح هنا = بالضبط كل ما يقرأه resources/js/accounting/*.js.
     */
    $acCommonI18n = [
        'currency' => __('accounting.common.currency'),
        'demo_notice' => __('accounting.common.mock_notice'),
        'not_available' => __('accounting.common.not_available'),
        'search_error' => __('accounting.pos.search_error'),
    ];

    $acOverviewI18n = [
        'sales_series' => [],
    ];

    $acSalesI18n = [
        'not_available' => __('accounting.common.not_available'),
    ];

    $acPosI18n = [
        'qty_label' => __('accounting.common.quantity'),
        'price_label' => __('accounting.common.unit_price'),
        'discount_label' => __('accounting.common.discount'),
        'remove_label' => __('accounting.common.remove'),
        'clear_cart_confirm' => __('accounting.pos.clear_cart_confirm'),
        'lines_required' => __('accounting.pos.lines_required'),
        'invalid_qty' => __('accounting.pos.invalid_qty'),
        'invalid_amount' => __('accounting.pos.invalid_amount'),
        'paid_exceeds_total' => __('accounting.pos.paid_exceeds_total'),
        'out_of_stock_warn' => __('accounting.pos.out_of_stock_warn'),
        'stock_available' => __('accounting.pos.stock_available'),
        'stock_none' => __('accounting.pos.stock_none'),
        'not_in_inventory' => __('accounting.pos.not_in_inventory'),
        'barcode_invalid' => __('accounting.pos.barcode_invalid'),
        'barcode_looking' => __('accounting.pos.barcode_looking'),
        'barcode_not_found' => __('accounting.pos.barcode_not_found'),
        'barcode_found' => __('accounting.pos.barcode_found'),
        'barcode_not_in_stock' => __('accounting.pos.barcode_not_in_stock'),
        'barcode_out_of_stock' => __('accounting.pos.barcode_out_of_stock'),
        'search_no_results' => __('accounting.pos.search_no_results'),
        'search_error' => __('accounting.pos.search_error'),
        'complete_sale' => __('accounting.pos.complete_sale'),
        'processing' => __('accounting.pos.processing'),
        'sale_saved' => __('accounting.pos.sale_saved'),
        'sale_no_backend' => __('accounting.pos.sale_no_backend'),
        'sale_failed' => __('accounting.pos.sale_failed'),
        'clear_cart' => __('accounting.pos.clear_cart'),
    ];

    $acInvoiceI18n = [
        'not_available' => __('accounting.common.not_available'),
    ];

    // --- الباركود: مفردات الحالة ورسائل مسار الربط/التعارض ---
    $acBarcodeI18n = [
        'field_label' => __('accounting.barcode.field_label'),
        'field_placeholder' => __('accounting.barcode.field_placeholder'),
        'scan_button' => __('accounting.barcode.scan_button'),
        'manual_button' => __('accounting.barcode.manual_button'),
        'scanned_code' => __('accounting.barcode.scanned_code'),
        'clear' => __('accounting.barcode.clear'),
        'not_every_medicine_hint' => __('accounting.barcode.not_every_medicine_hint'),
        'looking' => __('accounting.barcode.looking'),
        'lookup_failed' => __('accounting.barcode.lookup_failed'),
        'empty_code' => __('accounting.barcode.empty_code'),
        'no_stock_warning' => __('accounting.barcode.no_stock_warning'),
        'found_title' => __('accounting.barcode.found_title'),
        'found_add' => __('accounting.barcode.found_add'),
        'found_added' => __('accounting.barcode.found_added'),
        'link_saved' => __('accounting.barcode.link_saved'),
        'link_need_choice' => __('accounting.barcode.link_need_choice'),
        'link_unavailable_title' => __('accounting.barcode.link_unavailable_title'),
        'link_unavailable_body' => __('accounting.barcode.link_unavailable_body'),
        'link_search_empty' => __('accounting.barcode.link_search_empty'),
        'link_no_results' => __('accounting.barcode.link_no_results'),
        'conflict_review_note' => __('accounting.barcode.conflict_note_body'),
        'inventory_no_barcode' => __('accounting.barcode.inventory_no_barcode'),
        'status' => [
            'unknown' => __('accounting.barcode.status.unknown'),
            'pending' => __('accounting.barcode.status.pending'),
            'verified' => __('accounting.barcode.status.verified'),
            'conflict' => __('accounting.barcode.status.conflict'),
        ],
        'status_hint' => [
            'unknown' => __('accounting.barcode.status_hint.unknown'),
            'pending' => __('accounting.barcode.status_hint.pending'),
            'verified' => __('accounting.barcode.status_hint.verified'),
            'conflict' => __('accounting.barcode.status_hint.conflict'),
        ],
    ];

    // --- المسح بالهاتف: الحالات الثمانية + نصوص نافذة الاقتران ---
    $acScannerI18n = [
        'states' => [
            'waiting' => __('accounting.scanner.state_waiting'),
            'connecting' => __('accounting.scanner.state_connecting'),
            'connected' => __('accounting.scanner.state_connected'),
            'scanning' => __('accounting.scanner.state_scanning'),
            'received' => __('accounting.scanner.state_received'),
            'disconnected' => __('accounting.scanner.state_disconnected'),
            'expired' => __('accounting.scanner.state_expired'),
            'error' => __('accounting.scanner.state_error'),
        ],
        'received_label' => __('accounting.scanner.received_label'),
        'queue_label' => __('accounting.scanner.queue_label'),
        'disconnected_note' => __('accounting.scanner.disconnected_note'),
        'session_expired_note' => __('accounting.scanner.session_expired_note'),
        'device_request_title' => __('accounting.scanner.device_request_title'),
        'device_active' => __('accounting.scanner.device_active'),
        'devices_empty' => __('accounting.scanner.devices_empty'),
        'pairing_code' => __('accounting.scanner.pairing_code'),
    ];

    $acAccountingConfig = [
        'currency' => __('accounting.common.currency'),
        'demo' => true,
        'catalog' => [],
        'endpoints' => [],
    ];
@endphp
<script>
    window.acCommonI18n = @json($acCommonI18n);
    window.acOverviewI18n = @json($acOverviewI18n);
    window.acSalesI18n = @json($acSalesI18n);
    window.acPosI18n = @json($acPosI18n);
    window.acInvoiceI18n = @json($acInvoiceI18n);
    window.acBarcodeI18n = @json($acBarcodeI18n);
    window.acScannerI18n = @json($acScannerI18n);
    // إعداد أساسي — الصفحة قد توسّعه لاحقًا (تكرار التعيين كان يطمس القيم).
    window.acAccountingConfig = window.acAccountingConfig || @json($acAccountingConfig);
</script>
