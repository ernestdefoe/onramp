import app from 'flarum/admin/app';
import isExtensionEnabled from 'flarum/admin/utils/isExtensionEnabled';

export interface AdminStep {
  id: number;
  type: string;
  config: { count?: number; tag?: number; path?: string };
  title: string | null;
  description: string | null;
  icon: string | null;
  buttonLabel: string | null;
  buttonUrl: string | null;
  points: number;
  badgeId: number | null;
  required: boolean;
  enabled: boolean;
  available: boolean;
}

export interface TypeInfo {
  key: string;
  icon: string;
  fields: string[];
  extension: string | null;
  available: boolean;
}

export interface Listing {
  data: AdminStep[];
  meta: { types: TypeInfo[]; points: boolean; badges: boolean };
}

/** Why points are off: no Gamification at all, or one without point grants. */
export const pointsHint = () => (isExtensionEnabled('fof-gamification') ? 'admin.needs_points' : 'admin.needs_gamification');

const base = () => app.forum.attribute('apiUrl') + '/onramp-admin';

/** The editor's state, shared by the page and its modal. */
export const store: { listing: Listing | null } = { listing: null };

export function load(): Promise<Listing> {
  return app.request<Listing>({ method: 'GET', url: base() + '/steps' }).then((res) => (store.listing = res));
}

export function save(id: number | null, attributes: Record<string, unknown>): Promise<Listing> {
  return app
    .request<Listing>({ method: id ? 'PATCH' : 'POST', url: base() + '/steps' + (id ? `/${id}` : ''), body: { attributes } })
    .then((res) => (store.listing = res));
}

export function remove(id: number): Promise<Listing> {
  return app.request({ method: 'DELETE', url: base() + `/steps/${id}` }).then(load);
}

export function order(ids: number[]): Promise<Listing> {
  return app.request<Listing>({ method: 'POST', url: base() + '/order', body: { ids } }).then((res) => (store.listing = res));
}

export function importSteps(steps: unknown[], replace: boolean): Promise<Listing> {
  return app.request<Listing>({ method: 'POST', url: base() + '/import', body: { steps, replace } }).then((res) => (store.listing = res));
}

export function funnel(weeks: number): Promise<any> {
  return app.request<{ data: any }>({ method: 'GET', url: base() + '/funnel', params: { weeks } }).then((res) => res.data);
}

export function startExisting(days: number): Promise<{ started: number; remaining: number }> {
  return app
    .request<{ data: { started: number; remaining: number } }>({ method: 'POST', url: base() + '/start-existing', body: { days } })
    .then((res) => res.data);
}

export interface BadgeOption {
  id: string;
  name: string;
  icon: string;
}

let badges: Promise<BadgeOption[]> | null = null;

/** FoF Badges' own list, fetched once per admin visit. */
export function loadBadges(): Promise<BadgeOption[]> {
  badges ??= app
    .request<{ data: { id: string; attributes: { name: string; icon: string } }[] }>({
      method: 'GET',
      url: app.forum.attribute('apiUrl') + '/badges',
      params: { page: { limit: 50 } },
    })
    .then((res) => res.data.map((b) => ({ id: b.id, name: b.attributes.name, icon: b.attributes.icon })))
    .catch(() => []);

  return badges;
}

export const presets: Record<string, Partial<AdminStep>[]> = {
  starter: [
    { type: 'avatar', points: 5 },
    { type: 'read', config: { count: 3 }, points: 5 },
    { type: 'reply', points: 10, required: true },
    { type: 'discussion', points: 10 },
  ],
  community: [
    { type: 'avatar', points: 5 },
    { type: 'bio', points: 5 },
    { type: 'read', config: { count: 5 }, points: 5 },
    { type: 'like_given', points: 5 },
    { type: 'follow_tag', points: 5 },
    { type: 'reply', points: 10, required: true },
    { type: 'discussion', points: 15 },
  ],
  full: [
    { type: 'email_confirmed', points: 5, required: true },
    { type: 'avatar', points: 5 },
    { type: 'bio', points: 5 },
    { type: 'read', config: { count: 5 }, points: 5 },
    { type: 'like_given', config: { count: 3 }, points: 5 },
    { type: 'follow_discussion', points: 5 },
    { type: 'follow_tag', points: 5 },
    { type: 'poll_vote', points: 5 },
    { type: 'reply', config: { count: 3 }, points: 15, required: true },
    { type: 'mention', points: 5 },
    { type: 'discussion', points: 15 },
    { type: 'streak', config: { count: 3 }, points: 20 },
  ],
};
