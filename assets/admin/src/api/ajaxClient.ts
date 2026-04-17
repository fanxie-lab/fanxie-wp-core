// Typed wrapper around WordPress's admin-ajax.php.
//
// Contract with the PHP side (frozen — see CLAUDE.md §3.4 and the brief):
//   action       = 'fanxie_wp_core'        (fixed — PHP dispatches on this)
//   _action      = <subAction>             (PHP AjaxRouter reads this; sanitize_key on PHP side)
//   _ajax_nonce  = window.fanxieWPCore.nonce  (verified against 'fanxie_wp_core_admin')
//
// Response envelope is WP's standard { success: boolean, data: unknown }.

const WP_ACTION = 'fanxie_wp_core' as const;

export interface AjaxOptions {
  /**
   * If true, coalesce in-flight calls with identical (subAction + payload).
   * Only safe for idempotent GET-ish calls. Default: false.
   */
  dedupe?: boolean;
  /** AbortSignal forwarded to fetch. */
  signal?: AbortSignal;
}

export class AjaxError extends Error {
  public readonly code: string;
  public override readonly message: string;
  public readonly status: number;

  constructor(code: string, message: string, status: number) {
    super(message);
    this.name = 'AjaxError';
    this.code = code;
    this.message = message;
    this.status = status;
  }
}

interface WpAjaxEnvelope {
  success: boolean;
  data: unknown;
}

function isWpAjaxEnvelope(value: unknown): value is WpAjaxEnvelope {
  return (
    typeof value === 'object' &&
    value !== null &&
    'success' in value &&
    typeof (value as { success: unknown }).success === 'boolean'
  );
}

function extractErrorFields(data: unknown): { code: string; message: string } {
  if (typeof data === 'object' && data !== null) {
    const rec = data as Record<string, unknown>;
    const code = typeof rec['code'] === 'string' ? rec['code'] : 'unknown_error';
    const message =
      typeof rec['message'] === 'string'
        ? rec['message']
        : 'The request failed without a message.';
    return { code, message };
  }
  if (typeof data === 'string' && data.length > 0) {
    return { code: 'unknown_error', message: data };
  }
  return { code: 'unknown_error', message: 'The request failed without a message.' };
}

// In-flight dedupe cache. Keyed by `${subAction}|${stableJson(payload)}`.
// Entries are cleared as soon as the promise settles.
const inFlight = new Map<string, Promise<unknown>>();

function stableStringify(value: unknown): string {
  // Deterministic-enough for dedupe keys: sort object keys shallowly.
  // Payloads here are expected to be plain, flat-ish records.
  if (value === undefined) return '';
  if (value === null || typeof value !== 'object') {
    return JSON.stringify(value);
  }
  if (Array.isArray(value)) {
    return `[${value.map(stableStringify).join(',')}]`;
  }
  const rec = value as Record<string, unknown>;
  const keys = Object.keys(rec).sort();
  return `{${keys.map((k) => `${JSON.stringify(k)}:${stableStringify(rec[k])}`).join(',')}}`;
}

function buildBody<TPayload extends Record<string, unknown>>(
  subAction: string,
  payload: TPayload | undefined,
  nonce: string,
): URLSearchParams {
  const body = new URLSearchParams();
  body.set('action', WP_ACTION);
  body.set('_action', subAction);
  body.set('_ajax_nonce', nonce);

  if (payload) {
    for (const [key, value] of Object.entries(payload)) {
      if (value === undefined) continue;
      if (value === null) {
        body.set(key, '');
        continue;
      }
      if (typeof value === 'string') {
        body.set(key, value);
        continue;
      }
      if (typeof value === 'number' || typeof value === 'boolean') {
        body.set(key, String(value));
        continue;
      }
      // Objects, arrays, etc. — stringify. The PHP side should expect JSON
      // for fields where the schema calls for structured data.
      body.set(key, JSON.stringify(value));
    }
  }
  return body;
}

/**
 * Typed AJAX call to the Fanxie WP Core admin endpoint.
 *
 * @typeParam TResponse - Shape of the unwrapped `data` field on success.
 * @typeParam TPayload  - Shape of the request payload.
 */
export async function ajax<
  TResponse,
  TPayload extends Record<string, unknown> = Record<string, never>,
>(
  subAction: string,
  payload?: TPayload,
  options: AjaxOptions = {},
): Promise<TResponse> {
  const bootstrap = window.fanxieWPCore;
  if (!bootstrap) {
    throw new AjaxError(
      'bootstrap_missing',
      'window.fanxieWPCore is not available — PHP bootstrap did not run.',
      0,
    );
  }

  const dedupeKey = options.dedupe
    ? `${subAction}|${stableStringify(payload)}`
    : null;

  if (dedupeKey !== null) {
    const existing = inFlight.get(dedupeKey);
    if (existing) {
      return existing as Promise<TResponse>;
    }
  }

  const promise = (async (): Promise<TResponse> => {
    const body = buildBody(subAction, payload, bootstrap.nonce);

    let response: Response;
    try {
      response = await fetch(bootstrap.ajaxUrl, {
        method: 'POST',
        credentials: 'same-origin',
        headers: {
          // URLSearchParams implies application/x-www-form-urlencoded; set
          // explicitly for clarity and to play well with proxies.
          'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8',
          Accept: 'application/json',
        },
        body,
        ...(options.signal ? { signal: options.signal } : {}),
      });
    } catch (err) {
      const message =
        err instanceof Error ? err.message : 'Network request failed.';
      throw new AjaxError('network_error', message, 0);
    }

    if (!response.ok) {
      throw new AjaxError(
        'network_error',
        `Request failed with HTTP ${response.status}.`,
        response.status,
      );
    }

    let parsed: unknown;
    try {
      parsed = await response.json();
    } catch {
      throw new AjaxError(
        'invalid_response',
        'Response was not valid JSON.',
        response.status,
      );
    }

    if (!isWpAjaxEnvelope(parsed)) {
      throw new AjaxError(
        'invalid_response',
        'Response did not match the WordPress { success, data } envelope.',
        response.status,
      );
    }

    if (!parsed.success) {
      const { code, message } = extractErrorFields(parsed.data);
      throw new AjaxError(code, message, response.status);
    }

    return parsed.data as TResponse;
  })();

  if (dedupeKey !== null) {
    inFlight.set(dedupeKey, promise);
    promise.finally(() => {
      // Only clear if it's still our entry — a later call may have replaced it
      // after ours settled.
      if (inFlight.get(dedupeKey) === promise) {
        inFlight.delete(dedupeKey);
      }
    });
  }

  return promise;
}
