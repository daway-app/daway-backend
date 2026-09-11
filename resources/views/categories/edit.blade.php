@extends('layouts.app')

@section('title', __('categories.edit_title', ['name' => $category->name_ar]))

@section('content')
    @vite(['resources/css/pages/medicines_edit.css'])

    <div class="edit-medicine-page-wrapper">
        <div class="page-heading">
            <div class="page-heading-icon">
                <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"></path><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"></path></svg>
            </div>
            <div>
                <h1>@lang('categories.edit_title', ['name' => $category->name_ar])</h1>
                <p>@lang('categories.main_description')</p>
            </div>
        </div>

        <form action="{{ route('categories.update', $category->id) }}" method="POST" enctype="multipart/form-data">
            @csrf
            @method('PUT')

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
                            <input class="fc" type="text" id="name_ar" name="name_ar" value="{{ old('name_ar', $category->name_ar) }}" required>
                            @error('name_ar')<span style="display:block;color:#e11d48;font-size:.8rem;margin-top:4px;">{{ $message }}</span>@enderror
                        </div>
                        <div class="fg">
                            <label class="fl" for="name_en">@lang('categories.name_en_label') <span class="req">*</span></label>
                            <input class="fc" type="text" id="name_en" name="name_en" value="{{ old('name_en', $category->name_en) }}" required>
                            @error('name_en')<span style="display:block;color:#e11d48;font-size:.8rem;margin-top:4px;">{{ $message }}</span>@enderror
                        </div>
                    </div>
                    <div class="form-row">
                        <div class="fg">
                            <label class="fl" for="slug">@lang('categories.slug_label')</label>
                            <input class="fc" type="text" id="slug" name="slug" value="{{ old('slug', $category->slug) }}">
                            <small style="color:#94a3b8;font-size:11px;">@lang('categories.slug_hint')</small>
                            @error('slug')<span style="display:block;color:#e11d48;font-size:.8rem;margin-top:4px;">{{ $message }}</span>@enderror
                        </div>
                        <div class="fg">
                            <label class="fl" for="sort_order">@lang('categories.sort_order_label')</label>
                            <input class="fc" type="number" id="sort_order" name="sort_order" value="{{ old('sort_order', $category->sort_order) }}" min="0">
                            @error('sort_order')<span style="display:block;color:#e11d48;font-size:.8rem;margin-top:4px;">{{ $message }}</span>@enderror
                        </div>
                    </div>
                    <div class="fg">
                        <label class="fl" style="display:flex;align-items:center;gap:8px;cursor:pointer;">
                            <input type="hidden" name="is_active" value="0">
                            <input type="checkbox" name="is_active" value="1" {{ old('is_active', $category->is_active ? 1 : 0) == 1 ? 'checked' : '' }}>
                            <span>@lang('categories.is_active_label')</span>
                        </label>
                    </div>
                    <div class="fg">
                        <label class="fl" for="image">@lang('categories.image_label')</label>
                        @if($category->image)
                            <img src="{{ \App\Support\Image::url($category->image) }}" alt="{{ $category->name_ar }}" style="display:block;width:72px;height:72px;object-fit:cover;border-radius:10px;margin-bottom:8px;">
                        @endif
                        <input class="fc" type="file" id="image" name="image" accept="image/jpeg,image/png,image/webp" style="height:auto;padding:10px;">
                        @error('image')<span style="display:block;color:#e11d48;font-size:.8rem;margin-top:4px;">{{ $message }}</span>@enderror
                    </div>
                </div>
            </div>

            <div class="form-actions">
                <a href="{{ route('categories.index') }}" class="btn-cancel">@lang('categories.cancel_button')</a>
                <button type="submit" class="btn-submit">@lang('categories.update_button')</button>
            </div>
        </form>
    </div>
@endsection
