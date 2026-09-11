@extends('layouts.app')

@section('title', __('categories.create_title'))

@section('content')
    @vite(['resources/css/pages/medicines_edit.css'])

    <div class="edit-medicine-page-wrapper">
        <div class="page-heading">
            <div class="page-heading-icon">
                <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="12" y1="5" x2="12" y2="19"></line><line x1="5" y1="12" x2="19" y2="12"></line></svg>
            </div>
            <div>
                <h1>@lang('categories.create_title')</h1>
                <p>@lang('categories.main_description')</p>
            </div>
        </div>

        <form action="{{ route('categories.store') }}" method="POST" enctype="multipart/form-data">
            @csrf

            <div class="premium-card">
                <div class="card-head">
                    <div class="card-head-content">
                        <div class="card-icon teal">
                            <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="7" height="7"></rect><rect x="14" y="3" width="7" height="7"></rect><rect x="14" y="14" width="7" height="7"></rect><rect x="3" y="14" width="7" height="7"></rect></svg>
                        </div>
                        <div>
                            <h2>@lang('categories.basic_data')</h2>
                        </div>
                    </div>
                </div>
                <div class="card-body">
                    <div class="form-row">
                        <div class="fg">
                            <label class="fl" for="name_ar">@lang('categories.name_ar_label') <span class="req">*</span></label>
                            <input class="fc" type="text" id="name_ar" name="name_ar" value="{{ old('name_ar') }}" required>
                            @error('name_ar')<span style="display:block;color:#e11d48;font-size:.8rem;margin-top:4px;">{{ $message }}</span>@enderror
                        </div>
                        <div class="fg">
                            <label class="fl" for="name_en">@lang('categories.name_en_label') <span class="req">*</span></label>
                            <input class="fc" type="text" id="name_en" name="name_en" value="{{ old('name_en') }}" required>
                            @error('name_en')<span style="display:block;color:#e11d48;font-size:.8rem;margin-top:4px;">{{ $message }}</span>@enderror
                        </div>
                    </div>
                    <div class="form-row">
                        <div class="fg">
                            <label class="fl" for="slug">@lang('categories.slug_label')</label>
                            <input class="fc" type="text" id="slug" name="slug" value="{{ old('slug') }}">
                            <small style="color:#94a3b8;font-size:11px;">@lang('categories.slug_hint')</small>
                            @error('slug')<span style="display:block;color:#e11d48;font-size:.8rem;margin-top:4px;">{{ $message }}</span>@enderror
                        </div>
                        <div class="fg">
                            <label class="fl" for="sort_order">@lang('categories.sort_order_label')</label>
                            <input class="fc" type="number" id="sort_order" name="sort_order" value="{{ old('sort_order', 0) }}" min="0">
                            @error('sort_order')<span style="display:block;color:#e11d48;font-size:.8rem;margin-top:4px;">{{ $message }}</span>@enderror
                        </div>
                    </div>
                    <div class="fg">
                        <label class="fl" style="display:flex;align-items:center;gap:8px;cursor:pointer;">
                            <input type="hidden" name="is_active" value="0">
                            <input type="checkbox" name="is_active" value="1" {{ old('is_active', true) == 1 ? 'checked' : '' }}>
                            <span>@lang('categories.is_active_label')</span>
                        </label>
                    </div>
                    <div class="fg">
                        <label class="fl" for="image">@lang('categories.image_label')</label>
                        <input class="fc" type="file" id="image" name="image" accept="image/jpeg,image/png,image/webp" style="height:auto;padding:10px;">
                        @error('image')<span style="display:block;color:#e11d48;font-size:.8rem;margin-top:4px;">{{ $message }}</span>@enderror
                    </div>
                </div>
            </div>

            <div class="form-actions">
                <a href="{{ route('categories.index') }}" class="btn-cancel">@lang('categories.cancel_button')</a>
                <button type="submit" class="btn-submit">@lang('categories.save_button')</button>
            </div>
        </form>
    </div>
@endsection
