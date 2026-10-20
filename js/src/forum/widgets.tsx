import app from 'flarum/forum/app';
import Component from 'flarum/common/Component';
import Button from 'flarum/common/components/Button';
import Link from 'flarum/common/components/Link';
import { stepButton, stepTitle, t } from '../common/text';
import { own, showsLauncher } from './state';
import { actionFor, run } from './actions';
import { nextStep } from './components/Launcher';
import Ring from './components/Ring';

declare const flarum: any;
declare const m: import('mithril').Static;

/**
 * "Your journey": the ring, the next step and its button. Drawn from the
 * member's own user — no request — and nothing at all for everyone without an
 * open journey, so placing it costs the forum's regulars nothing.
 */
export class JourneyWidget extends Component {
  view() {
    const journey = own();
    if (!journey || journey.status !== 'open') return null;

    const next = nextStep(journey);

    return (
      <div className="OnrampWidget">
        <div className="OnrampWidget-head">
          <Ring done={journey.done + journey.skipped} total={journey.total} size={40} label={`${journey.done}/${journey.total}`} />
          <div>
            <h4 className="OnrampWidget-title">{t('forum.widget.title')}</h4>
            <div className="OnrampWidget-sub">{t('forum.launcher.sub', { count: journey.total - journey.done - journey.skipped })}</div>
          </div>
        </div>
        {next ? (
          <div className="OnrampWidget-next">
            <div className="OnrampWidget-nextTitle">{stepTitle(next)}</div>
            {actionFor(next) ? (
              <Button className="Button Button--primary Button--block" onclick={() => run(next)}>
                {stepButton(next)}
              </Button>
            ) : null}
          </div>
        ) : null}
        <Link className="OnrampWidget-link" href={app.route('onramp')}>
          {t('forum.launcher.see_all')}
        </Link>
      </div>
    );
  }
}

export function hasFofWidgets(): boolean {
  return typeof flarum !== 'undefined' && !!flarum?.extensions && 'fof-forum-widgets-core' in flarum.extensions;
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

  if (hasFofWidgets()) {
    const widgetsMod = flarum.reg?.get?.('fof-forum-widgets-core', 'common/extend/Widgets');
    const widgetMod = flarum.reg?.get?.('fof-forum-widgets-core', 'common/components/Widget');
    const Widgets = widgetsMod?.default ?? widgetsMod;
    const WidgetBase = (widgetMod?.default ?? widgetMod) as { new (...args: any[]): any } | undefined;
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
      .add({ key: 'onrampJourney', component: JourneyFofWidget, isDisabled: false, isUnique: true, placement: 'start', position: 0 })
      .extend(app, 'ernestdefoe-onramp');
  }
}

export { showsLauncher };
