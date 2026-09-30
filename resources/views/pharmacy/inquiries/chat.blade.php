@extends('layouts.app')

@section('title', __('pharmacy.inquiries.chat_title'))

@section('content')
    @vite(['resources/css/pages/pharmacy_hub.css', 'resources/js/pharmacy_hub.js'])
    @include('partials.pharmacy-hub-i18n')

    <div class='ph-page'>
        <div class='ph-head'>
            <div class='ph-page-title'>
                <h1><i class='fas fa-comment-dots' style='color:var(--ph-teal-text);margin-inline-end:10px;'></i>@lang('pharmacy.inquiries.chat_title')</h1>
                <p>@lang('pharmacy.inquiries.chat_subtitle')</p>
            </div>
            <div class='ph-actions'>
                <a href='{{ route('pharmacy.inquiries.index') }}' class='ph-btn ghost'><i class='fas fa-arrow-right'></i> @lang('pharmacy.inquiries.back_to_inquiries')</a>
            </div>
        </div>

        @if (session('success'))
            <div class='ph-card' style='margin-block-end:20px;background:var(--ph-green-bg);color:var(--ph-green);border-color:var(--ph-green-bg);padding:14px 18px;'>{{ session('success') }}</div>
        @endif
        @if (session('error'))
            <div class='ph-card' style='margin-block-end:20px;background:var(--ph-red-bg);color:var(--ph-red);border-color:var(--ph-red-bg);padding:14px 18px;'>{{ session('error') }}</div>
        @endif

        {{-- Conversation header --}}
        <div class='ph-card' style='margin-block-end:20px;'>
            <div class='ph-card-head' style='border-block-end:none;'>
                <div style='display:flex;align-items:center;gap:12px;'>
                    <div style='display:flex;align-items:center;gap:8px;'>
                        <i class='fas fa-user' style='color:var(--ph-teal-text);'></i>
                        <strong style='font-size:1rem;'>{{ $inquiry->user->name ?? __('pharmacy.inquiries.patient_fallback') }}</strong>
                    </div>
                    <span class='ph-badge {{ ($inquiry->status === 'new') ? 'new' : (($inquiry->status === 'answered') ? 'ans' : 'closed') }}' style='text-transform:capitalize;'>
                        {{ $inquiry->status }}
                    </span>
                </div>
                @if($inquiry->medicine)
                    <div style='font-size:.85rem;color:var(--ph-ink-faint);'>
                        <i class='fas fa-pills' style='margin-inline-end:6px;'></i>
                        {{ $inquiry->medicine->trade_name }}
                        @if($inquiry->medicine->strength) <small style='color:var(--ph-ink-faint);'>{{ $inquiry->medicine->strength }}</small>@endif
                    </div>
                @endif
            </div>
        </div>

        {{-- Messages container --}}
        <div class='ph-card ph-chat-messages' id='ph-chat-messages'>
            <div class='ph-card-body' style='padding:0;overflow-y:auto;max-height:60vh;min-height:200px;'>
                @forelse($messages as $msg)
                    @php
                        $isPharmacy = $msg->sender_user_id === $pharmacy->user?->id;
                    @endphp
                    <div class='ph-chat-bubble {{ $isPharmacy ? "ph-chat-mine" : "ph-chat-other" }}' data-msg-id='{{ $msg->id }}'>
                        <div class='ph-chat-text'>
                            {{ $msg->message }}
                        </div>
                        <div class='ph-chat-meta'>
                            <span class='ph-chat-time'>{{ $msg->created_at->format('h:i A') }}</span>
                            @if(!$isPharmacy && $msg->read_at !== null)
                                <i class='fas fa-check-double' title='@lang("pharmacy.inquiries.msg_read")' style='font-size:.7rem;'></i>
                            @endif
                        </div>
                    </div>
                @empty
                    <div class='ph-empty' style='padding:40px 20px;'>
                        <i class='fas fa-message' style='font-size:2rem;color:var(--ph-ink-faint);margin-block-end:10px;'></i>
                        <h3>@lang('pharmacy.inquiries.chat_no_messages')</h3>
                    </div>
                @endforelse
            </div>
        </div>

        {{-- Reply form --}}
        <form action='{{ route("pharmacy.inquiries.chat.send", $inquiry) }}' method='POST' class='ph-chat-input-wrap'>
            @csrf
            <div class='ph-chat-input-row'>
                <input type='text' name='message' value='{{ old('message') }}'
                       placeholder='@lang("pharmacy.inquiries.chat_send_placeholder")'
                       maxlength='1000' autocomplete='off' required
                       style='flex:1;background:var(--ph-paper);border:1px solid var(--ph-line-soft);border-radius:var(--ph-r-md);padding:12px 16px;font-size:.95rem;color:var(--ph-ink);outline:none;transition:var(--ph-tr);'>
                <button type='submit' class='ph-btn primary' style='border-radius:var(--ph-r-md);padding:12px 20px;font-size:.95rem;'>
                    <i class='fas fa-paper-plane'></i>
                </button>
            </div>
        </form>
    </div>
@endsection

@push('styles')
<style>
.ph-chat-messages .ph-chat-bubble {
    display: inline-block;
    max-width: 70%;
    padding: 10px 16px;
    border-radius: var(--ph-r-lg);
    margin: 8px 0;
    position: relative;
}
.ph-chat-bubble .ph-chat-text {
    font-size: .95rem;
    color: var(--ph-ink);
    word-break: break-word;
}
.ph-chat-bubble .ph-chat-meta {
    display: flex;
    align-items: center;
    gap: 6px;
    margin-top: 4px;
    font-size: .72rem;
    color: var(--ph-ink-faint);
}
.ph-chat-bubble .ph-chat-time { font-variant-numeric: tabular-nums; }

.ph-chat-bubble.ph-chat-mine {
    background: var(--ph-teal-mist);
    border: 1px solid var(--ph-teal-mist);
    margin-inline-start: auto;
    border-start-start-radius: 2px;
    border-end-start-radius: var(--ph-r-lg);
}
.ph-chat-bubble.ph-chat-mine .ph-chat-text { color: var(--ph-teal-text); }
.ph-chat-bubble.ph-chat-mine .ph-chat-meta { justify-content: flex-end; color: var(--ph-teal-text); opacity: .7; }

.ph-chat-bubble.ph-chat-other {
    background: var(--ph-canvas);
    border: 1px solid var(--ph-line-soft);
    margin-inline-end: auto;
    border-start-end-radius: 2px;
    border-end-end-radius: var(--ph-r-lg);
}
.ph-chat-bubble.ph-chat-other .ph-chat-text { color: var(--ph-ink); }

.ph-chat-input-wrap {
    margin-top: 16px;
}
.ph-chat-input-row {
    display: flex;
    gap: 10px;
    align-items: center;
}
.ph-chat-input-row input:focus {
    border-color: var(--ph-teal);
    box-shadow: 0 0 0 3px var(--ph-teal-mist);
}

/* RTL adjustments */
[dir="rtl"] .ph-chat-bubble.ph-chat-mine {
    border-start-start-radius: var(--ph-r-lg);
    border-end-start-radius: 2px;
}
[dir="rtl"] .ph-chat-bubble.ph-chat-other {
    border-start-end-radius: var(--ph-r-lg);
    border-end-end-radius: 2px;
}

@media(max-width:768px){
    .ph-chat-messages .ph-chat-bubble { max-width: 85%; }
    .ph-chat-input-row { flex-direction: column; }
    .ph-chat-input-row input { width: 100%; }
}
</style>
@endpush
