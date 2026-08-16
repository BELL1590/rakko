/**
 * Hono アプリをそのまま fetch して検証するための最小クライアント。
 * Cookie を保持し、CSRFトークンをフォームから拾えるようにする。
 */

import app from '../../src/index';
import type { Bindings } from '../../src/env';

export interface TestEnvOverrides extends Partial<Bindings> {
  DB: D1Database;
}

export function testEnv(overrides: TestEnvOverrides): Bindings {
  return {
    BASE_URL: 'http://localhost:8787',
    DEMO_MODE: 'true',
    ENVIRONMENT: 'development',
    SESSION_SECRET: 'test-session-secret-value',
    ADMIN_USERNAME: 'staff',
    ADMIN_PASSWORD: 'staff-password',
    ...overrides,
  } as Bindings;
}

const executionContext = {
  waitUntil: () => undefined,
  passThroughOnException: () => undefined,
} as unknown as ExecutionContext;

export class TestClient {
  private cookies = new Map<string, string>();

  constructor(private readonly env: Bindings) {}

  private cookieHeader(): string {
    return [...this.cookies.entries()].map(([name, value]) => `${name}=${value}`).join('; ');
  }

  private storeCookies(response: Response): void {
    // getSetCookie は Workers/Node どちらにもあるが、型定義の差を吸収する
    const headers = response.headers as Headers & { getSetCookie?: () => string[] };
    const raw = headers.getSetCookie?.() ?? [];
    for (const cookie of raw) {
      const [pair] = cookie.split(';');
      const separator = pair?.indexOf('=') ?? -1;
      if (!pair || separator < 0) continue;
      const name = pair.slice(0, separator);
      const value = pair.slice(separator + 1);
      if (value === '' || /Max-Age=0/i.test(cookie)) this.cookies.delete(name);
      else this.cookies.set(name, value);
    }
  }

  async get(path: string): Promise<Response> {
    const response = await app.fetch(
      new Request(`http://localhost:8787${path}`, {
        headers: this.cookieHeader() ? { Cookie: this.cookieHeader() } : {},
      }),
      this.env,
      executionContext,
    );
    this.storeCookies(response);
    return response;
  }

  async post(path: string, body: Record<string, string | string[]>): Promise<Response> {
    const form = new URLSearchParams();
    for (const [key, value] of Object.entries(body)) {
      if (Array.isArray(value)) for (const item of value) form.append(key, item);
      else form.append(key, value);
    }
    const headers: Record<string, string> = {
      'Content-Type': 'application/x-www-form-urlencoded',
    };
    if (this.cookieHeader()) headers.Cookie = this.cookieHeader();

    const response = await app.fetch(
      new Request(`http://localhost:8787${path}`, {
        method: 'POST',
        headers,
        body: form.toString(),
      }),
      this.env,
      executionContext,
    );
    this.storeCookies(response);
    return response;
  }

  /** ページのフォームから CSRF トークンを取り出す。 */
  async csrfTokenFrom(path: string): Promise<string> {
    const response = await this.get(path);
    const html = await response.text();
    const match = /name="csrf_token" value="([^"]+)"/.exec(html);
    if (!match?.[1]) throw new Error(`csrf token not found on ${path}`);
    return match[1];
  }
}
