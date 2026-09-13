@extends('layouts.app')

@section('title', __('categories.edit_title', ['name' => $category->name_ar]))

@section('content')
    @vite(['resources/css/pages/medicines_edit.css', 'resources/css/pages/categories.css'])

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

            <!-- Section 1: Basic -->
            <details class="cat-accordion" open>
                <summary class="cat-accordion-summary">
                    <span class="cat-accordion-icon">
                        <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="7" height="7"></rect><rect x="14" y="3" width="7" height="7"></rect><rect x="14" y="14" width="7" height="7"></rect><rect x="3" y="14" width="7" height="7"></rect></svg>
                    </span>
                    <span>@lang('categories.section_basic')</span>
                </summary>
                <div class="cat-accordion-body">
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
                            <label class="fl" style="display:flex;align-items:center;gap:8px;cursor:pointer;">
                                <input type="hidden" name="is_active" value="0">
                                <input type="checkbox" name="is_active" value="1" {{ old('is_active', $category->is_active ? 1 : 0) == 1 ? 'checked' : '' }}>
                                <span>@lang('categories.is_active_label')</span>
                            </label>
                        </div>
                    </div>
                    <div class="fg">
                        <label class="fl" for="image">@lang('categories.image_label')</label>
                        <img id="existing-image" src="{{ $category->image ? \App\Support\Image::thumbUrl($category->image, 144, 144) : '' }}" alt="{{ $category->name_ar }}" width="72" height="72" style="{{ $category->image ? 'display:block;' : 'display:none;' }}width:72px;height:72px;object-fit:cover;border-radius:10px;margin-bottom:8px;">
                        <img id="image-preview" src="" alt="" style="display:none;width:72px;height:72px;object-fit:cover;border-radius:10px;margin-bottom:8px;">
                        <input class="fc" type="file" id="image" name="image" accept="image/jpeg,image/png,image/webp" style="height:auto;padding:10px;">
                        @error('image')<span style="display:block;color:#e11d48;font-size:.8rem;margin-top:4px;">{{ $message }}</span>@enderror
                    </div>
                </div>
            </details>

            <!-- Section 2: Advanced -->
            <details class="cat-accordion">
                <summary class="cat-accordion-summary">
                    <span class="cat-accordion-icon">
                        <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="3"></circle><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1 0 2.83 2 2 0 0 1-2.83 0l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-4 0v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 0 1-2.83 0 2 2 0 0 1 0-2.83l.06-.06a1.65 1.65 0 0 0 .33-1.82 1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1 0-4h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 0 1 0-2.83 2 2 0 0 1 2.83 0l.06.06a1.65 1.65 0 0 0 1.82.33H9a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 4 0v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 0 1 2.83 0 2 2 0 0 1 0 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82V9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1z"></path></svg>
                    </span>
                    <span>@lang('categories.section_advanced')</span>
                </summary>
                <div class="cat-accordion-body">
                    <div class="fg">
                        <label class="fl" for="sort_order">@lang('categories.sort_order_label')</label>
                        <input class="fc" type="number" id="sort_order" name="sort_order" value="{{ old('sort_order', $category->sort_order) }}" min="0">
                        @error('sort_order')<span style="display:block;color:#e11d48;font-size:.8rem;margin-top:4px;">{{ $message }}</span>@enderror
                    </div>
                </div>
            </details>

            <div class="form-actions">
                <a href="{{ route('categories.index') }}" class="btn-cancel">@lang('categories.cancel_button')</a>
                <button type="submit" class="btn-submit">@lang('categories.update_button')</button>
            </div>
        </form>
    </div>

    <script>
    (function() {
        // Slug auto-generation from name_en
        var nameEn = document.getElementById('name_en');
        var slug = document.getElementById('slug');
        if (nameEn && slug) {
            var slugTouched = slug.value.trim() !== '';
            slug.addEventListener('input', function() { slugTouched = true; });
            nameEn.addEventListener('input', function() {
                if (slugTouched) return;
                slug.value = nameEn.value
                    .toLowerCase()
                    .trim()
                    .replace(/[^a-z0-9\s-]/g, '')
                    .replace(/[\s_]+/g, '-')
                    .replace(/-+/g, '-')
                    .replace(/^-|-$/g, '');
            });
        }

        // Image preview — replaces existing image when a new file is selected
        var imageInput = document.getElementById('image');
        var imagePreview = document.getElementById('image-preview');
        var existingImage = document.getElementById('existing-image');
        if (imageInput && imagePreview) {
            imageInput.addEventListener('change', function() {
                if (imageInput.files && imageInput.files[0]) {
                    imagePreview.src = URL.createObjectURL(imageInput.files[0]);
                    imagePreview.style.display = 'block';
                    if (existingImage) existingImage.style.display = 'none';
                } else {
                    imagePreview.style.display = 'none';
                    if (existingImage && existingImage.src) existingImage.style.display = 'block';
                }
            });
        }
    })();
    </script>
@endsection
