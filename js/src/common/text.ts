import app from 'flarum/common/app';
import type { StepView } from './types';

export const NS = 'ernestdefoe-onramp';

/** A translation from this extension. */
export function t(key: string, params: Record<string, unknown> = {}): any {
  return app.translator.trans(`${NS}.${key}`, params);
}

function typeText(step: StepView, what: 'title' | 'description' | 'button'): any {
  return t(`lib.types.${step.type}.${what}`, { count: step.target, tag: step.tag?.name || '' });
}

/**
 * The step's own words when the admin wrote some, otherwise the type's —
 * translated for the reader, with the step's count and tag filled in.
 */
export function stepTitle(step: StepView): any {
  if (step.title) return step.title;

  // A tag-scoped step names its tag: "Start a discussion in Introductions".
  return step.tag ? t(`lib.types.${step.type}.title_in_tag`, { count: step.target, tag: step.tag.name }) : typeText(step, 'title');
}

export function stepDescription(step: StepView): any {
  return step.description ?? typeText(step, 'description');
}

export function stepButton(step: StepView): any {
  return step.buttonLabel || typeText(step, 'button');
}
