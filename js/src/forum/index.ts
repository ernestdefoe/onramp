import app from 'flarum/forum/app';
import { extend, override } from 'flarum/common/extend';
import HeaderSecondary from 'flarum/forum/components/HeaderSecondary';
import IndexSidebar from 'flarum/forum/components/IndexSidebar';
import IndexPage from 'flarum/forum/components/IndexPage';
import UserPage from 'flarum/forum/components/UserPage';
import LinkButton from 'flarum/common/components/LinkButton';
import Page from 'flarum/common/components/Page';
import type ItemList from 'flarum/common/utils/ItemList';
import type { OnrampMeta } from '../common/types';
import { t } from '../common/text';
import Launcher from './components/Launcher';
import { act, celebrate, own, remember, showsLauncher, stale } from './state';
import { GraduatedNotification, ReminderNotification, StepCompletedNotification } from './notifications';
import { hasFofWidgets, JourneyWidget, registerWidgets } from './widgets';

declare const m: import('mithril').Static;

export { default as Ring } from './components/Ring';

app.initializers.add('ernestdefoe/onramp', () => {
  // 🚨 Both pages are their own chunks: nothing in them loads on other pages.
  app.routes.onramp = { path: '/onramp', component: () => import('./components/OnrampPage') };
  app.routes['user.onramp'] = { path: '/u/:username/onramp', component: () => import('./components/MemberOnrampPage') };

  app.notificationComponents.onrampStepCompleted = StepCompletedNotification;
  app.notificationComponents.onrampGraduated = GraduatedNotification;
  app.notificationComponents.onrampReminder = ReminderNotification;

  extend('flarum/forum/components/NotificationGrid', 'notificationTypes', function (items: ItemList<any>) {
    if (!own()) return;
    items.add('onrampStepCompleted', { name: 'onrampStepCompleted', icon: 'fas fa-circle-check', label: t('forum.settings.notify_step') });
    items.add('onrampGraduated', { name: 'onrampGraduated', icon: 'fas fa-flag-checkered', label: t('forum.settings.notify_graduated') });
    items.add('onrampReminder', { name: 'onrampReminder', icon: 'fas fa-route', label: t('forum.settings.notify_reminder') });
  });

  extend(HeaderSecondary.prototype, 'items', function (items: ItemList<any>) {
    if (showsLauncher()) items.add('onramp', m(Launcher), 16);
  });

  // Where the forum lists its own pages, while the journey is open.
  extend(IndexSidebar.prototype, 'navItems', function (items: ItemList<any>) {
    if (!showsLauncher()) return;
    items.add('onramp', LinkButton.component({ href: app.route('onramp'), icon: 'fas fa-route' }, t('forum.page.title')), 7);
  });

  // The stock sidebar gets the widget on the index when no widget host is
  // there to place it — only for members on a journey, so nobody else's
  // sidebar changes at all.
  extend(IndexSidebar.prototype, 'items', function (items: ItemList<any>) {
    if (!showsLauncher() || hasFofWidgets() || !app.current?.matches(IndexPage)) return;
    items.add('onrampJourney', m(JourneyWidget), -15);
  });

  extend(UserPage.prototype, 'navItems', function (this: any, items: ItemList<any>) {
    if (!this.user || !app.forum.attribute('canViewOnrampMembers')) return;
    items.add(
      'onramp',
      LinkButton.component({ href: app.route('user.onramp', { username: this.user.slug() }), icon: 'fas fa-route' }, t('forum.member.tab')),
      70
    );
  });

  /*
   * 🚨 The news of a completed step rides in the `meta` of the very response
   * that completed it — the reply, the like, the avatar upload. Every request
   * goes through app.request, so this is the one place to hear it, whatever
   * the page and whichever extension made the request.
   */
  override(app, 'request', function (original: any, options: any) {
    return original(options).then((res: any) => {
      // Only the forum's own API speaks for Onramp: a response from anywhere
      // else (an extension calling an outside service) is never read.
      const url = String(options?.url || '');
      const meta = url.startsWith(app.forum.attribute<string>('apiUrl')) && res && typeof res === 'object' ? res.meta?.onramp : undefined;

      if (meta && Array.isArray(meta.completed)) {
        try {
          if (meta.state !== undefined) remember(meta.state ?? null);
          celebrate(meta, meta.state);
          m.redraw();
        } catch (e) {
          // The member's own request succeeded; a toast is never worth failing it.
        }
      }

      return res;
    });
  });

  /*
   * Visit steps: when the member lands on a page one of their pending steps
   * points at, tell the server. Nothing is sent on any other page, and
   * nothing at all once the journey is over.
   */
  let lastPath = '';
  // Every route draws a Page; after it is up, the route is the one to report.
  extend(Page.prototype, 'oncreate', () => setTimeout(() => reportVisit(), 0));

  function reportVisit() {
    const journey = own();
    if (!journey || journey.status !== 'open') return;

    const path = m.route.get() || '/';
    if (path === lastPath) return;
    lastPath = path;

    const wanted = journey.steps.some((s) => s.type === 'visit' && !s.completedAt && s.path && samePage(s.path, path));
    if (wanted) act('visit', { path }).catch(() => {});
  }

  // J opens the launcher — unless the member is typing.
  document.addEventListener('keydown', (e: KeyboardEvent) => {
    if (e.key !== 'j' && e.key !== 'J') return;
    if (e.metaKey || e.ctrlKey || e.altKey || !showsLauncher()) return;
    const el = e.target as HTMLElement | null;
    if (el && (el.isContentEditable || ['INPUT', 'TEXTAREA', 'SELECT'].includes(el.tagName))) return;

    const toggle = document.querySelector<HTMLElement>('.OnrampLauncher .Dropdown-toggle');
    if (toggle) {
      e.preventDefault();
      toggle.click();
    }
  });

  // After sibling extensions (Bespoke) have set up their registries.
  setTimeout(() => registerWidgets(), 0);

  // Anything the member did elsewhere (another tab) shows next time it opens.
  document.addEventListener('visibilitychange', () => {
    if (document.visibilityState === 'visible') stale();
  });
});

/** The same page as the server decides it (see StepActionController::samePath). */
function samePage(want: string, have: string): boolean {
  const norm = (p: string) => {
    let path = p;
    try {
      path = new URL(p, window.location.origin).pathname;
    } catch (e) {
      // A plain path is already what we want.
    }
    const base = new URL(app.forum.attribute<string>('baseUrl')).pathname.replace(/\/$/, '');
    if (base && (path + '/').startsWith(base + '/')) path = path.slice(base.length);
    path = '/' + path.replace(/^\/+|\/+$/g, '');
    const d = path.match(/^\/d\/(\d+)/);
    return d ? `/d/${d[1]}` : path.toLowerCase();
  };

  return norm(want) === norm(have);
}
