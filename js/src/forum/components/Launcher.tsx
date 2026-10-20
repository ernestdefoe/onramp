import Component from 'flarum/common/Component';
import Dropdown from 'flarum/common/components/Dropdown';
import LoadingIndicator from 'flarum/common/components/LoadingIndicator';
import extractText from 'flarum/common/utils/extractText';
import { t } from '../../common/text';
import { fullJourney, loadFull, own } from '../state';
import { journeyChunk } from '../lazy';
import Ring from './Ring';

declare const m: import('mithril').Static;

/**
 * The launcher in the header: a ring with the count, and one tap for the next
 * step and its button. Press J anywhere to open it.
 *
 * 🚨 Closed, it is drawn from the member's own user — no request, and no
 * panel code. Opening it fetches the live counts ("2 of 3 read") and the
 * panel's chunk, together, and only then.
 */
export default class Launcher extends Component {
  view() {
    const journey = fullJourney() ?? own();
    if (!journey) return null;

    const label = extractText(t('forum.launcher.label', { done: journey.done, total: journey.total }));
    const Panel = journeyChunk.loaded?.Panel;

    return (
      <Dropdown
        className="OnrampLauncher"
        buttonClassName="Button Button--flat OnrampLauncher-button"
        menuClassName="Dropdown-menu--right OnrampLauncher-menu"
        accessibleToggleLabel={label}
        lazyDraw
        onshow={() => Promise.all([loadFull(), journeyChunk.load()]).then(() => m.redraw())}
        label={
          <span className="OnrampLauncher-label" title={label}>
            <Ring done={journey.done + journey.skipped} total={journey.total} size={22} />
            <span className="OnrampLauncher-count">
              {journey.done}/{journey.total}
            </span>
          </span>
        }
      >
        <li className="OnrampLauncher-panel">{Panel ? <Panel /> : <LoadingIndicator size="small" />}</li>
      </Dropdown>
    );
  }
}
