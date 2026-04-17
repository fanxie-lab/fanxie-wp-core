import { createApp, type Component } from 'vue';
import { createPinia } from 'pinia';
import App from '@/App.vue';
import '@/styles/main.css';

const MOUNT_ID = 'fanxie-wp-core-admin';

function renderBootstrapError(mountEl: HTMLElement, message: string): void {
  // We can't trust the Vue app to mount when the bootstrap is missing, so
  // we emit a minimal plain-DOM error into the mount node using textContent
  // (never innerHTML with dynamic values).
  const wrapper = document.createElement('div');
  wrapper.setAttribute('role', 'alert');
  wrapper.style.padding = '16px';
  wrapper.style.border = '1px solid #c00';
  wrapper.style.color = '#900';
  wrapper.style.background = '#fff5f5';
  wrapper.style.fontFamily =
    'system-ui, -apple-system, "Segoe UI", Roboto, sans-serif';

  const heading = document.createElement('strong');
  heading.textContent = 'Fanxie WP Core failed to initialise.';

  const body = document.createElement('p');
  body.style.margin = '8px 0 0';
  body.textContent = message;

  wrapper.append(heading, body);
  mountEl.replaceChildren(wrapper);
}

function bootstrap(): void {
  const mountEl = document.getElementById(MOUNT_ID);
  if (!mountEl) {
    // Nothing we can do without a mount node. Log for devs.
    // eslint-disable-next-line no-console
    console.error(
      `[fanxie-wp-core] Mount node #${MOUNT_ID} not found in the DOM.`,
    );
    return;
  }

  if (!window.fanxieWPCore) {
    // eslint-disable-next-line no-console
    console.error(
      '[fanxie-wp-core] window.fanxieWPCore is missing. The PHP bootstrap did not run, or the inline script was stripped.',
    );
    renderBootstrapError(
      mountEl,
      'The plugin bootstrap data was not found on the page. Please reload, and if the problem persists, contact support.',
    );
    return;
  }

  // `.vue` SFC imports are untyped without a full IDE plugin; narrow to
  // `Component` so `createApp` stops seeing an implicit error-typed arg.
  const app = createApp(App as Component);
  app.use(createPinia());
  app.mount(mountEl);
}

// DOM is usually ready by the time our module script runs, but guard anyway.
if (document.readyState === 'loading') {
  document.addEventListener('DOMContentLoaded', bootstrap, { once: true });
} else {
  bootstrap();
}
