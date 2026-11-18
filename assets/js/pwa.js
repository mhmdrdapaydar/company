/* Install and browser notifications are opt-in; nothing private is cached. */
(function () {
  'use strict';
  var installPrompt = null;
  if ('serviceWorker' in navigator && window.isSecureContext) {
    navigator.serviceWorker.register('service-worker.js').catch(function () {});
    navigator.serviceWorker.addEventListener('message', function (event) {
      if (event.data && event.data.type === 'open-notifications' && window.App) window.App.go('notifications');
    });
  }
  window.addEventListener('beforeinstallprompt', function (event) {
    event.preventDefault(); installPrompt = event;
  });
  window.addEventListener('appinstalled', function () { installPrompt = null; });
  function message(text, type) {
    if (window.App && window.App.toast) window.App.toast(text, type || 'info', 8000);
  }
  document.addEventListener('DOMContentLoaded', function () {
    var install = document.getElementById('btnInstallApp');
    if (install) install.addEventListener('click', function (event) {
      event.preventDefault();
      if (window.matchMedia && window.matchMedia('(display-mode: standalone)').matches) {
        message('برنامه از قبل نصب و باز شده است.'); return;
      }
      if (installPrompt) {
        var prompt = installPrompt; installPrompt = null;
        prompt.prompt(); return;
      }
      message('در مرورگر، گزینهٔ نصب برنامه یا افزودن به صفحهٔ اصلی را انتخاب کنید. در آیفون: اشتراک‌گذاری ← افزودن به صفحهٔ اصلی. نصب به HTTPS نیاز دارد.');
    });
    var button = document.getElementById('btnDesktopNotifications');
    if (button) button.addEventListener('click', function (event) {
      event.preventDefault();
      if (!window.isSecureContext || !('Notification' in window)) {
        message('اعلان دستگاه در این مرورگر در دسترس نیست؛ اتصال HTTPS و تنظیمات مرورگر را بررسی کنید.', 'error'); return;
      }
      if (Notification.permission === 'granted') { message('اعلان دستگاه فعال است؛ برای دریافت آن، برنامه باید باز بماند.'); return; }
      if (Notification.permission === 'denied') { message('اجازهٔ اعلان مسدود است؛ آن را در تنظیمات مرورگر فعال کنید.', 'error'); return; }
      Notification.requestPermission().then(function (permission) {
        message(permission === 'granted' ? 'اعلان دستگاه فعال شد؛ برنامه باید باز بماند.' : 'اجازهٔ اعلان دستگاه داده نشد.', permission === 'granted' ? 'info' : 'error');
      }).catch(function () { message('فعال‌سازی اعلان ممکن نشد.', 'error'); });
    });
  });
})();
