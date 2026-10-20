import app from 'flarum/forum/app';
import extractText from 'flarum/common/utils/extractText';
import type { JourneyView, OnrampMeta, StepView } from '../common/types';
import { stepTitle, t } from '../common/text';

declare const m: import('mithril').Static;

/** The member's journey as their own user carries it (no live counts). */
export function own(): JourneyView | null {
  return (app.session.user?.attribute<JourneyView | null>('onramp') as JourneyView | null) ?? null;
}

/** An open journey whose launcher the admin left on. */
export function showsLauncher(): boolean {
  const journey = own();
  return !!journey && journey.status === 'open' && journey.launcher;
}

export function setOwn(journey: JourneyView | null): void {
  app.session.user?.pushAttributes({ onramp: journey });
}

/** The full read — live counts — kept for a short while between opens. */
let full: { at: number; data: JourneyView | null } | null = null;
let inflight: Promise<JourneyView | null> | null = null;

export function fullJourney(): JourneyView | null {
  return full?.data ?? null;
}

export function loadFull(force = false): Promise<JourneyView | null> {
  if (!force && full && Date.now() - full.at < 30_000) return Promise.resolve(full.data);
  if (inflight) return inflight;

  inflight = app
    .request<{ data: JourneyView | null; meta: OnrampMeta }>({ method: 'GET', url: app.forum.attribute('apiUrl') + '/onramp' })
    .then((res) => {
      remember(res.data);
      celebrate(res.meta, res.data);
      return res.data;
    })
    .finally(() => {
      inflight = null;
    });

  return inflight;
}

export function act(action: 'skip' | 'unskip' | 'visit' | 'close' | 'reopen', body: Record<string, unknown> = {}): Promise<JourneyView | null> {
  return app
    .request<{ data: JourneyView | null; meta: OnrampMeta }>({ method: 'POST', url: app.forum.attribute('apiUrl') + '/onramp/' + action, body })
    .then((res) => {
      remember(res.data);
      celebrate(res.meta, res.data);
      m.redraw();
      return res.data;
    });
}

/** Keep the full read and the session user's copy in step. */
export function remember(journey: JourneyView | null): void {
  full = { at: Date.now(), data: journey };
  setOwn(journey);
}

/** Drop the full read, so the next open refetches live counts. */
export function stale(): void {
  full = null;
}

/** Last step the member was told about, so a repeated meta never toasts twice. */
const told = new Set<number>();
let toldGraduation = false;

/**
 * Toasts for what the member just did. The server sends the news in the
 * response to the very request that completed the step, so the toast lands
 * on whatever page the member is on.
 */
export function celebrate(meta: OnrampMeta | undefined, journey: JourneyView | null | undefined): void {
  if (!meta) return;

  const steps: StepView[] = journey?.steps || own()?.steps || [];

  meta.completed
    .filter((id) => !told.has(id))
    .forEach((id) => {
      told.add(id);
      const step = steps.find((s) => s.id === id);
      if (!step) return;

      const reward = [step.points ? extractText(t('forum.toast.points', { count: step.points })) : '', step.badge ? step.badge.name : '']
        .filter(Boolean)
        .join(' · ');

      app.alerts.show(
        { type: 'success', icon: 'fas fa-circle-check', className: 'OnrampToast' } as any,
        extractText(t('forum.toast.step', { title: extractText(stepTitle(step)) })) + (reward ? ` — ${reward}` : '')
      );
    });

  if (meta.graduated && !toldGraduation) {
    toldGraduation = true;
    app.alerts.show(
      { type: 'success', icon: 'fas fa-flag-checkered', className: 'OnrampToast OnrampToast--graduated' } as any,
      t('forum.toast.graduated')
    );
  }
}
