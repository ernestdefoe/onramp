import Component from 'flarum/common/Component';
import Button from 'flarum/common/components/Button';
import Icon from 'flarum/common/components/Icon';
import humanTime from 'flarum/common/helpers/humanTime';
import classList from 'flarum/common/utils/classList';
import type { StepView } from '../../common/types';
import { stepButton, stepDescription, stepTitle, t } from '../../common/text';
import { actionFor, run } from '../actions';

interface StepRowAttrs {
  step: StepView;
  index: number;
  /** The member's own journey: buttons, skip and undo. Off for staff viewing it. */
  interactive?: boolean;
  compact?: boolean;
  onskip?: (step: StepView) => void;
  onunskip?: (step: StepView) => void;
  onnavigate?: () => void;
  /** Something staff can do with this step, in place of the member's buttons. */
  aside?: any;
}

/** One step: where it stands, what it pays, and the button that gets it done. */
export default class StepRow extends Component<StepRowAttrs> {
  view() {
    const { step, index, interactive, compact } = this.attrs;
    const done = !!step.completedAt;
    const skipped = !done && !!step.skippedAt;
    const pending = !done && !skipped;
    const progress = step.progress ?? 0;

    return (
      <li className={classList('OnrampStep', { 'is-done': done, 'is-skipped': skipped, 'is-pending': pending, 'is-compact': compact })}>
        <span className="OnrampStep-marker" aria-hidden="true">
          {done ? <Icon name="fas fa-check" /> : skipped ? <Icon name="fas fa-forward" /> : <span>{index + 1}</span>}
        </span>

        <div className="OnrampStep-body">
          <div className="OnrampStep-head">
            {step.icon ? <Icon name={step.icon} className="OnrampStep-icon" /> : null}
            <span className="OnrampStep-title">{stepTitle(step)}</span>
            {step.required && pending ? <span className="OnrampStep-required">{t('forum.step.required')}</span> : null}
          </div>

          {compact ? null : <p className="OnrampStep-description">{stepDescription(step)}</p>}

          {pending && step.target > 1 && step.progress !== undefined ? (
            <div className="OnrampStep-progress" aria-label={t('forum.step.progress', { done: progress, total: step.target })}>
              <span className="OnrampStep-bar">
                <span style={{ width: `${Math.round((progress / step.target) * 100)}%` }} />
              </span>
              <span className="OnrampStep-count">{t('forum.step.progress', { done: progress, total: step.target })}</span>
            </div>
          ) : null}

          {compact ? null : this.rewards()}
        </div>

        <div className="OnrampStep-side">{this.side(done, skipped)}</div>
      </li>
    );
  }

  rewards() {
    const { step } = this.attrs;
    if (!step.points && !step.badge) return null;

    return (
      <div className="OnrampStep-rewards">
        {step.points ? (
          <span className="OnrampChip OnrampChip--points">
            <Icon name="fas fa-star" /> {t('forum.step.points', { count: step.points })}
          </span>
        ) : null}
        {step.badge ? (
          <span
            className="OnrampChip OnrampChip--badge"
            style={{ '--badge-bg': step.badge.background || undefined, '--badge-fg': step.badge.color || undefined } as any}
          >
            <Icon name={step.badge.icon} /> {step.badge.name}
          </span>
        ) : null}
      </div>
    );
  }

  side(done: boolean, skipped: boolean) {
    const { step, interactive, compact } = this.attrs;

    if (done) {
      return <span className="OnrampStep-when">{compact ? null : humanTime(new Date(step.completedAt!))}</span>;
    }

    if (skipped) {
      return interactive ? (
        <Button className="Button Button--link OnrampStep-undo" onclick={() => this.attrs.onunskip?.(step)}>
          {t('forum.step.undo_skip')}
        </Button>
      ) : (
        [<span className="OnrampStep-when">{t('forum.step.skipped')}</span>, this.attrs.aside ?? null]
      );
    }

    if (!interactive) return this.attrs.aside ?? null;

    return [
      actionFor(step) ? (
        <Button
          className={classList('Button', compact ? 'Button--primary Button--sm' : 'Button--primary')}
          onclick={() => run(step, this.attrs.onnavigate)}
        >
          {stepButton(step)}
        </Button>
      ) : null,
      !step.required && !compact ? (
        <Button className="Button Button--link OnrampStep-skip" onclick={() => this.attrs.onskip?.(step)}>
          {t('forum.step.skip')}
        </Button>
      ) : null,
    ];
  }
}
