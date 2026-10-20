import app from 'flarum/forum/app';
import Component from 'flarum/common/Component';
import Dropdown from 'flarum/common/components/Dropdown';
import Button from 'flarum/common/components/Button';
import Link from 'flarum/common/components/Link';
import LoadingIndicator from 'flarum/common/components/LoadingIndicator';
import extractText from 'flarum/common/utils/extractText';
import type { JourneyView, StepView } from '../../common/types';
import { stepButton, stepDescription, stepTitle, t } from '../../common/text';
import { fullJourney, loadFull, own } from '../state';
import { actionFor, run } from '../actions';
import Ring from './Ring';
import StepRow from './StepRow';

declare const m: import('mithril').Static;

/** The first step neither done nor skipped. */
export function nextStep(journey: JourneyView | null): StepView | null {
  return journey?.steps.find((s) => !s.completedAt && !s.skippedAt) ?? null;
}

/**
 * The launcher in the header: a ring with the count, and one tap for the next
 * step and its button. Press J anywhere to open it.
 *
 * 🚨 Closed, it is drawn from the member's own user — no request. Opening it
 * fetches the live counts ("2 of 3 read"), and only then.
 */
export default class Launcher extends Component {
  view() {
    const journey = fullJourney() ?? own();
    if (!journey) return null;

    const label = extractText(t('forum.launcher.label', { done: journey.done, total: journey.total }));

    return (
      <Dropdown
        className="OnrampLauncher"
        buttonClassName="Button Button--flat OnrampLauncher-button"
        menuClassName="Dropdown-menu--right OnrampLauncher-menu"
        accessibleToggleLabel={label}
        lazyDraw
        onshow={() => loadFull().then(() => m.redraw())}
        label={
          <span className="OnrampLauncher-label" title={label}>
            <Ring done={journey.done + journey.skipped} total={journey.total} size={22} />
            <span className="OnrampLauncher-count">
              {journey.done}/{journey.total}
            </span>
          </span>
        }
      >
        <li className="OnrampLauncher-panel">{this.panel()}</li>
      </Dropdown>
    );
  }

  panel() {
    const journey = fullJourney();
    if (!journey) return <LoadingIndicator size="small" />;

    const next = nextStep(journey);
    const close = () => document.body.click();

    return (
      <div className="OnrampPanel">
        <header className="OnrampPanel-head">
          <Ring done={journey.done + journey.skipped} total={journey.total} size={44} label={`${journey.done}/${journey.total}`} />
          <div>
            <strong>{t('forum.launcher.title')}</strong>
            <div className="OnrampPanel-sub">{t('forum.launcher.sub', { count: journey.total - journey.done - journey.skipped })}</div>
          </div>
        </header>

        {next ? (
          <section className="OnrampPanel-next">
            <div className="OnrampPanel-nextLabel">{t('forum.launcher.next')}</div>
            <div className="OnrampPanel-nextTitle">{stepTitle(next)}</div>
            <p className="OnrampPanel-nextText">{stepDescription(next)}</p>
            {actionFor(next) ? (
              <Button className="Button Button--primary Button--block" onclick={() => run(next, close)}>
                {stepButton(next)}
              </Button>
            ) : null}
          </section>
        ) : null}

        <ol className="OnrampPanel-steps">
          {journey.steps.map((step, i) => (
            <StepRow step={step} index={i} compact />
          ))}
        </ol>

        <Link className="OnrampPanel-all" href={app.route('onramp')} onclick={close}>
          {t('forum.launcher.see_all')}
        </Link>
      </div>
    );
  }
}
