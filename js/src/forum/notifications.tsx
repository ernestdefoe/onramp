import app from 'flarum/forum/app';
import Notification from 'flarum/forum/components/Notification';
import { t } from '../common/text';

/** "A step finished itself": a like received, an answer marked best. */
export class StepCompletedNotification extends Notification {
  icon() {
    return 'fas fa-circle-check';
  }

  href() {
    return app.route('onramp');
  }

  content() {
    return t('forum.notifications.step');
  }

  excerpt() {
    return null;
  }
}

export class GraduatedNotification extends Notification {
  icon() {
    return 'fas fa-flag-checkered';
  }

  href() {
    return app.route('onramp');
  }

  content() {
    return t('forum.notifications.graduated');
  }

  excerpt() {
    return null;
  }
}

export class ReminderNotification extends Notification {
  icon() {
    return 'fas fa-route';
  }

  href() {
    return app.route('onramp');
  }

  content() {
    const data = this.attrs.notification.content<{ left: number }>() || { left: 0 };
    return t('forum.notifications.reminder', { count: data.left });
  }

  excerpt() {
    return null;
  }
}
