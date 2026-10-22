import app from 'flarum/forum/app';
import Component from 'flarum/common/Component';
import { t } from '../common/text';
import { own, showsLauncher } from './state';
import { journeyChunk } from './lazy';
import { fof, hasFofWidgets, placed } from '../common/fofWidgets';

declare const m: import('mithril').Static;

/**
 * "Your journey": the ring, the next step and its button — nothing at all for
 * anyone without an open journey, so placing it costs the forum's regulars
 * nothing, not even the code: the body is in the lazy journey chunk.
 */
export class JourneyWidget extends Component {
  oncreate(vnode: any) {
    super.oncreate(vnode);
    if (own()?.status === 'open') journeyChunk.load();
  }

  view() {
    const journey = own();
    if (!journey || journey.status !== 'open') return null;

    const Body = journeyChunk.loaded?.WidgetBody;
    return Body ? <Body /> : null;
  }
}

/**
 * Register with every widget host that is present — Bespoke and
 * fof/forum-widgets-core — resolved at runtime, never imported, so the bundle
 * is the same whether either is installed.
 */
export function registerWidgets(): void {
  const g = globalThis as any;

  if (g.flarum?.extensions && 'ernestdefoe-bespoke' in g.flarum.extensions) {
    g.BespokeWidgetQueue = g.BespokeWidgetQueue || [];
    g.BespokeWidgetQueue.push({
      type: 'onramp-journey',
      // 🚨 A translation KEY: Bespoke resolves it, and calls string methods on it.
      label: 'ernestdefoe-onramp.forum.widget.title',
      icon: 'fas fa-route',
      zones: ['sidebar', 'above-list', 'below-list', 'footer'],
      schema: [],
      component: { view: () => m(JourneyWidget) },
    });
  }

  if (hasFofWidgets() && (app as any).widgets) {
    const Widgets = fof('common/extend/Widgets');
    const WidgetBase = fof('common/components/Widget') as { new (...args: any[]): any } | undefined;
    if (!Widgets || !WidgetBase) return;

    class JourneyFofWidget extends WidgetBase {
      // No frame at all for members without an open journey — the widget
      // host would otherwise draw an empty box for every regular.
      view() {
        const journey = own();
        return journey && journey.status === 'open' ? super.view() : null;
      }
      className() {
        return 'OnrampFofWidget FofWidgets-Widget--flat';
      }
      header() {
        return null;
      }
      icon() {
        return 'fas fa-route';
      }
      title() {
        return t('forum.widget.title');
      }
      content() {
        return m(JourneyWidget);
      }
    }

    new Widgets()
      .add({
        key: 'onrampJourney',
        component: JourneyFofWidget,
        // 🚨 Opt-in: only where the admin placed it in FoF's layout editor
        // (see common/fofWidgets.ts), and only for a member with an open
        // journey — FoF draws its section for any widget it is given.
        isDisabled: () => !placed(app, 'ernestdefoe-onramp', 'onrampJourney') || own()?.status !== 'open',
        isUnique: true,
        // 'start' is not a FoF section: the widget could never be drawn.
        placement: 'start_top',
        position: 0,
      })
      .extend(app, 'ernestdefoe-onramp');

    // Registered after FoF drew its sections; a placed widget appears now.
    m.redraw();
  }
}

export { showsLauncher };
