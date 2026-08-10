/**
 * @file
 * Service worker: receives push messages and opens the right comment.
 *
 * Served by PushController::serviceWorker() rather than as a static file,
 * so it can carry a Service-Worker-Allowed header and claim the whole site
 * as its scope. It is deliberately minimal — no fetch handler, no caching.
 * Caching a Drupal site correctly offline is a project of its own, and a
 * half-working cache is worse than none: it serves stale private pages.
 * This worker exists solely to make notifications possible.
 */

self.addEventListener('push', (event) => {
  if (!event.data) {
    return;
  }

  let payload;
  try {
    payload = event.data.json();
  } catch (e) {
    payload = { title: 'Peanut Gallery', body: event.data.text() };
  }

  event.waitUntil(
    self.registration.showNotification(payload.title || 'Peanut Gallery', {
      body: payload.body || '',
      // Coalesce by recording: several comments on the same one replace
      // each other rather than stacking up a wall of notifications.
      tag: payload.tag || 'peanut-gallery',
      renotify: true,
      data: { url: payload.url || '/' },
    }),
  );
});

self.addEventListener('notificationclick', (event) => {
  event.notification.close();

  const target = (event.notification.data && event.notification.data.url) || '/';

  // Focus an already-open tab on this site instead of piling up new ones.
  event.waitUntil(
    self.clients.matchAll({ type: 'window', includeUncontrolled: true }).then((clientList) => {
      for (const client of clientList) {
        if ('focus' in client) {
          client.navigate(target);
          return client.focus();
        }
      }
      return self.clients.openWindow(target);
    }),
  );
});
