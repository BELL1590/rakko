/** HTML生成の最小ユーティリティ。SSRの出力は必ずここを通してエスケープする。 */

export function esc(value: unknown): string {
  if (value === null || value === undefined) return '';
  return String(value)
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;')
    .replace(/'/g, '&#39;');
}

/** 条件付き出力。false のときは空文字。 */
export function when(condition: unknown, html: string): string {
  return condition ? html : '';
}

/** 配列を連結する。要素は既にエスケープ済みのHTMLであること。 */
export function join(parts: string[]): string {
  return parts.join('');
}
