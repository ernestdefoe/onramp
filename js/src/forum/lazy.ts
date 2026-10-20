declare const m: import('mithril').Static;

type JourneyModule = typeof import('./components/Journey');

/** The panel-and-widget chunk, fetched once by whichever asks first. */
export const journeyChunk: { loaded: JourneyModule | null; load(): Promise<JourneyModule> } = {
  loaded: null,
  load() {
    return (pending ??= import('./components/Journey').then((mod) => {
      journeyChunk.loaded = mod;
      m.redraw();
      return mod;
    }));
  },
};

let pending: Promise<JourneyModule> | null = null;
