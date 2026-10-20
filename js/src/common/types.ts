/** Shapes of what the server sends. Mirrors src/Presenter.php. */

export interface BadgeView {
  id: number;
  name: string;
  icon: string;
  color: string | null;
  background: string | null;
}

export interface StepView {
  id: number;
  type: string;
  title: string | null;
  description: string | null;
  icon: string | null;
  buttonLabel: string | null;
  buttonUrl: string | null;
  required: boolean;
  points: number;
  badge: BadgeView | null;
  target: number;
  tag: { id: number; name: string; slug: string } | null;
  path: string | null;
  completedAt: string | null;
  skippedAt: string | null;
  /** Live count; only in a full read (GET /api/onramp). */
  progress?: number;
}

export interface JourneyView {
  status: 'open' | 'graduated' | 'closed';
  startedAt: string;
  completedAt: string | null;
  done: number;
  skipped: number;
  total: number;
  launcher: boolean;
  steps: StepView[];
  reward: { points: number; badge: BadgeView | null };
}

export interface OnrampMeta {
  completed: number[];
  graduated: boolean;
  state?: JourneyView | null;
}
