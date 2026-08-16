/**
 * テスト用のD1互換アダプタ。
 *
 * Workers ランタイムを立ち上げずに、実際のSQL（CHECK制約・部分UNIQUEインデックス・
 * 定員トリガー）をそのまま検証するため、node:sqlite 上に D1Database のサブセットを実装する。
 * マイグレーションSQLは本番と同じファイルを読み込む。
 */

import { readFileSync, readdirSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { dirname, join } from 'node:path';
import { createRequire } from 'node:module';

// node:sqlite は Vite の builtin 一覧に載っていないため、実行時に require で読み込む。
const require = createRequire(import.meta.url);
const { DatabaseSync } = require('node:sqlite') as typeof import('node:sqlite');
type DatabaseSync = InstanceType<typeof DatabaseSync>;

const here = dirname(fileURLToPath(import.meta.url));
const migrationsDir = join(here, '..', '..', 'migrations');

type SqlValue = string | number | null;

class TestStatement {
  constructor(
    private readonly db: DatabaseSync,
    private readonly sql: string,
    private readonly params: SqlValue[] = [],
  ) {}

  bind(...values: unknown[]): TestStatement {
    return new TestStatement(this.db, this.sql, values as SqlValue[]);
  }

  private normalized(): SqlValue[] {
    return this.params.map((value) => {
      if (value === undefined || value === null) return null;
      if (typeof value === 'boolean') return value ? 1 : 0;
      return value;
    });
  }

  async first<T = Record<string, unknown>>(column?: string): Promise<T | null> {
    const statement = this.db.prepare(this.sql);
    const row = statement.get(...(this.normalized() as never[])) as
      | Record<string, unknown>
      | undefined;
    if (!row) return null;
    if (column) return (row[column] ?? null) as T;
    return { ...row } as T;
  }

  async all<T = Record<string, unknown>>(): Promise<{ results: T[]; success: true }> {
    const statement = this.db.prepare(this.sql);
    const rows = statement.all(...(this.normalized() as never[])) as Record<string, unknown>[];
    return { results: rows.map((row) => ({ ...row })) as T[], success: true };
  }

  async run(): Promise<{ success: true; meta: { changes: number; last_row_id: number } }> {
    const statement = this.db.prepare(this.sql);
    const result = statement.run(...(this.normalized() as never[]));
    return {
      success: true,
      meta: {
        changes: Number(result.changes),
        last_row_id: Number(result.lastInsertRowid),
      },
    };
  }
}

export interface TestDatabase {
  d1: D1Database;
  raw: DatabaseSync;
  close(): void;
}

/** マイグレーションを適用済みのインメモリDBを作る。 */
export function createTestDb(options: { seed?: boolean } = {}): TestDatabase {
  const db = new DatabaseSync(':memory:');
  db.exec('PRAGMA foreign_keys = ON;');

  const files = readdirSync(migrationsDir)
    .filter((name) => name.endsWith('.sql'))
    .sort();

  for (const file of files) {
    // 0002 は seed。seed 不要なテストでは適用しない。
    if (options.seed === false && file.includes('seed')) continue;
    db.exec(readFileSync(join(migrationsDir, file), 'utf8'));
  }

  const d1 = {
    prepare: (sql: string) => new TestStatement(db, sql),
  } as unknown as D1Database;

  return { d1, raw: db, close: () => db.close() };
}

/** テスト用のユーザーを作る。 */
export async function createTestUser(
  db: D1Database,
  lineUserId: string,
  displayName = 'テストユーザー',
  isFriend: boolean | null = true,
): Promise<number> {
  const now = '2026-08-01T00:00:00Z';
  await db
    .prepare(
      `INSERT INTO users (line_user_id, line_display_name, is_line_friend, created_at, updated_at)
       VALUES (?1, ?2, ?3, ?4, ?4)`,
    )
    .bind(lineUserId, displayName, isFriend === null ? null : isFriend ? 1 : 0, now)
    .run();
  const row = await db
    .prepare(`SELECT id FROM users WHERE line_user_id = ?1`)
    .bind(lineUserId)
    .first<{ id: number }>();
  return row!.id;
}

/** 便のIDを slug から引く。 */
export async function tripIdBySlug(db: D1Database, slug: string): Promise<number> {
  const row = await db
    .prepare(`SELECT id FROM trips WHERE slug = ?1`)
    .bind(slug)
    .first<{ id: number }>();
  if (!row) throw new Error(`trip not found: ${slug}`);
  return row.id;
}

export const OUTBOUND_SLUG = 'ikebukuro-20260821-outbound';
export const RETURN_SLUG = 'ikebukuro-20260822-return';

/** イベント前の基準時刻（UTC）。 */
export const NOW = '2026-08-01T00:00:00Z';
