@forelse($categories as $category)
    <tr>
        <td>
            <div style="display: flex; align-items: center; gap: 12px;">
                @if($category->image)
                    <img src="{{ \App\Support\Image::thumbUrl($category->image, 84, 84) }}" alt="{{ $category->name_ar }}" width="42" height="42" loading="lazy" decoding="async" style="width:42px;height:42px;object-fit:cover;border-radius:10px;">
                @else
                    <div class="pill-icon-wrapper icon-cyan"><svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="7" height="7"></rect><rect x="14" y="3" width="7" height="7"></rect><rect x="14" y="14" width="7" height="7"></rect><rect x="3" y="14" width="7" height="7"></rect></svg></div>
                @endif
                <div>
                    <strong>{{ $category->name_ar }}</strong><br>
                    <small style="color: #94a3b8; font-size: 11px;">{{ $category->name_en }} • {{ $category->slug }}</small>
                    @if($category->subcategories->isNotEmpty())
                        <div style="margin-top:6px;display:flex;flex-wrap:wrap;gap:4px;">
                            @foreach($category->subcategories as $sub)
                                <span style="font-size:10px;background:var(--field-bg,#F1F5F9);color:#64748b;border-radius:6px;padding:2px 6px;">{{ $sub->name_ar }}</span>
                            @endforeach
                        </div>
                    @endif
                </div>
            </div>
        </td>
        <td>
            <form action="{{ route('categories.toggleStatus', $category->id) }}" method="POST">
                @csrf
                @method('PATCH')
                <button type="submit" class="pill-badge status-badge {{ $category->is_active ? 'available' : 'out' }}" style="border:none;cursor:pointer;font-family:inherit;">● {{ $category->is_active ? __('categories.status_active') : __('categories.status_inactive') }}</button>
            </form>
        </td>
        <td>{{ $category->sort_order }}</td>
        <td><strong style="color: #1C72A6;">{{ $category->category_medicine_links_count }}</strong></td>
        <td>
            @if(($category->needs_review_count ?? 0) > 0)
                <a href="{{ route('categories.show', $category->id) }}?review=1" class="pill-badge status-badge out" style="text-decoration:none;border:none;cursor:pointer;font-family:inherit;" title="@lang('categories.needs_review_badge')">
                    ● {{ $category->needs_review_count }}
                </a>
            @else
                <span style="color:#94a3b8;font-size:13px;">—</span>
            @endif
        </td>
        <td>
            <div class="action-btn-group">
                <a href="{{ route('categories.show', $category->id) }}" class="action-btn" title="@lang('categories.tooltip_manage')" style="color: #1C72A6;"><svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"></path><circle cx="12" cy="12" r="3"></circle></svg></a>
                <a href="{{ route('categories.edit', $category->id) }}" class="action-btn edit" title="@lang('categories.tooltip_edit')"><svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"></path><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"></path></svg></a>
                <form action="{{ route('categories.destroy', $category->id) }}" method="POST" onsubmit="return confirm('@lang('categories.delete_confirm')');">
                    @csrf
                    @method('DELETE')
                    <button type="submit" class="action-btn delete" title="@lang('categories.tooltip_delete')"><svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="3 6 5 6 21 6"></polyline><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"></path><line x1="10" y1="11" x2="10" y2="17"></line><line x1="14" y1="11" x2="14" y2="17"></line></svg></button>
                </form>
            </div>
        </td>
    </tr>
@empty
    <tr>
        <td colspan="6" style="text-align: center; padding: 24px; color: #94a3b8;">
            @lang('categories.no_categories_found')
        </td>
    </tr>
@endforelse
