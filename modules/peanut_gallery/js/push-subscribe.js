/**
 * @file
 * Renders the "enable notifications on this device" control and manages the
 * browser's push subscription.
 *
 * Push is per-device, not per-account: being logged in on a phone tells the
 * server nothing until that particular browser has handed over a
 * subscription. So this is a button the user presses on each device rather
 * than anything that can be switched on for them server-side.
 */

((Drupal, drupalSettings, once) => {
  Drupal.behaviors.peanutGalleryPush = {
    attach(context) {
      once('pg-push', '[data-pg-push-toggle]', context).forEach((button) => {
        initToggle(button);
      });
    },
  };

  /**
   * @param {HTMLButtonElement} button
   */
  async function initToggle(button) {
    const settings = drupalSettings.peanutGallery || {};

    if (!('serviceWorker' in navigator) || !('PushManager' in window)) {
      setState(button, 'unsupported', Drupal.t('Notifications are not supported by this browser'));
      return;
    }

    // iOS only exposes PushManager once the site is running as an installed
    // Home Screen app, so say that plainly rather than letting the button
    // fail mysteriously.
    if (isIos() && !isStandalone()) {
      setState(button, 'needs-install', Drupal.t('Add this site to your Home Screen first, then reopen it to enable notifications'));
      return;
    }

    let registration;
    try {
      registration = await navigator.serviceWorker.register(settings.serviceWorkerUrl, { scope: '/' });
    } catch (e) {
      setState(button, 'error', Drupal.t('Could not start notifications on this device'));
      return;
    }

    const existing = await registration.pushManager.getSubscription();
    setState(button, existing ? 'on' : 'off');

    button.addEventListener('click', async () => {
      button.disabled = true;
      try {
        const current = await registration.pushManager.getSubscription();
        if (current) {
          await post(settings.unsubscribeUrl, { endpoint: current.endpoint }, settings.csrfToken);
          await current.unsubscribe();
          setState(button, 'off');
        } else {
          const permission = await Notification.requestPermission();
          if (permission !== 'granted') {
            setState(button, 'denied', Drupal.t('Notifications are blocked in your browser settings'));
            return;
          }
          const subscription = await registration.pushManager.subscribe({
            userVisibleOnly: true,
            applicationServerKey: urlBase64ToUint8Array(settings.vapidPublicKey),
          });
          await post(settings.subscribeUrl, subscription.toJSON(), settings.csrfToken);
          setState(button, 'on');
        }
      } catch (e) {
        setState(button, 'error', Drupal.t('Could not change notification settings'));
      } finally {
        button.disabled = false;
      }
    });
  }

  /**
   * @param {HTMLButtonElement} button
   * @param {string} state
   * @param {string} [message]
   */
  function setState(button, state, message) {
    button.dataset.pgPushState = state;
    const labels = {
      on: Drupal.t('Turn off notifications on this device'),
      off: Drupal.t('Notify me on this device'),
    };
    button.textContent = message || labels[state] || button.textContent;
    button.disabled = !!message;
  }

  /**
   * @param {string} url
   * @param {object} body
   * @param {string} token
   * @return {Promise<Response>}
   */
  function post(url, body, token) {
    return fetch(url, {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': token },
      body: JSON.stringify(body),
    });
  }

  /**
   * The VAPID key travels as base64url text but subscribe() wants bytes.
   *
   * @param {string} base64String
   * @return {Uint8Array}
   */
  function urlBase64ToUint8Array(base64String) {
    const padding = '='.repeat((4 - (base64String.length % 4)) % 4);
    const base64 = (base64String + padding).replace(/-/g, '+').replace(/_/g, '/');
    const raw = window.atob(base64);
    return Uint8Array.from([...raw].map((char) => char.charCodeAt(0)));
  }

  /**
   * @return {boolean}
   */
  function isIos() {
    return /iPad|iPhone|iPod/.test(navigator.userAgent)
      || (navigator.platform === 'MacIntel' && navigator.maxTouchPoints > 1);
  }

  /**
   * @return {boolean}
   */
  function isStandalone() {
    return window.navigator.standalone === true
      || window.matchMedia('(display-mode: standalone)').matches;
  }
})(Drupal, drupalSettings, once);
