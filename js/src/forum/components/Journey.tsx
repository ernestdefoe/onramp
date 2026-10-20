import app from 'flarum/forum/app';
import Component from 'flarum/common/Component';
import Button from 'flarum/common/components/Button';
import Link from 'flarum/common/components/Link';
import LoadingIndicator from 'flarum/common/components/LoadingIndicator';
import type { JourneyView, StepView } from '../../common/types';
import { stepButton, stepDescription, stepTitle, t } from '../../common/text';
import { fullJourney, own } from '../state';
import { actionFor, run } from '../actions';
import Ring from './Ring';
import useStyles from '../styles';

useStyles();
import StepRow from './StepRow';

/*
 * 🚨 Its own chunk: the launcher's panel and the widget's body.
 *
 * Only a member mid-journey ever draws either, and that is a sliver of the
 * people loading any page. Everyone else — guests, regulars, graduates — gets
 * a forum.js without the step rows, the actions or the panel at all.
 */

/** The first step neither done nor skipped. */
export function nextStep(journey: JourneyView | null): StepView | null {
  return journey?.steps.find((s) => !s.completedAt && !s.skippedAt) ?? null;
}

/** The open launcher: the next step and its button, then every step. */
export class Panel extends Component {
  view() {
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

/** "Your journey" in a sidebar: the ring, the next step and its button. */
export class WidgetBody extends Component {
  view() {
    const journey = own();
    if (!journey) return null;

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
