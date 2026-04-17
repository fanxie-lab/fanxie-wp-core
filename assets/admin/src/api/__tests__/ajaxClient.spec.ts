import { describe, expect, it } from 'vitest';
import { ajax, AjaxError } from '@/api/ajaxClient';
import { mockAjaxResponse } from '../../../tests/setup';

describe('ajax()', () => {
  it('resolves with the unwrapped `data` field on a successful envelope', async () => {
    mockAjaxResponse('something', { hello: 'world' });

    const result = await ajax<{ hello: string }>('something');

    expect(result).toEqual({ hello: 'world' });
  });

  it('throws AjaxError with the server-reported code on a success:false envelope', async () => {
    mockAjaxResponse(
      'something',
      { code: 'denied', message: 'Nope' },
      { success: false },
    );

    await expect(ajax('something')).rejects.toBeInstanceOf(AjaxError);

    try {
      await ajax('something');
      throw new Error('expected ajax() to throw');
    } catch (err) {
      expect(err).toBeInstanceOf(AjaxError);
      const e = err as AjaxError;
      expect(e.code).toBe('denied');
      expect(e.message).toBe('Nope');
    }
  });

  it('throws AjaxError with code="network_error" on an HTTP 500', async () => {
    mockAjaxResponse(
      'something',
      { code: 'boom', message: 'exploded' },
      { status: 500, success: false },
    );

    try {
      await ajax('something');
      throw new Error('expected ajax() to throw');
    } catch (err) {
      expect(err).toBeInstanceOf(AjaxError);
      const e = err as AjaxError;
      expect(e.code).toBe('network_error');
      expect(e.status).toBe(500);
    }
  });
});
