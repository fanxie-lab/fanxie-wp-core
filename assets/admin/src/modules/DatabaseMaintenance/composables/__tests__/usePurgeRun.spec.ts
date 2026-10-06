import { describe, expect, it, vi } from 'vitest';
import { usePurgeRun } from '../usePurgeRun';
import type { PurgeStepResponse, TaskId } from '../../types';

function step(
  id: TaskId,
  over: Partial<PurgeStepResponse> = {},
): PurgeStepResponse {
  return {
    id,
    deleted: 0,
    failed: 0,
    remaining: 0,
    done: true,
    busy: false,
    ...over,
  };
}

describe('usePurgeRun', () => {
  it('re-calls a task until done and totals the deletions', async () => {
    const calls = [
      step('revisions', { deleted: 500, remaining: 300, done: false }),
      step('revisions', { deleted: 300, done: true }),
      step('spam-comments', { deleted: 4 }),
    ];
    const stepFn = vi.fn(() => Promise.resolve(calls.shift()!));
    const run = usePurgeRun({ step: stepFn, sleep: () => Promise.resolve() });

    const totals = await run.run(['revisions', 'spam-comments']);

    expect(stepFn).toHaveBeenCalledTimes(3);
    expect(totals).toEqual({ deleted: 804, failed: 0, cancelled: false });
    expect(run.progress.value.revisions?.state).toBe('done');
    expect(run.running.value).toBe(false);
  });

  it('retries busy steps then gives up after five tries', async () => {
    const stepFn = vi.fn(() =>
      Promise.resolve(step('revisions', { busy: true, done: false })),
    );
    const sleep = vi.fn(() => Promise.resolve());
    const run = usePurgeRun({ step: stepFn, sleep });

    await run.run(['revisions']);

    expect(stepFn).toHaveBeenCalledTimes(6);
    expect(sleep).toHaveBeenCalledWith(2000);
    expect(run.progress.value.revisions?.state).toBe('error');
  });

  it('stops a task that makes no progress instead of looping forever', async () => {
    const stepFn = vi.fn(() =>
      Promise.resolve(
        step('trashed-posts', {
          deleted: 0,
          failed: 500,
          remaining: 500,
          done: false,
        }),
      ),
    );
    const run = usePurgeRun({ step: stepFn, sleep: () => Promise.resolve() });

    const totals = await run.run(['trashed-posts']);

    expect(stepFn).toHaveBeenCalledTimes(1);
    expect(run.progress.value['trashed-posts']?.state).toBe('error');
    expect(totals.failed).toBe(500);
  });

  it('cancel stops after the in-flight step and marks the rest cancelled', async () => {
    let release!: (v: PurgeStepResponse) => void;
    const stepFn = vi.fn(
      () =>
        new Promise<PurgeStepResponse>((r) => {
          release = r;
        }),
    );
    const run = usePurgeRun({ step: stepFn, sleep: () => Promise.resolve() });

    const pending = run.run(['revisions', 'spam-comments']);
    run.cancel();
    release(step('revisions', { deleted: 10, remaining: 5, done: false }));
    const totals = await pending;

    expect(totals.cancelled).toBe(true);
    expect(totals.deleted).toBe(10);
    expect(run.progress.value['spam-comments']?.state).toBe('cancelled');
    expect(stepFn).toHaveBeenCalledTimes(1);
  });

  it('a request error fails that task but continues with the next', async () => {
    const stepFn = vi
      .fn()
      .mockRejectedValueOnce(new Error('network'))
      .mockResolvedValueOnce(step('spam-comments', { deleted: 2 }));
    const run = usePurgeRun({ step: stepFn, sleep: () => Promise.resolve() });

    const totals = await run.run(['revisions', 'spam-comments']);

    expect(run.progress.value.revisions?.state).toBe('error');
    expect(run.progress.value['spam-comments']?.state).toBe('done');
    expect(totals.deleted).toBe(2);
  });

  it('announces each completed item for screen readers', async () => {
    const run = usePurgeRun({
      step: () => Promise.resolve(step('spam-comments', { deleted: 3 })),
      sleep: () => Promise.resolve(),
    });
    await run.run(['spam-comments']);
    expect(run.announcement.value).toMatch(/3/);
  });

  it('does not double-count rows that fail again in a later step', async () => {
    const calls = [
      step('revisions', { deleted: 10, failed: 2, remaining: 5, done: false }),
      step('revisions', { deleted: 3, failed: 2, done: true }),
    ];
    const stepFn = vi.fn(() => Promise.resolve(calls.shift()!));
    const run = usePurgeRun({ step: stepFn, sleep: () => Promise.resolve() });

    const totals = await run.run(['revisions']);

    expect(totals.failed).toBe(2);
    expect(run.progress.value.revisions?.failed).toBe(2);
  });
});
