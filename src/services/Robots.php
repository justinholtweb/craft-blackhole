<?php

namespace justinholtweb\blackhole\services;

use Craft;
use justinholtweb\blackhole\Plugin;
use Throwable;
use yii\base\Component;

/**
 * The half of the trap that lives outside Craft.
 *
 * The honeypot only means anything if crawlers were told to stay away from it — without the
 * robots.txt directive, following the hidden link is not disobedience, it is just crawling. So
 * this service exists to make the directive impossible to forget: it generates it, it reads the
 * site's actual `robots.txt` back and says whether the rule is really in there, and it can write
 * the rule in.
 */
class Robots extends Component
{
    /**
     * The directives, ready to paste.
     */
    public function directives(?int $siteId = null): string
    {
        $lines = ['# Black Hole for bad bots — do not remove'];
        $lines[] = 'User-agent: *';

        foreach ($this->paths($siteId) as $path) {
            $lines[] = 'Disallow: ' . $path;
        }

        return implode("\n", $lines) . "\n";
    }

    /**
     * Every distinct path the trap answers on.
     *
     * A multi-site install can mount its sites under different base paths, so `/blackhole/` and
     * `/de/blackhole/` are both real and both need naming — one robots.txt serves the whole host.
     *
     * @return string[]
     */
    public function paths(?int $siteId = null): array
    {
        $trap = Plugin::getInstance()->trap;

        if ($siteId !== null) {
            return [$trap->robotsPath($siteId)];
        }

        $paths = [];

        foreach (Craft::$app->getSites()->getAllSites() as $site) {
            try {
                $path = $trap->robotsPath($site->id);
            } catch (Throwable) {
                continue;
            }

            if (!in_array($path, $paths, true)) {
                $paths[] = $path;
            }
        }

        return $paths ?: ['/' . $trap->path() . '/'];
    }

    // ------------------------------------------------------------------ the actual file

    public function filePath(): string
    {
        foreach (['@webroot', '@root/web'] as $alias) {
            try {
                $path = Craft::getAlias($alias, false);
            } catch (Throwable) {
                continue;
            }

            if (is_string($path) && $path !== '' && is_dir($path)) {
                return rtrim($path, '/\\') . DIRECTORY_SEPARATOR . 'robots.txt';
            }
        }

        return '';
    }

    public function fileExists(): bool
    {
        $path = $this->filePath();

        return $path !== '' && is_file($path);
    }

    public function fileContents(): ?string
    {
        if (!$this->fileExists()) {
            return null;
        }

        $contents = @file_get_contents($this->filePath());

        return $contents === false ? null : $contents;
    }

    /**
     * Whether the site's robots.txt already disallows the trap.
     *
     * Deliberately forgiving about spacing and case, and deliberately naive about groups: a
     * `Disallow` for the trap path under *some* user-agent is close enough to say "you have
     * done this", and a false yes here costs less than nagging an author who has.
     */
    public function fileCoversTrap(): bool
    {
        $contents = $this->fileContents();

        if ($contents === null) {
            return false;
        }

        foreach ($this->paths() as $path) {
            $pattern = '~^\s*disallow\s*:\s*' . preg_quote(rtrim($path, '/'), '~') . '/?\s*$~mi';

            if (!preg_match($pattern, $contents)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Adds the directives to the site's robots.txt, creating the file if there isn't one.
     *
     * Idempotent — running it twice does not produce two copies — and it appends rather than
     * replaces, because whatever else is in that file belongs to somebody.
     *
     * @return array{written: bool, path: string, message: string}
     */
    public function write(): array
    {
        $path = $this->filePath();

        if ($path === '') {
            return [
                'written' => false,
                'path' => '',
                'message' => Craft::t('blackhole', 'Could not work out where the site’s web root is.'),
            ];
        }

        if ($this->fileCoversTrap()) {
            return [
                'written' => false,
                'path' => $path,
                'message' => Craft::t('blackhole', 'robots.txt already disallows the trap. Nothing to do.'),
            ];
        }

        $existing = $this->fileContents() ?? '';
        $separator = $existing === '' ? '' : (str_ends_with($existing, "\n") ? "\n" : "\n\n");

        $result = @file_put_contents($path, $existing . $separator . $this->directives());

        if ($result === false) {
            return [
                'written' => false,
                'path' => $path,
                'message' => Craft::t('blackhole', 'Could not write to {path}. Check its permissions.', ['path' => $path]),
            ];
        }

        return [
            'written' => true,
            'path' => $path,
            'message' => Craft::t('blackhole', 'Added the trap’s directives to {path}.', ['path' => $path]),
        ];
    }

    /**
     * Whether the plugin should answer `/robots.txt` itself.
     *
     * Only when asked to *and* there is no file — a plugin route that quietly shadows a file the
     * author wrote is the kind of thing that eats an afternoon.
     */
    public function shouldServe(): bool
    {
        return Plugin::getInstance()->getSettings()->serveRobotsTxt && !$this->fileExists();
    }
}
