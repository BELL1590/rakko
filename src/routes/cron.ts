/**
 * Cron Trigger のハンドラ。
 * Cloudflare の scheduled イベントから5分おきに呼ばれる。
 */

import type { Bindings } from '../env';
import { assertDemoModeSafety } from '../env';
import { processDueReminders, type ReminderRunSummary } from '../services/reminder-service';
import { nowUtc } from '../lib/time';

export async function handleScheduled(
  event: ScheduledController,
  env: Bindings,
): Promise<ReminderRunSummary> {
  assertDemoModeSafety(env);
  const now = nowUtc(new Date(event.scheduledTime));
  const summary = await processDueReminders(env.DB, env, {}, now);

  // 個人情報は出さず、件数のみ記録する
  console.log(
    `[cron] reminders checked=${summary.checked} requested=${summary.requested} ` +
      `failed=${summary.failed} skipped=${summary.skipped} already=${summary.already}`,
  );
  return summary;
}
