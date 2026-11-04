<?php

namespace Sitecommand\Laravel;

/**
 * The app's dependencies for the hub's vulnerability check: Composer
 * packages as installed (vendor/composer/installed.json, else composer.lock),
 * plus npm packages when a lockfile is deployed. Each carries its ecosystem
 * ("packagist" or "npm") so the hub asks the right database.
 */
final class Dependencies
{
    /** @return array{ok: true, source: string, dependencies: list<array{name: string, version: string, direct: bool, ecosystem: string}>} */
    public static function read(?string $root = null): array
    {
        $root = rtrim($root ?? base_path(), '/');
        $sources = [];
        $deps = [];

        $direct = self::composerDirect($root);
        [$src, $composer] = self::composer($root, $direct);
        if ($composer !== []) {
            $sources[] = $src;
            $deps = $composer;
        }

        [$src, $npm] = self::npm($root);
        if ($npm !== []) {
            $sources[] = $src;
            $deps = array_merge($deps, $npm);
        }

        usort($deps, fn ($a, $b) => [$a['ecosystem'], $a['name']] <=> [$b['ecosystem'], $b['name']]);

        return ['ok' => true, 'source' => $sources === [] ? 'none' : implode('+', $sources), 'dependencies' => $deps];
    }

    /** @return array<string, true> */
    private static function composerDirect(string $root): array
    {
        $json = self::json($root.'/composer.json');
        $names = array_merge(array_keys((array) ($json['require'] ?? [])), array_keys((array) ($json['require-dev'] ?? [])));

        return array_fill_keys(array_filter($names, fn ($n) => self::isPackage($n)), true);
    }

    private static function isPackage(string $name): bool
    {
        return str_contains($name, '/') && ! str_starts_with($name, 'ext-') && ! str_starts_with($name, 'lib-');
    }

    /**
     * @param  array<string, true>  $direct
     * @return array{0: string, 1: list<array{name: string, version: string, direct: bool, ecosystem: string}>}
     */
    private static function composer(string $root, array $direct): array
    {
        $installed = self::json($root.'/vendor/composer/installed.json');
        if ($installed !== null) {
            $packages = isset($installed['packages']) ? (array) $installed['packages'] : $installed; // Composer 2 / 1

            return ['composer-installed', self::fromComposerPackages($packages, $direct)];
        }
        $lock = self::json($root.'/composer.lock');
        if ($lock !== null) {
            return ['composer-lock', self::fromComposerPackages(array_merge((array) ($lock['packages'] ?? []), (array) ($lock['packages-dev'] ?? [])), $direct)];
        }

        return ['none', []];
    }

    /**
     * @param  array<int, mixed>  $packages
     * @param  array<string, true>  $direct
     * @return list<array{name: string, version: string, direct: bool, ecosystem: string}>
     */
    public static function fromComposerPackages(array $packages, array $direct): array
    {
        $out = [];
        foreach ($packages as $p) {
            if (! is_array($p) || ! isset($p['name'], $p['version']) || ! self::isPackage((string) $p['name'])) {
                continue;
            }
            $version = ltrim((string) $p['version'], 'vV');
            $out[$p['name'].'@'.$version] = ['name' => (string) $p['name'], 'version' => $version, 'direct' => isset($direct[$p['name']]), 'ecosystem' => 'packagist'];
        }

        return array_values($out);
    }

    /** @return array{0: string, 1: list<array{name: string, version: string, direct: bool, ecosystem: string}>} */
    private static function npm(string $root): array
    {
        $pkg = self::json($root.'/package.json');
        $direct = array_fill_keys(array_merge(array_keys((array) ($pkg['dependencies'] ?? [])), array_keys((array) ($pkg['devDependencies'] ?? []))), true);
        if (is_readable($root.'/package-lock.json')) {
            $lock = self::json($root.'/package-lock.json');
            if ($lock !== null) {
                return ['package-lock', self::fromPackageLock($lock, $direct)];
            }
        }
        if (is_readable($root.'/pnpm-lock.yaml')) {
            return ['pnpm-lock', self::fromPnpmLock((string) file_get_contents($root.'/pnpm-lock.yaml'), $direct)];
        }

        return ['none', []];
    }

    /**
     * package-lock.json v2/v3. Pure.
     *
     * @param  array<string, mixed>  $lock
     * @param  array<string, true>  $direct
     * @return list<array{name: string, version: string, direct: bool, ecosystem: string}>
     */
    public static function fromPackageLock(array $lock, array $direct): array
    {
        $out = [];
        foreach ((array) ($lock['packages'] ?? []) as $path => $info) {
            if ($path === '' || ! is_array($info) || empty($info['version']) || ! empty($info['link']) || ! str_contains((string) $path, 'node_modules/')) {
                continue;
            }
            $path = (string) $path;
            $name = substr($path, strrpos($path, 'node_modules/') + strlen('node_modules/'));
            if ($name === '') {
                continue;
            }
            $nested = substr_count($path, 'node_modules/') > 1;
            $out[$name.'@'.$info['version']] = ['name' => $name, 'version' => (string) $info['version'], 'direct' => isset($direct[$name]) && ! $nested, 'ecosystem' => 'npm'];
        }

        return array_values($out);
    }

    /**
     * pnpm-lock.yaml (v6–v9) package keys like `/name@1.2.3` or `name@1.2.3(peer)`. Pure.
     *
     * @param  array<string, true>  $direct
     * @return list<array{name: string, version: string, direct: bool, ecosystem: string}>
     */
    public static function fromPnpmLock(string $yaml, array $direct): array
    {
        $parts = preg_split('/\n(?=packages:\s*\n)/', $yaml);
        $section = $parts[1] ?? '';
        $section = preg_split('/\n(?=snapshots:\s*\n)/', $section)[0] ?? $section;
        $out = [];
        if (preg_match_all('/^\s{2}[\'"]?\/?((?:@[^\/\s\'"]+\/)?[^@\s\'"\/]+)@([0-9][^(:\s\'"]*)/m', $section, $m, PREG_SET_ORDER)) {
            foreach ($m as $x) {
                $out[$x[1].'@'.$x[2]] = ['name' => $x[1], 'version' => $x[2], 'direct' => isset($direct[$x[1]]), 'ecosystem' => 'npm'];
            }
        }

        return array_values($out);
    }

    /** @return array<string, mixed>|null */
    private static function json(string $file): ?array
    {
        if (! is_readable($file)) {
            return null;
        }
        $data = json_decode((string) file_get_contents($file), true);

        return is_array($data) ? $data : null;
    }
}
