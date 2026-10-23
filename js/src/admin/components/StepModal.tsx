import app from 'flarum/admin/app';
import FormModal, { type IFormModalAttrs } from 'flarum/common/components/FormModal';
import Button from 'flarum/common/components/Button';
import Select from 'flarum/common/components/Select';
import Switch from 'flarum/common/components/Switch';
import extractText from 'flarum/common/utils/extractText';
import Stream from 'flarum/common/utils/Stream';
import type { AdminStep, BadgeOption, TypeInfo } from '../api';
import { loadBadges, pointsHint, save, store } from '../api';
import { t } from '../../common/text';

declare const m: import('mithril').Static;

interface StepModalAttrs extends IFormModalAttrs {
  step?: AdminStep;
}

/** Add or change one step. Empty text fields fall back to the type's own wording. */
export default class StepModal extends FormModal<StepModalAttrs> {
  type!: Stream<string>;
  count!: Stream<string>;
  tag!: Stream<string>;
  path!: Stream<string>;
  label!: Stream<string>;
  description!: Stream<string>;
  icon!: Stream<string>;
  buttonLabel!: Stream<string>;
  buttonUrl!: Stream<string>;
  points!: Stream<string>;
  badgeId!: Stream<string>;
  required!: Stream<boolean>;
  enabled!: Stream<boolean>;
  badges: BadgeOption[] | null = null;

  oninit(vnode: any) {
    super.oninit(vnode);
    const s = this.attrs.step;
    const types = this.types();

    this.type = Stream(s?.type || types[0]?.key || 'reply');
    this.count = Stream(String(s?.config?.count ?? ''));
    this.tag = Stream(String(s?.config?.tag ?? ''));
    this.path = Stream(s?.config?.path ?? '');
    this.label = Stream(s?.title ?? '');
    this.description = Stream(s?.description ?? '');
    this.icon = Stream(s?.icon ?? '');
    this.buttonLabel = Stream(s?.buttonLabel ?? '');
    this.buttonUrl = Stream(s?.buttonUrl ?? '');
    this.points = Stream(String(s?.points ?? (store.listing?.meta.points ? 10 : 0)));
    this.badgeId = Stream(String(s?.badgeId ?? ''));
    this.required = Stream(!!s?.required);
    this.enabled = Stream(s ? s.enabled : true);

    if (store.listing?.meta.badges) {
      loadBadges().then((list) => {
        this.badges = list;
        m.redraw();
      });
    }
  }

  className() {
    return 'OnrampStepModal Modal--medium';
  }

  title() {
    return this.attrs.step ? t('admin.steps.edit') : t('admin.steps.add');
  }

  types(): TypeInfo[] {
    return (store.listing?.meta.types || []).filter((type) => type.available || type.key === this.attrs.step?.type);
  }

  info(): TypeInfo | undefined {
    return this.types().find((type) => type.key === this.type());
  }

  /** The type's own wording, shown as the placeholder the admin can replace. */
  fallback(what: 'title' | 'description' | 'button'): string {
    return extractText(t(`lib.types.${this.type()}.${what}`, { count: Number(this.count()) || 1, tag: '' }));
  }

  content() {
    const info = this.info();
    const fields = info?.fields || [];
    const meta = store.listing?.meta;
    const tags = app.store.all<any>('tags');

    const options: Record<string, string> = {};
    this.types().forEach((type) => (options[type.key] = extractText(t(`admin.types.${type.key}`))));

    return (
      <div className="Modal-body">
        <div className="Form">
          <div className="Form-group">
            <label>{t('admin.steps.type')}</label>
            <Select value={this.type()} options={options} onchange={(v: string) => this.type(v)} />
            {info?.extension ? <p className="helpText">{t('admin.steps.from_extension', { extension: info.extension })}</p> : null}
          </div>

          {fields.includes('count') ? (
            <div className="Form-group">
              <label>{t('admin.steps.count')}</label>
              <input className="FormControl" type="number" min="1" max="1000" placeholder="1" bidi={this.count} />
            </div>
          ) : null}

          {fields.includes('tag') && tags.length ? (
            <div className="Form-group">
              <label>{t('admin.steps.tag')}</label>
              <Select
                value={this.tag()}
                options={tags.reduce((o: Record<string, string>, tag: any) => ({ ...o, [tag.id()]: tag.name() }), {
                  '': extractText(t('admin.steps.any_tag')),
                })}
                onchange={(v: string) => this.tag(v)}
              />
            </div>
          ) : null}

          {fields.includes('path') ? (
            <div className="Form-group">
              <label>{t('admin.steps.path')}</label>
              <input className="FormControl" placeholder="/d/1-welcome" bidi={this.path} />
              <p className="helpText">{t('admin.steps.path_help')}</p>
            </div>
          ) : null}

          <div className="Form-group">
            <label>{t('admin.steps.title')}</label>
            <input className="FormControl" maxlength="150" placeholder={this.fallback('title')} bidi={this.label} />
          </div>

          <div className="Form-group">
            <label>{t('admin.steps.description')}</label>
            <textarea className="FormControl" maxlength="1000" rows="2" placeholder={this.fallback('description')} bidi={this.description} />
          </div>

          <div className="OnrampStepModal-row">
            <div className="Form-group">
              <label>{t('admin.steps.button_label')}</label>
              <input className="FormControl" maxlength="100" placeholder={this.fallback('button')} bidi={this.buttonLabel} />
            </div>
            <div className="Form-group">
              <label>{t('admin.steps.button_url')}</label>
              <input
                className="FormControl"
                maxlength="255"
                placeholder={extractText(t('admin.steps.button_url_placeholder'))}
                bidi={this.buttonUrl}
              />
            </div>
          </div>

          <div className="OnrampStepModal-row">
            <div className="Form-group">
              <label>{t('admin.steps.icon')}</label>
              <input className="FormControl" maxlength="100" placeholder={info?.icon || 'fas fa-star'} bidi={this.icon} />
            </div>
            <div className="Form-group">
              <label>{t('admin.steps.points')}</label>
              <input className="FormControl" type="number" min="0" max="100000" bidi={this.points} disabled={!meta?.points} />
              {meta?.points ? null : <p className="helpText">{t(pointsHint())}</p>}
            </div>
          </div>

          <div className="Form-group">
            <label>{t('admin.steps.badge')}</label>
            {meta?.badges ? (
              <Select
                value={this.badgeId()}
                options={(this.badges || []).reduce((o: Record<string, string>, b) => ({ ...o, [b.id]: b.name }), {
                  '': extractText(t('admin.steps.no_badge')),
                })}
                onchange={(v: string) => this.badgeId(v)}
              />
            ) : (
              <p className="helpText">{t('admin.needs_badges')}</p>
            )}
          </div>

          <div className="Form-group">
            <Switch state={this.required()} onchange={this.required}>
              {t('admin.steps.required')}
            </Switch>
            <p className="helpText">{t('admin.steps.required_help')}</p>
          </div>

          <div className="Form-group">
            <Switch state={this.enabled()} onchange={this.enabled}>
              {t('admin.steps.enabled')}
            </Switch>
          </div>

          <div className="Form-group">
            <Button className="Button Button--primary" type="submit" loading={this.loading}>
              {t('admin.steps.save')}
            </Button>
          </div>
        </div>
      </div>
    );
  }

  onsubmit(e: SubmitEvent) {
    e.preventDefault();
    this.loading = true;

    const config: Record<string, unknown> = {};
    const fields = this.info()?.fields || [];
    if (fields.includes('count') && this.count()) config.count = Number(this.count());
    if (fields.includes('tag') && this.tag()) config.tag = Number(this.tag());
    if (fields.includes('path')) config.path = this.path();

    save(this.attrs.step?.id ?? null, {
      type: this.type(),
      config,
      title: this.label(),
      description: this.description(),
      icon: this.icon(),
      button_label: this.buttonLabel(),
      button_url: this.buttonUrl(),
      points: Number(this.points()) || 0,
      badge_id: Number(this.badgeId()) || null,
      is_required: this.required(),
      is_enabled: this.enabled(),
    })
      .then(() => this.hide())
      .catch(() => {})
      .finally(() => {
        this.loading = false;
        m.redraw();
      });
  }
}
