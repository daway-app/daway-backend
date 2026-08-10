<div class="w-64 bg-white shadow-md">
    <div class="p-4">
        <h1 class="text-2xl font-bold text-gray-800">@lang('sidebar.dashboard_title')</h1>
    </div>
    <nav class="mt-5">
        <a href="{{ route('dashboard') }}" class="flex items-center px-4 py-2 text-gray-700 hover:bg-gray-200">
            <span class="mx-4 font-medium">@lang('sidebar.dashboard')</span>
        </a>
        <a href="{{ route('pharmacies.index') }}" class="flex items-center px-4 py-2 mt-2 text-gray-700 hover:bg-gray-200">
            <span class="mx-4 font-medium">@lang('sidebar.pharmacies')</span>
        </a>
        <a href="{{ route('medicines.index') }}" class="flex items-center px-4 py-2 mt-2 text-gray-700 hover:bg-gray-200">
            <span class="mx-4 font-medium">@lang('sidebar.medicines')</span>
        </a>
        <a href="{{ route('users.index') }}" class="flex items-center px-4 py-2 mt-2 text-gray-700 hover:bg-gray-200">
            <span class="mx-4 font-medium">@lang('sidebar.users')</span>
        </a>
        <a href="{{ route('patients.index') }}" class="flex items-center px-4 py-2 mt-2 text-gray-700 hover:bg-gray-200">
            <span class="mx-4 font-medium">@lang('sidebar.patients')</span>
        </a>
        <a href="{{ route('inventory.index') }}" class="flex items-center px-4 py-2 mt-2 text-gray-700 hover:bg-gray-200">
            <span class="mx-4 font-medium">@lang('sidebar.inventory')</span>
        </a>
        <a href="{{ route('settings.index') }}" class="flex items-center px-4 py-2 mt-2 text-gray-700 hover:bg-gray-200">
            <span class="mx-4 font-medium">@lang('sidebar.system_settings')</span>
        </a>
        <a href="{{ route('logs.index') }}" class="flex items-center px-4 py-2 mt-2 text-gray-700 hover:bg-gray-200">
            <span class="mx-4 font-medium">@lang('sidebar.activity_log')</span>
        </a>
    </nav>
</div>
