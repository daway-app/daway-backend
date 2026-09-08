/* Firebase Messaging service worker — استقبال إشعارات FCM بالخلفية.
   يُسجَّل بمساره الخاص (يجب أن يكون في جذر النطاق) ولا يمر عبر sw.js. */
importScripts('https://www.gstatic.com/firebasejs/10.14.1/firebase-app-compat.js');
importScripts('https://www.gstatic.com/firebasejs/10.14.1/firebase-messaging-compat.js');

// إن لم تُضبط إعدادات Firebase (env) — يعمل الـ SW فارغاً بلا أخطاء.
// التهيئة تُمرر عبر query string عند التسجيل من fcm.js.
if (!self.FIREBASE_CONFIG) {
  try {
    const config = JSON.parse(new URLSearchParams(self.location.search).get('config') || 'null');
    if (config) self.FIREBASE_CONFIG = config;
  } catch (e) { /* لا تهيئة — لا إشعارات بالخلفية */ }
}

try {
  if (self.FIREBASE_CONFIG) {
    firebase.initializeApp(self.FIREBASE_CONFIG);
    const messaging = firebase.messaging();

    messaging.onBackgroundMessage((payload) => {
      const title = (payload.notification && payload.notification.title) || 'إشعار من دوائي';
      const body = (payload.notification && payload.notification.body) || '';
      self.registration.showNotification(title, {
        body,
        icon: '/icons/icon-192.png',
        badge: '/icons/icon-192.png',
        dir: 'rtl',
        lang: 'ar',
        data: payload.data || {},
      });
    });
  }
} catch (e) { /* تجاهل — الإشعارات تبقى تعمل داخل الصفحة */ }
