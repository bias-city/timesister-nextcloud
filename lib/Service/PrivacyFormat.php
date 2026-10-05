<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\TimeSister\Service;

/**
 * Renders the tree of {@see PrivacyDocument} as Markdown or as a printable
 * HTML page without external resources (styles inline). Every text is
 * escaped in the HTML; in Markdown table cells keep to one line and
 * pipes are escaped. Pure.
 */
final class PrivacyFormat {
	public const FORMATS = ['html', 'md'];

	/** `?format=` of the routes: `html` or `md`, otherwise 400. */
	public static function checkFormat(mixed $format): string {
		$f = $format === null || $format === '' ? 'html' : $format;
		if (!is_string($f) || !in_array($f, self::FORMATS, true)) {
			throw ApiException::badRequest('“format” must be “html” or “md”.');
		}
		return $f;
	}

	/** `timesister-privacy-<slug>-<YYYY-MM-DD>.<html|md>` */
	public static function fileName(string $slug, string $date, string $format): string {
		return 'timesister-privacy-' . $slug . '-' . $date . '.' . $format;
	}

	public static function mime(string $format): string {
		return $format === 'md' ? 'text/markdown; charset=utf-8' : 'text/html; charset=utf-8';
	}

	/** @param array{title:string,intro:list<string>,sections:list<array{title:string,blocks:list<array<string,mixed>>}>} $doc */
	public static function markdown(array $doc): string {
		$out = ['# ' . self::line($doc['title']), ''];
		foreach ($doc['intro'] as $p) {
			$out[] = self::line($p);
			$out[] = '';
		}
		foreach ($doc['sections'] as $i => $s) {
			$out[] = '## ' . ($i + 1) . '. ' . self::line($s['title']);
			$out[] = '';
			foreach ($s['blocks'] as $b) {
				$type = (string)($b['type'] ?? '');
				if ($type === 'p' || $type === 'note') {
					$out[] = ($type === 'note' ? '*' : '') . self::line((string)($b['text'] ?? '')) . ($type === 'note' ? '*' : '');
					$out[] = '';
				} elseif ($type === 'list') {
					/** @var list<string> $items */
					$items = $b['items'] ?? [];
					foreach ($items as $item) {
						$out[] = '- ' . self::line($item);
					}
					if ($items === []) {
						$out[] = '–';
					}
					$out[] = '';
				} elseif ($type === 'table') {
					/** @var list<string> $head */
					$head = $b['head'] ?? [];
					/** @var list<list<string>> $rows */
					$rows = $b['rows'] ?? [];
					$cell = static fn (string $c): string => str_replace('|', '\\|', self::line($c));
					$out[] = '| ' . implode(' | ', array_map($cell, $head)) . ' |';
					$out[] = '|' . str_repeat(' --- |', count($head));
					foreach ($rows as $r) {
						$out[] = '| ' . implode(' | ', array_map($cell, $r)) . ' |';
					}
					if ($rows === []) {
						$out[] = '| ' . implode(' | ', array_fill(0, max(1, count($head)), '–')) . ' |';
					}
					$out[] = '';
				}
			}
		}
		return rtrim(implode("\n", $out)) . "\n";
	}

	/** One line without control characters. */
	private static function line(string $s): string {
		return trim((string)preg_replace('/[\x00-\x1F\x7F]+/', ' ', $s));
	}

	/** @param array{title:string,intro:list<string>,sections:list<array{title:string,blocks:list<array<string,mixed>>}>} $doc */
	public static function html(array $doc, string $lang): string {
		$e = static fn (string $s): string => htmlspecialchars(self::line($s), ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');
		$h = [];
		$h[] = '<!DOCTYPE html>';
		$h[] = '<html lang="' . $e($lang) . '">';
		$h[] = '<head>';
		$h[] = '<meta charset="utf-8">';
		$h[] = '<meta name="viewport" content="width=device-width, initial-scale=1">';
		$h[] = '<meta name="referrer" content="no-referrer">';
		$h[] = '<title>' . $e($doc['title']) . '</title>';
		$h[] = '<style>' . self::CSS . '</style>';
		$h[] = '</head>';
		$h[] = '<body>';
		$h[] = '<main>';
		$h[] = '<h1>' . $e($doc['title']) . '</h1>';
		foreach ($doc['intro'] as $i => $p) {
			$h[] = '<p class="' . ($i === 0 ? 'lede' : 'hint') . '">' . $e($p) . '</p>';
		}
		foreach ($doc['sections'] as $i => $s) {
			$h[] = '<section>';
			$h[] = '<h2>' . ($i + 1) . '. ' . $e($s['title']) . '</h2>';
			foreach ($s['blocks'] as $b) {
				$type = (string)($b['type'] ?? '');
				if ($type === 'p') {
					$h[] = '<p>' . $e((string)($b['text'] ?? '')) . '</p>';
				} elseif ($type === 'note') {
					$h[] = '<p class="note">' . $e((string)($b['text'] ?? '')) . '</p>';
				} elseif ($type === 'list') {
					/** @var list<string> $items */
					$items = $b['items'] ?? [];
					$h[] = $items === [] ? '<p>–</p>' : '<ul>' . implode('', array_map(static fn (string $x) => '<li>' . $e($x) . '</li>', $items)) . '</ul>';
				} elseif ($type === 'table') {
					/** @var list<string> $head */
					$head = $b['head'] ?? [];
					/** @var list<list<string>> $rows */
					$rows = $b['rows'] ?? [];
					$h[] = '<table><thead><tr>' . implode('', array_map(static fn (string $x) => '<th>' . $e($x) . '</th>', $head)) . '</tr></thead><tbody>';
					foreach ($rows as $r) {
						$h[] = '<tr>' . implode('', array_map(static fn (string $x) => '<td>' . $e($x) . '</td>', $r)) . '</tr>';
					}
					if ($rows === []) {
						$h[] = '<tr><td colspan="' . max(1, count($head)) . '">–</td></tr>';
					}
					$h[] = '</tbody></table>';
				}
			}
			$h[] = '</section>';
		}
		$h[] = '</main>';
		$h[] = '</body>';
		$h[] = '</html>';
		return implode("\n", $h) . "\n";
	}

	/** Printable, no external fonts or scripts; A4 with margins when printed. */
	private const CSS = <<<'CSS'
:root{color-scheme:light}
body{margin:0;font:15px/1.5 -apple-system,"Helvetica Neue",Helvetica,Arial,sans-serif;color:#1d1d1f;background:#fff}
main{max-width:960px;margin:0 auto;padding:40px 32px 64px}
h1{font-size:28px;line-height:1.2;margin:0 0 12px}
h2{font-size:19px;margin:36px 0 10px;padding-top:12px;border-top:1px solid #d8d8dc}
p{margin:8px 0}
p.lede{font-size:16px}
p.hint,p.note{color:#5b5b60;font-style:italic}
ul{margin:8px 0;padding-left:22px}
li{margin:3px 0}
table{width:100%;border-collapse:collapse;margin:10px 0 14px;font-size:14px}
th,td{text-align:left;vertical-align:top;padding:6px 8px;border-bottom:1px solid #d8d8dc}
th{color:#5b5b60;font-weight:600;border-bottom-color:#9a9aa0}
@media print{main{max-width:none;padding:0}h2{break-after:avoid}table,tr{break-inside:avoid}@page{size:A4;margin:18mm 16mm}}
CSS;
}
