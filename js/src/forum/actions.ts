import app from 'flarum/forum/app';
import type { StepView } from '../common/types';

declare const m: import('mithril').Static;

/**
 * What a step's button does: the admin's own link if they gave one, otherwise
 * the place on the forum where the step gets done.
 *
 * Returns an href for a plain link, or a function for the ones that open
 * something (the composer).
 */
export function actionFor(step: StepView): string | (() => void) | null {
  if (step.buttonUrl) return step.buttonUrl;

  const user = app.session.user;
  const route = (name: string, params: Record<string, unknown> = {}) => (app.routes[name] ? app.route(name, params) : null);
  const profile = user ? app.route.user(user) : null;
  // A tag-scoped step goes to the tag, where "Start a discussion" is already
  // in that tag and every discussion listed is one that counts.
  const tagPage = step.tag ? route('tag', { tags: step.tag.slug }) : null;

  switch (step.type) {
    case 'avatar':
    case 'bio':
      return profile;
    case 'nickname':
    case 'email_confirmed':
      return route('settings');
    case 'discussion':
      return tagPage || startDiscussion;
    case 'reply':
    case 'read':
    case 'mention':
    case 'like_given':
    case 'like_received':
    case 'reaction_given':
    case 'upvote_given':
    case 'best_answer':
    case 'follow_discussion':
    case 'poll_vote':
    case 'upload':
      return tagPage || route('index');
    case 'follow_tag':
      return route('tags') || route('index');
    case 'message':
      return route('messages');
    case 'private_discussion':
      return route('byobuPrivate') || route('index');
    case 'visit':
      return step.path;
    case 'streak':
      return route('cadence.streaks');
    default:
      return null;
  }
}

function startDiscussion(): void {
  if (!app.session.user) return;

  app.composer.load(() => import('flarum/forum/components/DiscussionComposer'), { user: app.session.user }).then(() => app.composer.show());
}

/** Follow a step's action: route inside the forum, leave it for an address outside. */
export function run(step: StepView, close?: () => void): void {
  const action = actionFor(step);
  close?.();

  if (typeof action === 'function') return action();
  if (!action) return;

  const base = app.forum.attribute<string>('baseUrl');
  const local = action.startsWith('/') ? action : action.startsWith(base) ? action.slice(base.length) || '/' : null;

  if (local !== null) m.route.set(local);
  else window.location.href = action;
}
