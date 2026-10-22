import app from 'flarum/admin/app';
import OnrampAdminPage from './components/OnrampPage';
import { registerEditorWidgets } from '../common/fofWidgets';

/*
 * 🚨 `app.registry`, the Flarum 2 API. `app.extensionData` is the 1.x one and
 * is gone in 2.0 — calling it throws inside the initializer and the admin only
 * says "failed to initialize".
 */
app.initializers.add('ernestdefoe/onramp', () => {
  app.registry
    .for('ernestdefoe-onramp')
    .registerPage(OnrampAdminPage)
    .registerPermission(
      {
        icon: 'fas fa-route',
        label: app.translator.trans('ernestdefoe-onramp.admin.permissions.view_members'),
        permission: 'onramp.viewMembers',
      },
      'moderate'
    );

  // FoF's layout editor lists only widgets the admin registers too.
  registerEditorWidgets(app, 'ernestdefoe-onramp', [
    {
      key: 'onrampJourney',
      icon: 'fas fa-route',
      title: () => app.translator.trans('ernestdefoe-onramp.admin.widgets.journey'),
      placement: 'start_top',
    },
  ]);
});
