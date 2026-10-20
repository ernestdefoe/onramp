import app from 'flarum/forum/app';
import UserPage from 'flarum/forum/components/UserPage';
import Button from 'flarum/common/components/Button';
import LoadingIndicator from 'flarum/common/components/LoadingIndicator';
import humanTime from 'flarum/common/helpers/humanTime';
import extractText from 'flarum/common/utils/extractText';
import type { JourneyView } from '../../common/types';
import { stepTitle, t } from '../../common/text';
import Ring from './Ring';
import useStyles from '../styles';

useStyles();
import StepRow from './StepRow';

declare const m: import('mithril').Static;

/**
 * Profile → Onramp, for staff: where this member is on their journey.
 * Moderators look; admins can also start, reset, put away, bring back, and
 * tick a step the member did in a way Onramp could not see.
 *
 * Its own chunk, and its own request — only when a staff member opens it.
 */
export default class MemberOnrampPage extends UserPage {
  journey: JourneyView | null = null;
  loaded = false;
  busy = false;

  oninit(vnode: any) {
    super.oninit(vnode);
    this.loadUser(m.route.param('username'));
  }

  show(user: any) {
    super.show(user);
    this.fetch('GET');
  }

  fetch(method: 'GET' | 'POST', body?: Record<string, unknown>) {
    if (!this.user) return;
    this.busy = true;

    app
      .request<{ data: JourneyView | null }>({ method, url: `${app.forum.attribute('apiUrl')}/onramp/members/${this.user.id()}`, body })
      .then((res) => {
        this.journey = res.data;
      })
      .catch(() => {})
      .finally(() => {
        this.loaded = true;
        this.busy = false;
        m.redraw();
      });
  }

  content() {
    if (!this.loaded) return <LoadingIndicator />;

    const j = this.journey;
    const admin = !!app.session.user?.isAdmin();

    return (
      <div className="OnrampMember">
        {j ? (
          <header className="OnrampMember-head">
            <Ring done={j.done + j.skipped} total={j.total} size={56} label={`${j.done}/${j.total}`} />
            <div>
              <strong>{t(`forum.member.status_${j.status}`)}</strong>
              <div className="OnrampMember-dates">
                {t('forum.member.started')} {humanTime(new Date(j.startedAt))}
                {j.completedAt ? [' · ', t('forum.member.finished'), ' ', humanTime(new Date(j.completedAt))] : null}
              </div>
            </div>
          </header>
        ) : (
          <p className="OnrampMember-none">{t('forum.member.none')}</p>
        )}

        {j ? (
          <ol className="OnrampSteps">
            {j.steps.map((step, i) => (
              <StepRow
                step={step}
                index={i}
                aside={
                  admin && j.status === 'open' && !step.completedAt ? (
                    <Button
                      className="Button Button--link OnrampMember-tick"
                      disabled={this.busy}
                      onclick={() => {
                        if (confirm(extractText(t('forum.member.tick_confirm', { title: extractText(stepTitle(step)) })))) {
                          this.fetch('POST', { action: 'complete', step: step.id });
                        }
                      }}
                    >
                      {t('forum.member.tick')}
                    </Button>
                  ) : null
                }
              />
            ))}
          </ol>
        ) : null}

        {admin ? <div className="OnrampMember-actions">{this.actions(j)}</div> : null}
      </div>
    );
  }

  actions(j: JourneyView | null) {
    const button = (action: string, confirmKey?: string) => (
      <Button
        className="Button"
        disabled={this.busy}
        onclick={() => {
          if (confirmKey && !confirm(extractText(t(confirmKey)))) return;
          this.fetch('POST', { action });
        }}
      >
        {t(`forum.member.${action}`)}
      </Button>
    );

    if (!j) return button('start');

    return [
      j.status === 'open' ? button('close') : null,
      j.status === 'closed' ? button('reopen') : null,
      button('reset', 'forum.member.reset_confirm'),
    ];
  }
}
