@php
    $phHubI18n = [
        'status' => [
            'available' => __('pharmacy.status.available'),
            'low' => __('pharmacy.status.low'),
            'out' => __('pharmacy.status.out'),
        ],
        'map' => [
            'confirm_title' => __('pharmacy.map.confirm_title'),
            'confirm_message' => __('pharmacy.map.confirm_message'),
            'confirm_ok' => __('pharmacy.map.confirm_ok'),
        ],
    ];
@endphp
<script>
    window.phHubI18n = @json($phHubI18n);
</script>