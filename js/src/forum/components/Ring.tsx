import Component from 'flarum/common/Component';

interface RingAttrs {
  done: number;
  total: number;
  size?: number;
  label?: any;
}

/**
 * The progress ring: an SVG circle whose stroke is the share done.
 *
 * Drawn with `stroke-dasharray` on a circle of circumference 100 (r = 15.915),
 * so the dash IS the percentage — no maths in the view, and it animates with a
 * plain CSS transition when the number moves.
 */
export default class Ring extends Component<RingAttrs> {
  view() {
    const { done, total, size = 36, label } = this.attrs;
    const share = total ? Math.round((done / total) * 100) : 0;

    return (
      <span className="OnrampRing" style={{ width: `${size}px`, height: `${size}px` }}>
        <svg viewBox="0 0 36 36" aria-hidden="true">
          <circle className="OnrampRing-track" cx="18" cy="18" r="15.915" />
          {/* At 0% a round line cap still draws a dot, so there is no fill at all. */}
          {share > 0 ? (
            <circle className="OnrampRing-fill" cx="18" cy="18" r="15.915" stroke-dasharray={`${share} ${100 - share}`} stroke-dashoffset="25" />
          ) : null}
        </svg>
        {label !== undefined ? <span className="OnrampRing-label">{label}</span> : null}
      </span>
    );
  }
}
