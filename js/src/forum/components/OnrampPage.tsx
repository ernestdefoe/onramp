import app from 'flarum/forum/app';
import Page from 'flarum/common/components/Page';
import Button from 'flarum/common/components/Button';
import Icon from 'flarum/common/components/Icon';
import LoadingIndicator from 'flarum/common/components/LoadingIndicator';
import extractText from 'flarum/common/utils/extractText';
import type { JourneyView, StepView } from '../../common/types';
import { t } from '../../common/text';
import { act, fullJourney, loadFull } from '../state';
import Ring from './Ring';
import useStyles from '../styles';

useStyles();
import StepRow from './StepRow';

declare const m: import('mithril').Static;

/**
 * /onramp — the member's journey: the ring, every step with what it pays and
 * the button that gets it done, skip and undo, and the reward at the end.
 *
 * Its own chunk: none of this is in the bundle every page loads.
 */
export default class OnrampPage extends Page {
  loading = true;
  busy = false;

  oninit(vnode: any) {
    super.oninit(vnode);
    app.setTitle(extractText(t('forum.page.title')));
    app.setTitleCount(0);

    if (!app.session.user) {
      this.loading = false;
      return;
    }

    loadFull(true)
      .catch(() => null)
      .then(() => {
        this.loading = false;
        m.redraw();
      });
  }

  view() {
    const journey = fullJourney();

    return (
      <div className="OnrampPage">
        <div className="container">
          {this.loading ? (
            <LoadingIndicator />
          ) : !app.session.user || !journey ? (
            <div className="OnrampPage-empty">
              <Icon name="fas fa-route" />
              <p>{t('forum.page.none')}</p>
            </div>
          ) : (
            this.journey(journey)
          )}
        </div>
      </div>
    );
  }

  journey(journey: JourneyView) {
    const left = journey.total - journey.done - journey.skipped;
    const graduated = journey.status === 'graduated';
    const closed = journey.status === 'closed';
    const interactive = journey.status === 'open';

    return [
      <header className={'OnrampHero' + (graduated ? ' is-graduated' : '')}>
        <Ring
          done={journey.done + journey.skipped}
          total={journey.total}
          size={112}
          label={graduated ? <Icon name="fas fa-flag-checkered" /> : `${journey.done}/${journey.total}`}
        />
        <div className="OnrampHero-text">
          <h2>{graduated ? t('forum.page.graduated_title') : t('forum.page.title')}</h2>
          <p>{graduated ? t('forum.page.graduated_text') : closed ? t('forum.page.closed_text') : t('forum.page.intro', { count: left })}</p>
          {this.reward(journey)}
        </div>
      </header>,

      <ol className="OnrampSteps">
        {journey.steps.map((step, i) => (
          <StepRow
            step={step}
            index={i}
            interactive={interactive}
            onskip={(s: StepView) => this.run(() => act('skip', { step: s.id }))}
            onunskip={(s: StepView) => this.run(() => act('unskip', { step: s.id }))}
          />
        ))}
      </ol>,

      <footer className="OnrampPage-footer">
        {interactive ? (
          <Button className="Button Button--link" disabled={this.busy} onclick={() => this.putAway()}>
            {t('forum.page.close')}
          </Button>
        ) : closed ? (
          <Button className="Button" disabled={this.busy} onclick={() => this.run(() => act('reopen'))}>
            {t('forum.page.reopen')}
          </Button>
        ) : null}
      </footer>,
    ];
  }

  reward(journey: JourneyView) {
    const { points, badge } = journey.reward;
    if (!points && !badge) return null;

    return (
      <div className="OnrampHero-reward">
        <span className="OnrampHero-rewardLabel">{journey.status === 'graduated' ? t('forum.page.reward_earned') : t('forum.page.reward')}</span>
        {points ? (
          <span className="OnrampChip OnrampChip--points">
            <Icon name="fas fa-star" /> {t('forum.step.points', { count: points })}
          </span>
        ) : null}
        {badge ? (
          <span
            className="OnrampChip OnrampChip--badge"
            style={{ '--badge-bg': badge.background || undefined, '--badge-fg': badge.color || undefined } as any}
          >
            <Icon name={badge.icon} /> {badge.name}
          </span>
        ) : null}
      </div>
    );
  }

  putAway() {
    if (!confirm(extractText(t('forum.page.close_confirm')))) return;
    this.run(() => act('close'));
  }

  run(fn: () => Promise<unknown>) {
    this.busy = true;
    fn()
      .catch(() => {})
      .finally(() => {
        this.busy = false;
        m.redraw();
      });
  }
}
