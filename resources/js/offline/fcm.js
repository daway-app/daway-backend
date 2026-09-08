/* Daway offline — FCM web push: تسجيل توكن المتصفح لصيدلية مسجلة الدخول.
   يعمل فقط إذا حُقنت إعدادات Firebase في الصفحة (env على السيرفر). */
const VAPID_STORAGE_KEY = 'fcm_vapid_key';
const TOKEN_STORAGE_KEY = 'fcm_last_sent_token';

function stableDeviceId() {
    let id = localStorage.getItem('daway_device_id');
    if (!id) {
        id = (crypto && crypto.randomUUID)
            ? crypto.randomUUID()
            : 'web-' + Date.now() + '-' + Math.random().toString(16).slice(2);
        localStorage.setItem('daway_device_id', id);
    }
    return id;
}

async function registerToken(messaging, vapidKey) {
    try {
        // تسجيل SW الرسائل بمساره الخاص + التهيئة عبر query
        const config = window.DAWAY_FIREBASE_CONFIG || null;
        const swUrl = '/firebase-messaging-sw.js'
            + (config ? '?config=' + encodeURIComponent(JSON.stringify(config)) : '');
        const registration = await navigator.serviceWorker.register(swUrl);

        const token = await getToken(messaging, {
            vapidKey,
            serviceWorkerRegistration: registration,
        });
        if (!token) return;

        // لا تكرر الطلب إن لم يتغير التوكن
        if (localStorage.getItem(TOKEN_STORAGE_KEY) === token) return;

        const response = await fetch('/api/device-tokens', {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                'Accept': 'application/json',
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || '',
                'X-Requested-With': 'XMLHttpRequest',
            },
            body: JSON.stringify({
                token,
                platform: 'web',
                device_id: stableDeviceId(),
            }),
        });

        if (response.ok) {
            localStorage.setItem(TOKEN_STORAGE_KEY, token);
        }
    } catch (e) { /* التسجيل اختياري — لا يؤثر على أي وظيفة أخرى */ }
}

async function initFcm() {
    try {
        const config = window.DAWAY_FIREBASE_CONFIG;
        const vapidKey = window.DAWAY_FCM_VAPID_KEY;
        if (!config || !vapidKey) return; // Firebase غير مُهيأ على السيرفر
        if (!('Notification' in window)) return;

        // المريض/الصيدلية فقط بعد تسجيل الدخول — الصفحات العامة بلا توكن
        if (!document.body.classList.contains('authed-user')) return;

        // استيراد SDK ديناميكياً (يقلل الحمل عند عدم تفعيل الإشعارات)
        const [{ initializeApp, getApps }, messagingModule] = await Promise.all([
            import('firebase/app'),
            import('firebase/messaging'),
        ]);
        const app = getApps().length ? getApps()[0] : initializeApp(config);
        const messaging = messagingModule.getMessaging(app);

        if (Notification.permission === 'granted') {
            await registerToken(messaging, vapidKey);
        } else if (Notification.permission === 'default') {
            // نطلب الإذن عند أول تفاعل من المستخدم (متطلبات المتصفحات)
            const ask = () => {
                document.removeEventListener('click', ask);
                Notification.requestPermission().then((permission) => {
                    if (permission === 'granted') registerToken(messaging, vapidKey);
                });
            };
            document.addEventListener('click', ask, { once: true });
        }

        // رسائل الصفحة الأمامية (عند فتح التطبيق)
        messagingModule.onMessage(messaging, (payload) => {
            const title = (payload.notification && payload.notification.title) || 'إشعار من دوائي';
            const body = (payload.notification && payload.notification.body) || '';
            if (Notification.permission === 'granted') {
                new Notification(title, {
                    body,
                    icon: '/icons/icon-192.png',
                    dir: 'rtl',
                    lang: 'ar',
                });
            }
        });
    } catch (e) { /* non-fatal */ }
}

export const fcm = { initFcm };
