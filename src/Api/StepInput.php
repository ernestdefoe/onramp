<?php

namespace ErnestDefoe\Onramp\Api;

use ErnestDefoe\Onramp\StepType\Registry;
use Flarum\Foundation\ValidationException;
use Flarum\Locale\TranslatorInterface;

/**
 * What an admin may write on a step, checked and normalised in one place —
 * the editor and the import both come through here.
 */
class StepInput
{
    public function __construct(private Registry $types, private TranslatorInterface $translator)
    {
    }

    /**
     * @param array<string, mixed> $in
     * @param bool $partial an edit: only the fields given are checked
     * @return array<string, mixed> columns for onramp_steps
     */
    public function normalise(array $in, bool $partial = false): array
    {
        $out = [];
        $errors = [];
        $has = fn (string $k) => array_key_exists($k, $in);

        if (! $partial || $has('type')) {
            $type = $this->types->get((string) ($in['type'] ?? ''));

            if (! $type) {
                $errors['type'] = $this->t('type');
            } else {
                $out['type'] = $type->key();
            }
        }

        if ($has('config')) {
            $config = [];

            foreach ((array) $in['config'] as $key => $value) {
                if ($key === 'count' && $value !== null && $value !== '') {
                    $count = (int) $value;
                    $count >= 1 && $count <= 1000 ? $config['count'] = $count : $errors['count'] = $this->t('count');
                } elseif ($key === 'tag' && (int) $value > 0) {
                    $config['tag'] = (int) $value;
                } elseif ($key === 'path' && trim((string) $value) !== '') {
                    $path = trim((string) $value);
                    $this->isLink($path) && strlen($path) <= 255 ? $config['path'] = $path : $errors['path'] = $this->t('path');
                }
            }

            $out['config'] = $config ?: null;
        }

        foreach (['title' => 150, 'description' => 1000, 'icon' => 100, 'button_label' => 100] as $key => $max) {
            if ($has($key)) {
                $value = trim((string) $in[$key]);
                mb_strlen($value) <= $max ? $out[$key] = ($value === '' ? null : $value) : $errors[$key] = $this->t('too_long');
            }
        }

        if ($has('button_url')) {
            $url = trim((string) $in['button_url']);

            if ($url === '') {
                $out['button_url'] = null;
            } elseif ($this->isLink($url) && strlen($url) <= 255) {
                $out['button_url'] = $url;
            } else {
                $errors['button_url'] = $this->t('path');
            }
        }

        if ($has('points')) {
            $points = (int) $in['points'];
            $points >= 0 && $points <= 100000 ? $out['points'] = $points : $errors['points'] = $this->t('points');
        }

        if ($has('badge_id')) {
            $out['badge_id'] = (int) $in['badge_id'] > 0 ? (int) $in['badge_id'] : null;
        }

        foreach (['is_required', 'is_enabled'] as $key) {
            if ($has($key)) {
                $out[$key] = (bool) $in[$key];
            }
        }

        if ($errors) {
            throw new ValidationException($errors);
        }

        return $out;
    }

    /**
     * A forum path or an http(s) address — never `javascript:` or any other
     * scheme, since this ends up as a link a member clicks.
     */
    private function isLink(string $value): bool
    {
        // No control characters, spaces or backslashes anywhere: browsers
        // drop or rewrite them, so `/\evil.com` or `/<tab>/evil.com` reads
        // as `//evil.com` — another site.
        if (preg_match('/[\x00-\x20\x7f\\\\]/', $value)) {
            return false;
        }

        return (str_starts_with($value, '/') && ! str_starts_with($value, '//'))
            || (bool) preg_match('#^https?://[^/]#i', $value);
    }

    private function t(string $key): string
    {
        return $this->translator->trans('ernestdefoe-onramp.lib.errors.'.$key);
    }
}
