import app from 'flarum/admin/app';
import ExtensionPage from 'flarum/admin/components/ExtensionPage';
import Button from 'flarum/common/components/Button';
import Icon from 'flarum/common/components/Icon';
import LoadingIndicator from 'flarum/common/components/LoadingIndicator';
import Select from 'flarum/common/components/Select';
import extractText from 'flarum/common/utils/extractText';
import isExtensionEnabled from 'flarum/admin/utils/isExtensionEnabled';
import classList from 'flarum/common/utils/classList';
import type { AdminStep } from '../api';
import { funnel, importSteps, load, loadBadges, order, presets, remove, startExisting, store } from '../api';
import { t } from '../../common/text';
import StepModal from './StepModal';

declare const m: import('mithril').Static;

const TABS = ['steps', 'settings', 'funnel'] as const;
type Tab = (typeof TABS)[number];

const key = (name: string) => `ernestdefoe-onramp.${name}`;

/**
 * Onramp's admin page: the journey (drag to reorder), the settings, and the
 * funnel that shows where newcomers stop.
 */
export default class OnrampAdminPage extends ExtensionPage {
  tab: Tab = 'steps';
  loading = true;
  sortable: any = null;
  // The funnel.
  weeks = 8;
  funnelData: any = null;
  funnelLoading = false;
  // Starting journeys for existing members.
  days = '30';
  starting = false;
  startedTotal: number | null = null;
  remaining: number | null = null;
  badgeOptions: Record<string, string> = {};

  oninit(vnode: any) {
    super.oninit(vnode);

    const loads: Promise<unknown>[] = [
      load().then((listing) => {
        if (!listing.meta.badges) return;
        return loadBadges().then((list) => {
          this.badgeOptions = list.reduce((o: Record<string, string>, b) => ({ ...o, [b.id]: b.name }), {
            '0': extractText(t('admin.steps.no_badge')),
          });
        });
      }),
    ];
    // The tag picker in the step editor needs the forum's tags.
    if (isExtensionEnabled('flarum-tags')) loads.push(app.store.find('tags').catch(() => null));

    Promise.all(loads)
      .catch(() => {})
      .finally(() => {
        this.loading = false;
        m.redraw();
      });
  }

  className() {
    return 'OnrampAdminPage';
  }

  content() {
    return (
      <div className="ExtensionPage-settings">
        <div className="container">
          <div className="OnrampAdmin-tabs" role="tablist">
            {TABS.map((tab) => (
              <Button
                className={classList('Button', { 'Button--primary': this.tab === tab })}
                role="tab"
                aria-selected={this.tab === tab ? 'true' : 'false'}
                onclick={() => {
                  this.tab = tab;
                  if (tab === 'funnel' && !this.funnelData) this.loadFunnel();
                }}
              >
                {t(`admin.tabs.${tab}`)}
              </Button>
            ))}
          </div>

          {this.loading ? (
            <LoadingIndicator />
          ) : this.tab === 'steps' ? (
            this.steps()
          ) : this.tab === 'settings' ? (
            this.settingsTab()
          ) : (
            this.funnelTab()
          )}
        </div>
      </div>
    );
  }

  // —— Steps ————————————————————————————————————————————————————————————

  steps() {
    const listing = store.listing;
    if (!listing) return <p>{t('admin.load_failed')}</p>;

    return (
      <div className="OnrampAdmin-steps">
        <p className="helpText">{t('admin.steps.intro')}</p>

        {listing.meta.points ? null : <p className="Alert OnrampAdmin-note">{t('admin.needs_points')}</p>}

        <ol className="OnrampAdmin-list" oncreate={(v: any) => this.makeSortable(v.dom)}>
          {listing.data.map((step) => this.row(step))}
        </ol>

        {listing.data.length ? null : <p className="OnrampAdmin-empty">{t('admin.steps.empty')}</p>}

        <div className="OnrampAdmin-toolbar">
          <Button className="Button Button--primary" icon="fas fa-plus" onclick={() => app.modal.show(StepModal, {})}>
            {t('admin.steps.add')}
          </Button>
          <Select
            value=""
            options={{
              '': extractText(t('admin.presets.choose')),
              starter: extractText(t('admin.presets.starter')),
              community: extractText(t('admin.presets.community')),
              full: extractText(t('admin.presets.full')),
            }}
            onchange={(name: string) => this.preset(name)}
          />
          <Button className="Button" icon="fas fa-file-export" onclick={() => this.exportSteps()}>
            {t('admin.export')}
          </Button>
          <label className="Button OnrampAdmin-import">
            <Icon name="fas fa-file-import" /> {t('admin.import')}
            <input type="file" accept="application/json,.json" onchange={(e: Event) => this.importFile(e)} />
          </label>
        </div>
      </div>
    );
  }

  row(step: AdminStep) {
    const title = step.title || extractText(t(`lib.types.${step.type}.title`, { count: step.config.count || 1, tag: '' }));

    return (
      <li
        key={step.id}
        data-id={step.id}
        className={classList('OnrampAdmin-row', { 'is-disabled': !step.enabled, 'is-unavailable': !step.available })}
      >
        <span className="OnrampAdmin-handle" title={extractText(t('admin.steps.drag'))}>
          <Icon name="fas fa-grip-vertical" />
        </span>
        <Icon name={step.icon || store.listing?.meta.types.find((x) => x.key === step.type)?.icon || 'fas fa-circle'} className="OnrampAdmin-icon" />
        <div className="OnrampAdmin-main">
          <div className="OnrampAdmin-title">{title}</div>
          <div className="OnrampAdmin-meta">
            <span>{t(`admin.types.${step.type}`)}</span>
            {step.points ? <span className="OnrampAdmin-chip">{t('admin.steps.points_short', { count: step.points })}</span> : null}
            {step.badgeId ? (
              <span className="OnrampAdmin-chip">
                <Icon name="fas fa-award" /> {t('admin.steps.has_badge')}
              </span>
            ) : null}
            {step.required ? <span className="OnrampAdmin-chip is-required">{t('admin.steps.required_short')}</span> : null}
            {!step.enabled ? <span className="OnrampAdmin-chip">{t('admin.steps.off')}</span> : null}
            {!step.available ? <span className="OnrampAdmin-chip is-warning">{t('admin.steps.unavailable')}</span> : null}
          </div>
        </div>
        <Button
          className="Button Button--icon"
          icon="fas fa-pencil-alt"
          aria-label={extractText(t('admin.steps.edit'))}
          onclick={() => app.modal.show(StepModal, { step })}
        />
        <Button
          className="Button Button--icon Button--danger"
          icon="fas fa-trash-alt"
          aria-label={extractText(t('admin.steps.delete'))}
          onclick={() => {
            if (confirm(extractText(t('admin.steps.delete_confirm', { title })))) remove(step.id).then(() => m.redraw());
          }}
        />
      </li>
    );
  }

  /**
   * Drag to reorder, with core's own lazily loaded Sortable — touch included,
   * and nothing added to the admin bundle.
   */
  makeSortable(dom: HTMLElement) {
    import('flarum/admin/utils/loadSortable').then(({ default: Sortable }: any) => {
      this.sortable = Sortable.create(dom, {
        handle: '.OnrampAdmin-handle',
        animation: 150,
        onEnd: () => {
          const ids = Array.from(dom.children).map((li) => Number((li as HTMLElement).dataset.id));
          order(ids).then(() => m.redraw());
        },
      });
    });
  }

  preset(name: string) {
    const steps = presets[name];
    if (!steps) return;

    const replace = !!store.listing?.data.length && confirm(extractText(t('admin.presets.replace_confirm')));
    importSteps(steps, replace).then(() => m.redraw());
  }

  exportSteps() {
    const data = (store.listing?.data || []).map(({ id, available, badgeId, ...rest }) => rest);
    const blob = new Blob([JSON.stringify({ onramp: 1, steps: data }, null, 2)], { type: 'application/json' });
    const a = document.createElement('a');
    a.href = URL.createObjectURL(blob);
    a.download = 'onramp-journey.json';
    a.click();
    setTimeout(() => URL.revokeObjectURL(a.href), 1000);
  }

  importFile(e: Event) {
    const input = e.target as HTMLInputElement;
    const file = input.files?.[0];
    input.value = '';
    if (!file) return;

    file.text().then((text) => {
      let steps: unknown[] = [];
      try {
        const parsed = JSON.parse(text);
        steps = Array.isArray(parsed) ? parsed : parsed.steps || [];
      } catch (err) {
        alert(extractText(t('admin.import_invalid')));
        return;
      }

      const replace = !!store.listing?.data.length && confirm(extractText(t('admin.presets.replace_confirm')));
      importSteps(steps, replace).then(() => m.redraw());
    });
  }

  // —— Settings —————————————————————————————————————————————————————————

  settingsTab() {
    const meta = store.listing?.meta;
    const groups = app.store
      .all<any>('groups')
      .filter((g) => Number(g.id()) > 3)
      .reduce((o: Record<string, string>, g) => ({ ...o, [g.id()]: g.namePlural() }), { '0': extractText(t('admin.settings.no_group')) });

    return (
      <div className="OnrampAdmin-settings">
        <div className="Form">
          {this.buildSettingComponent({
            setting: key('enabled'),
            type: 'boolean',
            label: t('admin.settings.enabled'),
            help: t('admin.settings.enabled_help'),
          })}
          {this.buildSettingComponent({
            setting: key('launcher'),
            type: 'boolean',
            label: t('admin.settings.launcher'),
            help: t('admin.settings.launcher_help'),
          })}

          <h3>{t('admin.settings.reward_heading')}</h3>
          <p className="helpText">{t('admin.settings.reward_help')}</p>
          {meta?.points ? (
            this.buildSettingComponent({
              setting: key('reward_points'),
              type: 'number',
              min: 0,
              max: 100000,
              label: t('admin.settings.reward_points'),
            })
          ) : (
            <p className="helpText">{t('admin.needs_points')}</p>
          )}
          {meta?.badges ? (
            this.buildSettingComponent({
              setting: key('reward_badge'),
              type: 'select',
              options: this.badgeOptions,
              default: '0',
              label: t('admin.settings.reward_badge'),
              help: t('admin.settings.reward_badge_help'),
            })
          ) : (
            <p className="helpText">{t('admin.needs_badges')}</p>
          )}
          {this.buildSettingComponent({
            setting: key('reward_group'),
            type: 'select',
            options: groups,
            default: '0',
            label: t('admin.settings.reward_group'),
            help: t('admin.settings.reward_group_help'),
          })}

          <h3>{t('admin.settings.reminders_heading')}</h3>
          {this.buildSettingComponent({
            setting: key('reminders'),
            type: 'boolean',
            label: t('admin.settings.reminders'),
            help: t('admin.settings.reminders_help'),
          })}
          {this.buildSettingComponent({
            setting: key('reminder_days'),
            type: 'text',
            placeholder: '3,7,14',
            label: t('admin.settings.reminder_days'),
            help: t('admin.settings.reminder_days_help'),
          })}
          {this.buildSettingComponent({
            setting: key('close_after'),
            type: 'number',
            min: 0,
            max: 3650,
            label: t('admin.settings.close_after'),
            help: t('admin.settings.close_after_help'),
          })}

          <div className="Form-group">{this.submitButton()}</div>
        </div>

        <div className="OnrampAdmin-start">
          <h3>{t('admin.start.heading')}</h3>
          <p className="helpText">{t('admin.start.help')}</p>
          <div className="OnrampAdmin-startRow">
            <input
              className="FormControl"
              type="number"
              min="1"
              max="3650"
              value={this.days}
              oninput={(e: InputEvent) => (this.days = (e.target as HTMLInputElement).value)}
            />
            <Button className="Button" loading={this.starting} disabled={this.starting} onclick={() => this.startExisting()}>
              {t('admin.start.button')}
            </Button>
          </div>
          {this.startedTotal !== null ? (
            <p className="helpText">{t('admin.start.result', { started: this.startedTotal, remaining: this.remaining ?? 0 })}</p>
          ) : null}
        </div>
      </div>
    );
  }

  /** One batch at a time until none are left, so no request runs long. */
  startExisting() {
    this.starting = true;
    this.startedTotal = 0;

    const next = (): Promise<void> =>
      startExisting(Number(this.days) || 30).then((res) => {
        this.startedTotal! += res.started;
        this.remaining = res.remaining;
        m.redraw();
        return res.started > 0 && res.remaining > 0 ? next() : undefined;
      });

    next()
      .catch(() => {})
      .finally(() => {
        this.starting = false;
        m.redraw();
      });
  }

  // —— Funnel ———————————————————————————————————————————————————————————

  loadFunnel() {
    this.funnelLoading = true;
    funnel(this.weeks)
      .then((data) => (this.funnelData = data))
      .catch(() => {})
      .finally(() => {
        this.funnelLoading = false;
        m.redraw();
      });
  }

  funnelTab() {
    const data = this.funnelData;
    const pct = (n: number) => `${Math.round(n * 100)}%`;
    const hours = (h: number | null) =>
      h === null
        ? '—'
        : h < 1
          ? extractText(t('admin.funnel.minutes', { count: Math.max(1, Math.round(h * 60)) }))
          : h < 48
            ? extractText(t('admin.funnel.hours', { count: Math.round(h) }))
            : extractText(t('admin.funnel.days', { count: Math.round(h / 24) }));

    return (
      <div className="OnrampAdmin-funnel">
        <div className="OnrampAdmin-funnelBar">
          <Select
            value={String(this.weeks)}
            options={{
              '4': extractText(t('admin.funnel.weeks', { count: 4 })),
              '8': extractText(t('admin.funnel.weeks', { count: 8 })),
              '12': extractText(t('admin.funnel.weeks', { count: 12 })),
              '26': extractText(t('admin.funnel.weeks', { count: 26 })),
            }}
            onchange={(v: string) => {
              this.weeks = Number(v);
              this.loadFunnel();
            }}
          />
        </div>

        {this.funnelLoading || !data ? (
          <LoadingIndicator />
        ) : !data.totals.started ? (
          <p className="OnrampAdmin-empty">{t('admin.funnel.empty')}</p>
        ) : (
          [
            <div className="OnrampAdmin-tiles">
              {['started', 'graduated', 'open', 'closed'].map((k) => (
                <div className="OnrampAdmin-tile">
                  <strong>{data.totals[k]}</strong>
                  <span>{t(`admin.funnel.${k}`)}</span>
                </div>
              ))}
              <div className="OnrampAdmin-tile">
                <strong>{pct(data.totals.graduated / data.totals.started)}</strong>
                <span>{t('admin.funnel.rate')}</span>
              </div>
            </div>,

            data.bottleneck ? (
              <p className="Alert Alert--warning OnrampAdmin-bottleneck">
                <Icon name="fas fa-triangle-exclamation" />{' '}
                {t('admin.funnel.bottleneck', { title: this.stepName(data.steps.find((s: any) => s.id === data.bottleneck)) })}
              </p>
            ) : null,

            <table className="OnrampAdmin-table">
              <thead>
                <tr>
                  <th>{t('admin.funnel.step')}</th>
                  <th>{t('admin.funnel.reached')}</th>
                  <th>{t('admin.funnel.median')}</th>
                  <th>{t('admin.funnel.stuck')}</th>
                </tr>
              </thead>
              <tbody>
                {data.steps.map((s: any) => (
                  <tr className={s.id === data.bottleneck ? 'is-bottleneck' : ''}>
                    <td>{this.stepName(s)}</td>
                    <td>
                      <span className="OnrampAdmin-meter">
                        <span style={{ width: pct(s.rate) }} />
                      </span>{' '}
                      {pct(s.rate)} <span className="OnrampAdmin-muted">({s.reached})</span>
                    </td>
                    <td>{hours(s.medianHours)}</td>
                    <td>{s.stuck}</td>
                  </tr>
                ))}
              </tbody>
            </table>,

            <h3>{t('admin.funnel.cohorts')}</h3>,
            <div className="OnrampAdmin-scroll">
              <table className="OnrampAdmin-table OnrampAdmin-cohorts">
                <thead>
                  <tr>
                    <th>{t('admin.funnel.week')}</th>
                    <th>{t('admin.funnel.started')}</th>
                    {data.steps.map((s: any, i: number) => (
                      <th title={this.stepName(s)}>{i + 1}</th>
                    ))}
                    <th>{t('admin.funnel.graduated')}</th>
                  </tr>
                </thead>
                <tbody>
                  {data.cohorts.map((c: any) => (
                    <tr>
                      <td>{c.start}</td>
                      <td>{c.started}</td>
                      {data.steps.map((s: any) => (
                        <td>{c.started ? pct((c.steps[s.id] || 0) / c.started) : '—'}</td>
                      ))}
                      <td>{c.started ? pct(c.graduated / c.started) : '—'}</td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>,
          ]
        )}
      </div>
    );
  }

  stepName(s: any): string {
    return s ? s.title || extractText(t(`lib.types.${s.type}.title`, { count: s.target || 1, tag: '' })) : '';
  }
}
