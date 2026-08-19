<?php
declare(strict_types=1);

use App\Core\Blueprint;
use App\Core\Schema;

/**
 * Lesefuchs — Kernschema (Etappe 1).
 * Familien-Mandanten, Eltern-Accounts, Kinderprofile, Geräte-Pairing,
 * Inhaltspakete (.lesepaket) mit Dateiliste, Zuweisungen, Fortschritt,
 * Nutzungs-Events (append-only) und Upload-Sessions für Chunk-Uploads.
 */
return new class {
    public function up(Schema $schema, PDO $pdo): void
    {
        $schema->create('families', function (Blueprint $t) {
            $t->id();
            $t->string('name');
            $t->timestamps();
        });

        $schema->create('users', function (Blueprint $t) {
            $t->id();
            $t->string('email')->unique();
            $t->string('password_hash')->nullable();
            $t->string('name');
            $t->string('role', 20)->default('parent');   // admin | parent
            $t->foreignId('family_id')->nullable();
            $t->string('status', 20)->default('active'); // active | disabled
            $t->datetime('last_login_at')->nullable();
            $t->timestamps();
            $t->references('family_id', 'families', 'id', 'SET NULL');
        });

        $schema->create('login_attempts', function (Blueprint $t) {
            $t->id();
            $t->string('identifier');
            $t->datetime('attempted_at');
            $t->index('identifier', 'attempted_at');
        });

        // Studio-/API-Tokens der Eltern-Accounts (nur SHA-256-Hash gespeichert)
        $schema->create('api_tokens', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id');
            $t->string('name');
            $t->string('token_hash', 64)->unique();
            $t->datetime('created_at');
            $t->datetime('last_used_at')->nullable();
            $t->datetime('revoked_at')->nullable();
            $t->references('user_id', 'users');
        });

        $schema->create('children', function (Blueprint $t) {
            $t->id();
            $t->foreignId('family_id');
            $t->string('name');
            $t->string('avatar_key', 40)->default('fox');
            $t->string('color_hex', 9)->default('#F59E0B');
            $t->integer('birth_year')->nullable();
            $t->integer('reading_level')->default(1);    // 1..3 (Konzept §7.1)
            $t->timestamps();
            $t->references('family_id', 'families');
            $t->index('family_id');
        });

        $schema->create('child_settings', function (Blueprint $t) {
            $t->foreignId('child_id')->unique();
            $t->string('highlight_mode', 20)->default('WORD'); // SENTENCE|WORD|SYLLABLE|KARAOKE
            $t->boolean('syllable_coloring')->default(false);
            $t->decimal('speech_rate', 3, 2)->default(1.0);    // 0.7 .. 1.3
            $t->decimal('font_scale', 3, 2)->default(1.2);     // 1.0 .. 1.8
            $t->string('font_family', 30)->default('andika');  // andika | opendyslexic
            $t->integer('lead_offset_ms')->default(-60);
            $t->boolean('scanner_enabled')->default(true);
            $t->integer('daily_limit_minutes')->nullable();
            $t->integer('quiet_hours_start')->nullable();      // Minuten seit Mitternacht
            $t->integer('quiet_hours_end')->nullable();
            $t->references('child_id', 'children');
        });

        $schema->create('devices', function (Blueprint $t) {
            $t->id();
            $t->foreignId('family_id');
            $t->string('name');
            $t->string('token_hash', 64)->unique();
            $t->datetime('paired_at');
            $t->datetime('last_seen_at')->nullable();
            $t->datetime('revoked_at')->nullable();
            $t->references('family_id', 'families');
        });

        $schema->create('pairing_codes', function (Blueprint $t) {
            $t->id();
            $t->foreignId('family_id');
            $t->string('code', 6)->unique();
            $t->datetime('expires_at');
            $t->datetime('used_at')->nullable();
            $t->datetime('created_at');
            $t->references('family_id', 'families');
        });

        $schema->create('packages', function (Blueprint $t) {
            $t->id();
            $t->string('uuid', 64);                       // id aus manifest.json
            $t->foreignId('family_id');
            $t->string('title');
            $t->string('author')->nullable();
            $t->string('type', 20)->default('REFLOW');    // FACSIMILE | REFLOW
            $t->string('language', 10)->default('de-DE');
            $t->integer('reading_level')->default(1);
            $t->integer('page_count')->default(0);
            $t->bigint('duration_ms')->default(0);
            $t->string('voice', 100)->nullable();
            $t->integer('package_version')->default(1);
            $t->bigint('size_bytes')->default(0);
            $t->string('status', 20)->default('ready');   // ready | archived
            $t->string('checksum', 71)->nullable();       // sha256:… des ZIPs
            $t->timestamps();
            $t->references('family_id', 'families');
            $t->uniqueIndex('family_id', 'uuid');
        });

        $schema->create('package_files', function (Blueprint $t) {
            $t->id();
            $t->foreignId('package_id');
            $t->string('rel_path', 500);
            $t->bigint('size_bytes')->default(0);
            $t->string('sha256', 64)->nullable();
            $t->references('package_id', 'packages');
            $t->index('package_id');
        });

        $schema->create('assignments', function (Blueprint $t) {
            $t->id();
            $t->foreignId('child_id');
            $t->foreignId('package_id');
            $t->datetime('assigned_at');
            $t->datetime('unlock_at')->nullable();
            $t->datetime('expires_at')->nullable();
            $t->integer('order_index')->default(0);
            $t->boolean('is_favorite')->default(false);
            $t->references('child_id', 'children');
            $t->references('package_id', 'packages');
            $t->uniqueIndex('child_id', 'package_id');
        });

        $schema->create('progress', function (Blueprint $t) {
            $t->id();
            $t->foreignId('child_id');
            $t->foreignId('package_id');
            $t->integer('last_page')->default(0);
            $t->bigint('last_position_ms')->default(0);
            $t->integer('last_token_index')->default(0);
            $t->integer('listen_count')->default(0);
            $t->datetime('completed_at')->nullable();
            $t->bigint('total_listened_ms')->default(0);
            $t->datetime('updated_at')->nullable();
            $t->references('child_id', 'children');
            $t->references('package_id', 'packages');
            $t->uniqueIndex('child_id', 'package_id');
        });

        // Append-only Event-Stream (Konzept §7.2) — Dedupe über (device_id, client_event_id)
        $schema->create('usage_events', function (Blueprint $t) {
            $t->id();
            $t->foreignId('device_id');
            $t->bigint('client_event_id');
            $t->foreignId('child_id')->nullable();
            $t->foreignId('package_id')->nullable();
            $t->string('type', 20); // OPEN|PLAY|PAUSE|SEEK|PAGE|WORD_TAP|GLOSSARY|SCAN|QUIZ|FINISH|CLOSE
            $t->bigint('ts_utc');   // Unix-Millisekunden vom Gerät
            $t->integer('page')->nullable();
            $t->bigint('position_ms')->nullable();
            $t->bigint('duration_ms')->nullable();
            $t->datetime('received_at');
            $t->references('device_id', 'devices');
            $t->uniqueIndex('device_id', 'client_event_id');
            $t->index('child_id', 'ts_utc');
        });

        $schema->create('upload_sessions', function (Blueprint $t) {
            $t->id();
            $t->string('token', 64)->unique();
            $t->foreignId('family_id');
            $t->foreignId('user_id');
            $t->string('filename');
            $t->bigint('total_size');
            $t->integer('total_chunks');
            $t->string('sha256', 64);
            $t->string('status', 20)->default('open'); // open | done | failed
            $t->datetime('created_at');
            $t->references('family_id', 'families');
            $t->references('user_id', 'users');
        });
    }

    public function down(Schema $schema, PDO $pdo): void
    {
        foreach ([
            'upload_sessions', 'usage_events', 'progress', 'assignments',
            'package_files', 'packages', 'pairing_codes', 'devices',
            'child_settings', 'children', 'api_tokens', 'login_attempts',
            'users', 'families',
        ] as $table) {
            $schema->dropIfExists($table);
        }
    }
};
