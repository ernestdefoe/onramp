import css from './generated/pagesCss';

/** Onramp's page styles, added once, the first time a lazy chunk needs them. */
export default function useStyles(): void {
  if (document.getElementById('onramp-styles')) return;
  const style = document.createElement('style');
  style.id = 'onramp-styles';
  style.textContent = css;
  document.head.appendChild(style);
}
