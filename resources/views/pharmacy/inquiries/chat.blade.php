@extends('layouts.app')

@php
    use Illuminate\Support\Facades\Storage;
@endphp

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
                    @if($inquiry->user && $inquiry->user->phone)
                        <span style='font-size:.85rem;color:var(--ph-ink-faint);'>
                            <i class='fas fa-phone' style='margin-inline-end:4px;'></i>{{ $inquiry->user->phone }}
                        </span>
                    @endif
                    <span class='ph-badge {{ ($inquiry->status === 'new') ? 'new' : (($inquiry->status === 'answered') ? 'ans' : 'closed') }}' style='text-transform:capitalize;'>
                        {{ $inquiry->status }}
                    </span>
                </div>
                @if($inquiry->medicine)
                    <div style='font-size:.85rem;color:var(--ph-ink-faint);margin-block-start:4px;'>
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
                            @if($msg->media_path)
                                <a href='{{ Storage::url($msg->media_path) }}' target='_blank'>
                                    <img src='{{ Storage::url($msg->media_path) }}' style='max-width:200px;max-height:200px;border-radius:8px;'>
                                </a>
                                @if($msg->message)
                                    <div style='margin-block-start:8px;'>{{ $msg->message }}</div>
                                @endif
                            @else
                                {{ $msg->message }}
                            @endif
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
        <form action='{{ route("pharmacy.inquiries.chat.send", $inquiry) }}' method='POST' enctype='multipart/form-data' class='ph-chat-input-wrap'>
            @csrf
            <div class='ph-chat-input-row'>
                <input type='text' name='message' value='{{ old('message') }}'
                       placeholder='@lang("pharmacy.inquiries.chat_send_placeholder")'
                       maxlength='1000' autocomplete='off'
                       style='flex:1;background:var(--ph-paper);border:1px solid var(--ph-line-soft);border-radius:var(--ph-r-md);padding:12px 16px;font-size:.95rem;color:var(--ph-ink);outline:none;transition:var(--ph-tr);'>
                <label for='ph-chat-media' class='ph-btn ghost' style='border-radius:var(--ph-r-md);padding:12px 16px;cursor:pointer;'>
                    <i class='fas fa-paperclip'></i>
                </label>
                <input type='file' id='ph-chat-media' name='media' accept='image/*' style='display:none;'>
                <button type='submit' class='ph-btn primary' style='border-radius:var(--ph-r-md);padding:12px 20px;font-size:.95rem;'>
                    <i class='fas fa-paper-plane'></i>
                </button>
            </div>
        </form>
    </div>

@endsection

    @push('scripts')
    <script>
    document.addEventListener('DOMContentLoaded', function() {
        const messagesContainer = document.querySelector('#ph-chat-messages .ph-card-body');
        const inquiryId = {{ $inquiry->id }};
        const pharmacyUserId = {{ $pharmacy->user_id ?? 'null' }};

        function fetchMessages() {
            fetch('/pharmacy/inquiries/' + inquiryId + '/messages', {
                method: 'GET',
                headers: {
                    'X-Requested-With': 'XMLHttpRequest',
                    'Accept': 'application/json',
                },
            })
            .then(r => r.ok ? r.json() : Promise.reject(r))
            .then(data => {
                const existingIds = new Set(
                    Array.from(messagesContainer.querySelectorAll('[data-msg-id]')).map(el => parseInt(el.dataset.msgId))
                );
                data.data.forEach(msg => {
                    if (!existingIds.has(msg.id)) {
                        const isPharmacy = msg.sender_user_id === pharmacyUserId;
                        const bubble = document.createElement('div');
                        bubble.className = 'ph-chat-bubble ' + (isPharmacy ? 'ph-chat-mine' : 'ph-chat-other');
                        bubble.dataset.msgId = msg.id;

                        let content = '<div class="ph-chat-text">';
                        if (msg.media_url) {
                            content += '<a href="' + msg.media_url + '" target="_blank"><img src="' + msg.media_url + '" style="max-width:200px;max-height:200px;border-radius:8px;"></a>';
                            if (msg.message) content += '<br><br>' + escapeHtml(msg.message);
                        } else {
                            content += escapeHtml(msg.message);
                        }
                        content += '</div>';

                        const time = new Date(msg.created_at).toLocaleTimeString([], {hour:'2-digit',minute:'2-digit'});
                        content += '<div class="ph-chat-meta"><span class="ph-chat-time">' + time + '</span></div>';

                        bubble.innerHTML = content;
                        messagesContainer.appendChild(bubble);
                    }
                });
                messagesContainer.scrollTop = messagesContainer.scrollHeight;
            })
            .catch(err => console.error('Polling error:', err));
        }

        function escapeHtml(text) {
            const div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        }

        const pollInterval = setInterval(fetchMessages, 4000);

        // Mark messages as read when page becomes visible
        function markRead() {
            fetch('/pharmacy/inquiries/' + inquiryId + '/messages?mark_read=1', {
                method: 'GET',
                headers: { 'X-Requested-With': 'XMLHttpRequest' },
                credentials: 'same-origin'
            }).then(() => {
                // After marking, fetch messages to update read states
                fetchMessages();
            }).catch(() => {});
        }
        markRead();
        document.addEventListener('visibilitychange', function() {
            if (!document.hidden) markRead();
        });
    });
    </script>
    @endpush

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
