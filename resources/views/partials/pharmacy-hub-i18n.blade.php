@php
    $phHubI18n = [
        'status' => [
            'available' => __('pharmacy.status.available'),
            'low' => __('pharmacy.status.low'),
            'out' => __('pharmacy.status.out'),
        ],
    ];
@endphp
<script>
    window.phHubI18n = @json($phHubI18n);
</script>