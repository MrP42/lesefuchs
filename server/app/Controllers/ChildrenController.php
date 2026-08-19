<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Db;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Core\Validator;
use App\Core\View;

/** Kinderprofile inkl. aller Player-Einstellungen (Konzept §7.1). */
final class ChildrenController
{
    public const AVATARS = ['fox' => '🦊', 'owl' => '🦉', 'bear' => '🐻', 'cat' => '🐱', 'dragon' => '🐲', 'unicorn' => '🦄'];
    public const HIGHLIGHT_MODES = [
        'SENTENCE' => 'Satz (Zuhören)',
        'WORD'     => 'Wort (Leseanfänger)',
        'SYLLABLE' => 'Silben (Leselernphase)',
        'KARAOKE'  => 'Karaoke (Fortgeschritten)',
    ];

    public function index(Request $request): Response
    {
        $children = Db::select(
            'SELECT c.*, s.highlight_mode FROM children c
             LEFT JOIN child_settings s ON s.child_id = c.id
             WHERE c.family_id = ? ORDER BY c.name',
            [(int) Auth::familyId()]
        );
        return View::render('children/index', ['title' => 'Kinder', 'children' => $children]);
    }

    public function create(Request $request): Response
    {
        return View::render('children/form', [
            'title' => 'Kind anlegen', 'child' => null, 'settings' => null,
        ]);
    }

    public function store(Request $request): Response
    {
        $v = Validator::make($request->all(), [
            'name'          => 'required|max:100',
            'reading_level' => 'required|in:1,2,3',
        ]);
        if ($v->fails()) {
            Session::flash('error', $v->firstError());
            return Response::redirect('/kinder/neu');
        }

        $childId = Db::insert('children', [
            'family_id'     => (int) Auth::familyId(),
            'name'          => $request->str('name'),
            'avatar_key'    => $this->avatarKey($request),
            'color_hex'     => $this->colorHex($request),
            'birth_year'    => $request->str('birth_year') !== '' ? (int) $request->str('birth_year') : null,
            'reading_level' => (int) $request->str('reading_level', '1'),
            'created_at'    => now(),
        ]);
        Db::insert('child_settings', array_merge(['child_id' => $childId], $this->settingsFrom($request)));

        Session::flash('success', 'Kind angelegt.');
        return Response::redirect('/kinder');
    }

    public function edit(Request $request, string $id): Response
    {
        $child = $this->find((int) $id);
        if ($child === null) {
            return Response::notFound();
        }
        $settings = Db::first('SELECT * FROM child_settings WHERE child_id = ?', [$child['id']]);
        return View::render('children/form', [
            'title' => $child['name'], 'child' => $child, 'settings' => $settings,
        ]);
    }

    public function update(Request $request, string $id): Response
    {
        $child = $this->find((int) $id);
        if ($child === null) {
            return Response::notFound();
        }
        $v = Validator::make($request->all(), [
            'name'          => 'required|max:100',
            'reading_level' => 'required|in:1,2,3',
        ]);
        if ($v->fails()) {
            Session::flash('error', $v->firstError());
            return Response::redirect('/kinder/' . $child['id']);
        }

        Db::update('children', [
            'name'          => $request->str('name'),
            'avatar_key'    => $this->avatarKey($request),
            'color_hex'     => $this->colorHex($request),
            'birth_year'    => $request->str('birth_year') !== '' ? (int) $request->str('birth_year') : null,
            'reading_level' => (int) $request->str('reading_level', '1'),
            'updated_at'    => now(),
        ], 'id = :id', ['id' => $child['id']]);

        $settings = $this->settingsFrom($request);
        if (Db::first('SELECT child_id FROM child_settings WHERE child_id = ?', [$child['id']]) !== null) {
            Db::update('child_settings', $settings, 'child_id = :id', ['id' => $child['id']]);
        } else {
            Db::insert('child_settings', array_merge(['child_id' => (int) $child['id']], $settings));
        }

        Session::flash('success', 'Gespeichert.');
        return Response::redirect('/kinder');
    }

    public function destroy(Request $request, string $id): Response
    {
        $child = $this->find((int) $id);
        if ($child === null) {
            return Response::notFound();
        }
        $cid = (int) $child['id'];
        Db::delete('assignments', 'child_id = :id', ['id' => $cid]);
        Db::delete('progress', 'child_id = :id', ['id' => $cid]);
        Db::exec('UPDATE usage_events SET child_id = NULL WHERE child_id = ?', [$cid]);
        Db::delete('child_settings', 'child_id = :id', ['id' => $cid]);
        Db::delete('children', 'id = :id', ['id' => $cid]);
        Session::flash('success', 'Kind gelöscht.');
        return Response::redirect('/kinder');
    }

    private function find(int $id): ?array
    {
        return Db::first(
            'SELECT * FROM children WHERE id = ? AND family_id = ?',
            [$id, (int) Auth::familyId()]
        );
    }

    private function avatarKey(Request $request): string
    {
        $key = $request->str('avatar_key', 'fox');
        return array_key_exists($key, self::AVATARS) ? $key : 'fox';
    }

    private function colorHex(Request $request): string
    {
        $hex = $request->str('color_hex', '#F59E0B');
        return preg_match('/^#[0-9A-Fa-f]{6}$/', $hex) ? $hex : '#F59E0B';
    }

    /** @return array<string,mixed> */
    private function settingsFrom(Request $request): array
    {
        $mode = $request->str('highlight_mode', 'WORD');
        $clamp = static fn (float $v, float $min, float $max): float => max($min, min($max, $v));
        return [
            'highlight_mode'      => array_key_exists($mode, self::HIGHLIGHT_MODES) ? $mode : 'WORD',
            'syllable_coloring'   => $request->input('syllable_coloring') !== null,
            'speech_rate'         => $clamp((float) $request->str('speech_rate', '1.0'), 0.7, 1.3),
            'font_scale'          => $clamp((float) $request->str('font_scale', '1.2'), 1.0, 1.8),
            'font_family'         => in_array($request->str('font_family'), ['andika', 'opendyslexic'], true)
                                     ? $request->str('font_family') : 'andika',
            'lead_offset_ms'      => (int) $clamp((float) $request->str('lead_offset_ms', '-60'), -300, 300),
            'scanner_enabled'     => $request->input('scanner_enabled') !== null,
            'daily_limit_minutes' => $request->str('daily_limit_minutes') !== '' ? (int) $request->str('daily_limit_minutes') : null,
            'quiet_hours_start'   => $this->minutes($request->str('quiet_hours_start')),
            'quiet_hours_end'     => $this->minutes($request->str('quiet_hours_end')),
        ];
    }

    /** "HH:MM" → Minuten seit Mitternacht (oder null). */
    private function minutes(string $time): ?int
    {
        if (!preg_match('/^(\d{1,2}):(\d{2})$/', $time, $m)) {
            return null;
        }
        return min(23, (int) $m[1]) * 60 + min(59, (int) $m[2]);
    }
}
