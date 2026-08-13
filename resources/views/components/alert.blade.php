@if(session('success'))
    <div class="alert alert-ok">
        ✅ {{ session('success') }}
    </div>
@endif

@if(session('error'))
    <div class="alert alert-err">
        ⚠️ {{ session('error') }}
    </div>
@endif

@if($errors->any())
    <div class="alert alert-err">
        <ul style="margin-right: 18px;">
            @foreach($errors->all() as $error)
                <li>{{ $error }}</li>
            @endforeach
        </ul>
    </div>
@endif
