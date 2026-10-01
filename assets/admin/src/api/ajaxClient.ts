// Typed wrapper around WordPress's admin-ajax.php.
//
// Contract with the PHP side (frozen — see CLAUDE.md §3.4 and the brief):
//   action       = 'fanxie_warden'         (fixed — PHP dispatches on this)
//   _action      = <subAction>             (PHP AjaxRouter reads this; sanitize_key on PHP side)
//   _ajax_nonce  = window.fanxieWarden.nonce  (verified against 'fanxie_warden_admin')
//
// Response envelope is WP's standard { success: boolean, data: unknown }.

const WP_ACTION = 'fanxie_warden' as const;

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
    const code = typeof rec.code === 'string' ? rec.code : 'unknown_error';
    const message =
      typeof rec.message === 'string'
        ? rec.message
        : 'The request failed without a message.';
    return { code, message };
  }
  if (typeof data === 'string' && data.length > 0) {
    return { code: 'unknown_error', message: data };
  }
  return {
    code: 'unknown_error',
    message: 'The request failed without a message.',
  };
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

function buildJsonBody(
  subAction: string,
  payload: Record<string, unknown> | undefined,
  nonce: string,
): string {
  // WP's admin-ajax routes on the `action` query-string param; `_action` and
  // `_ajax_nonce` travel inside the JSON body so nested payloads survive the
  // wire intact (form-urlencoded flattens object values to JSON strings).
  const envelope: Record<string, unknown> = {
    _action: subAction,
    _ajax_nonce: nonce,
    ...(payload ?? {}),
  };
  return JSON.stringify(envelope);
}

/**
 * Typed AJAX call to the Fanxie Warden admin endpoint.
 *
 * @typeParam TResponse - Shape of the unwrapped `data` field on success.
 */
export async function ajax<TResponse>(
  subAction: string,
  payload?: Record<string, unknown>,
  options: AjaxOptions = {},
): Promise<TResponse> {
  const bootstrap = window.fanxieWarden;
  if (!bootstrap) {
    throw new AjaxError(
      'bootstrap_missing',
      'window.fanxieWarden is not available — PHP bootstrap did not run.',
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
    const body = buildJsonBody(subAction, payload, bootstrap.nonce);
    const url = new URL(bootstrap.ajaxUrl, window.location.origin);
    url.searchParams.set('action', WP_ACTION);

    let response: Response;
    try {
      response = await fetch(url.toString(), {
        method: 'POST',
        credentials: 'same-origin',
        headers: {
          'Content-Type': 'application/json',
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
        `Request failed with HTTP ${String(response.status)}.`,
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
    // Fire-and-forget cache cleanup: we must return `promise` below so callers
    // get the original result; awaiting here would defeat the dedupe cache.
    void promise.finally(() => {
      // Only clear if it's still our entry — a later call may have replaced it
      // after ours settled.
      if (inFlight.get(dedupeKey) === promise) {
        inFlight.delete(dedupeKey);
      }
    });
  }

  return promise;
}
